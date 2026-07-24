<?php

declare(strict_types=1);

use Symfony\Component\Intl\Countries;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ConnectReseller domain registrar adapter for FOSSBilling.
 *
 * Implements the ConnectReseller API V11 (GET-only, JSON responses).
 * API documentation: https://www.connectreseller.com/resources/downloads/CR_API_Document_V11.pdf
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 */
class Registrar_Adapter_Connectreseller extends Registrar_AdapterAbstract
{
    public $config = [
        'api-key' => null,
        'user_id' => null,
        'debug_mode' => false,
    ];

    private const API_URL = 'https://api.connectreseller.com/ConnectReseller/ESHOP/';

    /** Per-request cache of ViewDomain responses, keyed by lowercase domain name. */
    private array $_viewDomainCache = [];

    /** Injectable HTTP client for unit testing; production uses getHttpClient(). */
    private ?HttpClientInterface $_httpClient = null;

    public function setHttpClient(HttpClientInterface $client): static
    {
        $this->_httpClient = $client;

        return $this;
    }

    public function __construct($options)
    {
        if (!empty($options['api-key'])) {
            $this->config['api-key'] = $options['api-key'];
        } else {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'ConnectReseller', ':missing' => 'API Key'], 3001);
        }

        if (!empty($options['user_id'])) {
            $this->config['user_id'] = (int) $options['user_id'];
        } else {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'ConnectReseller', ':missing' => 'Brand ID (User ID)'], 3001);
        }

        $this->config['debug_mode'] = !empty($options['debug_mode']);
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Manages domains on ConnectReseller via API V11. Log in to your ConnectReseller control panel, navigate to Settings > API to retrieve your API key. Note: ConnectReseller does not provide a sandbox endpoint — all API calls hit the production system, and write operations are blocked while FOSSBilling test mode is enabled.',
            'form' => [
                'api-key' => [
                    'password',
                    [
                        'label' => 'API Key',
                        'description' => 'Your ConnectReseller API key. Found under Settings > API in the control panel.',
                        'required' => true,
                    ],
                ],
                'user_id' => [
                    'text',
                    [
                        'label' => 'Brand ID (User ID)',
                        'description' => 'Your ConnectReseller reseller Brand ID. Found in your ConnectReseller control panel account profile.',
                        'required' => true,
                    ],
                ],
                'debug_mode' => [
                    'text',
                    [
                        'label' => 'Debug Mode',
                        'description' => 'Set to 1 to log full API request/response bodies. Leave empty or 0 in production.',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Domain availability
    // -------------------------------------------------------------------------

    /**
     * @throws Registrar_Exception
     */
    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $result = $this->_makeRequest('checkdomainavailable', [
            'websiteName' => $domain->getName(),
        ], [200, 400]);

        // 200 = available, 400 = not available
        if ((int) ($result['responseMsg']['statusCode'] ?? 0) !== 200) {
            return false;
        }

        $data = is_array($result['responseData'] ?? null) ? $result['responseData'] : [];

        // Premium domains carry registry-set pricing that does not match FOSSBilling's
        // static TLD prices — treat them as unavailable so they cannot be mis-sold.
        $domainType = strtolower((string) ($data['domainType'] ?? ''));
        if (str_contains($domainType, 'premium')) {
            $this->getLog()->info('ConnectReseller: rejecting premium domain ' . $domain->getName());

            return false;
        }

        // Honor the documented `available` flag when present; fall back to the 200 status.
        if (array_key_exists('available', $data)) {
            return filter_var($data['available'], FILTER_VALIDATE_BOOLEAN);
        }

        return true;
    }

    /**
     * @throws Registrar_Exception
     */
    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        // Step 1: Domain must already be registered elsewhere to be transferable.
        // isDomainAvailable returns true when domain is available (not registered),
        // so we invert: if it IS available it cannot be transferred.
        if ($this->isDomainAvailable($domain)) {
            return false;
        }

        // Step 2: Check whether a transfer order is already in-progress at
        // ConnectReseller. A 200 response from syncTransfer means an order
        // exists — the domain cannot be transferred again until that resolves.
        try {
            $this->_makeRequest('syncTransfer', ['domainName' => $domain->getName()]);

            // 200 = existing transfer order found — not eligible for a new transfer
            return false;
        } catch (Registrar_Exception $e) {
            // Re-throw authentication / configuration failures so the caller is
            // not misled into thinking the domain is transferable.
            $msg = $e->getMessage();
            if (stripos($msg, 'Unauthenticated') !== false
                || stripos($msg, 'Unauthorized') !== false
                || stripos($msg, 'Invalid API') !== false
            ) {
                throw $e;
            }

            // Any other error means no existing transfer order — domain is eligible.
            return true;
        }
    }

    // -------------------------------------------------------------------------
    // Registration / Renewal / Transfer
    // -------------------------------------------------------------------------

    /**
     * @throws Registrar_Exception
     */
    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->_assertWriteAllowed('register domain');

        // .us requires Nexus purpose/category fields that FOSSBilling does not collect.
        if (strtolower((string) $domain->getTld()) === '.us') {
            throw new Registrar_Exception('.us domains require Nexus registrant information which is not collected during checkout. Please contact support to register a .us domain.');
        }

        // ConnectReseller requires at least two nameservers on domainorder.
        if (empty($domain->getNs1()) || empty($domain->getNs2())) {
            throw new Registrar_Exception('At least two nameservers are required to register :domain.', [':domain' => $domain->getName()]);
        }

        // Idempotency: if the domain already exists under this reseller (e.g. a retried
        // activation after a partial failure), reconcile its state instead of ordering again.
        try {
            $this->_viewDomain($domain->getName());
            $this->getLog()->warning('ConnectReseller: ' . $domain->getName() . ' already exists under this account — reconciling instead of re-registering');

            return $this->_reconcileExistingDomain($domain);
        } catch (Registrar_Exception) {
            // Not registered here yet — proceed with a fresh order.
        }

        $customerId = $this->_getOrCreateCustomerId($domain);

        $params = [
            'ProductType' => 1,
            'Websitename' => $domain->getName(),
            'Duration' => max(1, (int) ($domain->getRegistrationPeriod() ?? 1)),
            'IsWhoisProtection' => $domain->getPrivacyEnabled() ? 'true' : 'false',
            'ns1' => $domain->getNs1(),
            'ns2' => $domain->getNs2(),
            'isEnablePremium' => 0,
            'Id' => $customerId,
        ];

        if ($domain->getNs3()) {
            $params['ns3'] = $domain->getNs3();
        }

        if ($domain->getNs4()) {
            $params['ns4'] = $domain->getNs4();
        }

        $this->_makeRequest('domainorder', $params);

        // Best effort: assign a dedicated registrant contact to all four roles so later
        // contact edits never mutate contacts shared with unrelated domains.
        $this->_assignDedicatedContact($domain, $customerId);

        return true;
    }

    /**
     * Bring an already-registered domain in line with the requested state
     * (nameservers + privacy) instead of double-ordering it.
     */
    private function _reconcileExistingDomain(Registrar_Domain $domain): bool
    {
        try {
            $this->modifyNs($domain);
        } catch (Registrar_Exception $e) {
            $this->getLog()->warning('ConnectReseller reconcile: nameserver update failed for ' . $domain->getName() . ': ' . $e->getMessage());
        }

        try {
            if ($domain->getPrivacyEnabled()) {
                $this->enablePrivacyProtection($domain);
            } else {
                $this->disablePrivacyProtection($domain);
            }
        } catch (Registrar_Exception $e) {
            $this->getLog()->warning('ConnectReseller reconcile: privacy update failed for ' . $domain->getName() . ': ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Create a dedicated registrant contact and assign it to the registrant, admin,
     * technical and billing roles via updatecontact.
     *
     * The V11 documentation does not define a contact ID in the AddRegistrantContact
     * response, so this is best effort: when no ID can be resolved the domain simply
     * keeps the customer-profile contacts created by domainorder.
     */
    private function _assignDedicatedContact(Registrar_Domain $domain, int $customerId): void
    {
        $contact = $domain->getContactRegistrar();
        if (!$contact) {
            return;
        }

        try {
            $result = $this->_makeRequest('AddRegistrantContact', array_merge(
                $this->_contactToParams($contact),
                ['Id' => $customerId]
            ));

            $data = is_array($result['responseData'] ?? null) ? $result['responseData'] : [];
            $contactId = $data['registrantContactId']
                ?? $data['contactId']
                ?? $data['RegistrantContactId']
                ?? (is_numeric($result['responseData'] ?? null) ? $result['responseData'] : null);

            if (empty($contactId)) {
                $this->getLog()->info('ConnectReseller: AddRegistrantContact returned no contact ID for ' . $domain->getName() . ' — keeping order-created contacts');

                return;
            }

            [$domainNameId] = $this->_viewDomain($domain->getName());

            $this->_makeRequest('updatecontact', [
                'domainNameId' => $domainNameId,
                'websiteName' => $domain->getName(),
                'registrantContactId' => (int) $contactId,
                'adminContactId' => (int) $contactId,
                'technicalContactId' => (int) $contactId,
                'billingContactId' => (int) $contactId,
            ]);
        } catch (Registrar_Exception $e) {
            // Never fail a successful registration over contact assignment.
            $this->getLog()->warning('ConnectReseller: dedicated contact assignment failed for ' . $domain->getName() . ': ' . $e->getMessage());
        }
    }

    /**
     * @throws Registrar_Exception
     */
    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->_assertWriteAllowed('renew domain');

        [, $data] = $this->_viewDomain($domain->getName());

        $customerId = $data['customerId'] ?? $data['clientId'] ?? null;
        if (empty($customerId)) {
            $customerId = $this->_getCustomerIdForDomain($domain->getName());
        }

        // IsWhoisProtection encoding must match the CR API exactly:
        //   privacy ON  → integer 1      (not string 'true' — CR returns 500 for that)
        //   privacy OFF → string 'false'
        $livePrivacy = (bool) ($data['isPrivacyProtection'] ?? false);

        $params = [
            'Websitename' => $domain->getName(),
            'OrderType' => 2,
            'Duration' => max(1, (int) ($domain->getRegistrationPeriod() ?? 1)),
            'Id' => (int) $customerId,
            'IsWhoisProtection' => $livePrivacy ? 1 : 'false',
            'isEnablePremium' => 0,
        ];

        // Optional per API V11: the domain's current expiry year disambiguates the renewal.
        if (!empty($data['expirationDate'])) {
            $ts = (int) $data['expirationDate'];
            $params['Expiryyear'] = date('Y', $ts > 1_000_000_000_000 ? (int) ($ts / 1000) : $ts);
        }

        $this->_makeRequest('RenewalOrder', $params);

        return true;
    }

    /**
     * @throws Registrar_Exception
     */
    public function transferDomain(Registrar_Domain $domain): bool
    {
        // EPP/auth code is required for all gTLD transfers. Reject early with a
        // user-readable message rather than letting the CR API return a cryptic error.
        $this->_assertWriteAllowed('transfer domain');

        $epp = $domain->getEpp();
        if (empty($epp)) {
            throw new Registrar_Exception('An EPP/authorization code is required to transfer :domain. Please obtain the auth code from the current registrar and try again.', [':domain' => $domain->getName()]);
        }
        if (strlen($epp) > 256 || !preg_match('/^[\x20-\x7E]+$/', $epp)) {
            throw new Registrar_Exception('The EPP/authorization code contains invalid characters or is too long.');
        }

        $customerId = $this->_getOrCreateCustomerId($domain);

        // Note: TransferOrder does NOT accept a Duration parameter — it is only
        // used by domainorder and renewalorder. Sending it has no effect and
        // could cause unexpected API behaviour on some CR API versions.
        $this->_makeRequest('TransferOrder', [
            'OrderType' => 4,
            'Websitename' => $domain->getName(),
            'AuthCode' => $epp,
            'IsWhoisProtection' => $domain->getPrivacyEnabled() ? 'true' : 'false',
            'Id' => $customerId,
        ]);

        return true;
    }

    /**
     * Cancel a pending domain transfer-in.
     *
     * @throws Registrar_Exception
     */
    public function cancelTransfer(Registrar_Domain $domain): bool
    {
        $this->_assertWriteAllowed('cancel transfer');

        $domainNameId = $this->_getDomainNameId($domain->getName());

        $result = $this->_makeRequest('CancelTransfer', ['id' => $domainNameId]);

        return (int) ($result['responseMsg']['statusCode'] ?? 0) === 200;
    }

    /**
     * Get the status of a pending domain transfer-in.
     *
     * @throws Registrar_Exception
     */
    public function getTransferStatus(Registrar_Domain $domain): array
    {
        $result = $this->_makeRequest('syncTransfer', ['domainName' => $domain->getName()]);

        return $result['responseData'] ?? [];
    }

    /**
     * @throws Registrar_Exception
     */
    public function deleteDomain(Registrar_Domain $domain): never
    {
        throw new Registrar_Exception(':type: does not support :action:', [':type:' => 'ConnectReseller', ':action:' => __trans('deleting domains')]);
    }

    // -------------------------------------------------------------------------
    // Domain details
    // -------------------------------------------------------------------------

    /**
     * @throws Registrar_Exception
     */
    public function getDomainDetails(Registrar_Domain $d): Registrar_Domain
    {
        [, $data] = $this->_viewDomain($d->getName());

        if (!empty($data['creationDate'])) {
            $ts = (int) $data['creationDate'];
            $d->setRegistrationTime($ts > 1_000_000_000_000 ? (int) ($ts / 1000) : $ts);
        }

        if (!empty($data['expirationDate'])) {
            $ts = (int) $data['expirationDate'];
            $d->setExpirationTime($ts > 1_000_000_000_000 ? (int) ($ts / 1000) : $ts);
        }

        $authCode = $data['authCode'] ?? $data['Authcode'] ?? null;
        if (!empty($authCode)) {
            $d->setEpp((string) $authCode);
        }

        $d->setPrivacyEnabled((bool) ($data['isPrivacyProtection'] ?? false));
        $d->setLocked((bool) ($data['isDomainLocked'] ?? false));

        // Name servers — API supports up to nameserver13
        for ($i = 1; $i <= 13; ++$i) {
            $ns = $data['nameserver' . $i] ?? null;
            if (empty($ns)) {
                break;
            }
            if ($i <= 4) {
                $d->{'setNs' . $i}($ns);
            }
        }

        // Fetch registrant contact via ViewRegistrant
        $contactId = $data['registrantContactId'] ?? null;
        if ($contactId) {
            try {
                $cr = $this->_makeRequest('ViewRegistrant', ['RegistrantContactId' => $contactId]);
                $cd = $cr['responseData'] ?? [];

                if (!empty($cd)) {
                    $contact = new Registrar_Domain_Contact();

                    // CR returns the full name as a single "Name" field
                    if (!empty($cd['Name'])) {
                        $parts = explode(' ', trim((string) $cd['Name']), 2);
                        $contact->setFirstName($parts[0]);
                        $contact->setLastName($parts[1] ?? '');
                    }

                    if (!empty($cd['emailaddress'])) {
                        $contact->setEmail($cd['emailaddress']);
                    }
                    if (!empty($cd['companyName'])) {
                        $contact->setCompany($cd['companyName']);
                    }
                    if (!empty($cd['address'])) {
                        $contact->setAddress1($cd['address']);
                    }
                    if (!empty($cd['city'])) {
                        $contact->setCity($cd['city']);
                    }
                    if (!empty($cd['stateName'])) {
                        $contact->setState($cd['stateName']);
                    }
                    if (!empty($cd['countryName'])) {
                        $contact->setCountry($cd['countryName']);
                    }
                    if (!empty($cd['zip'])) {
                        $contact->setZip($cd['zip']);
                    }
                    if (!empty($cd['phoneNo_cc'])) {
                        $contact->setTelCc($cd['phoneNo_cc']);
                    }
                    if (!empty($cd['phoneNo'])) {
                        $contact->setTel($cd['phoneNo']);
                    }

                    $d->setContactRegistrar($contact);
                }
            } catch (Registrar_Exception $e) {
                $this->getLog()->warning('ConnectReseller: ViewRegistrant failed for ' . $d->getName() . ': ' . $e->getMessage());
            }
        }

        return $d;
    }

    /**
     * @throws Registrar_Exception
     */
    public function getEpp(Registrar_Domain $domain): string
    {
        [, $data] = $this->_viewDomain($domain->getName());

        $authCode = $data['authCode'] ?? $data['Authcode'] ?? null;

        if (empty($authCode)) {
            $domainNameId = $data['domainNameId'] ?? $this->_getDomainNameId($domain->getName());
            $result = $this->_makeRequest('ViewEPPCode', ['domainNameId' => (int) $domainNameId]);
            $authCode = $result['responseData']['DomainSecretKey'] ?? null;
            if (!is_string($authCode) && is_string($result['responseData'] ?? null)) {
                $authCode = $result['responseData'];
            }
        }

        if (empty($authCode)) {
            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', [':action:' => __trans('retrieve the EPP code'), ':type:' => 'ConnectReseller']);
        }

        return (string) $authCode;
    }

    // -------------------------------------------------------------------------
    // Nameservers & Contacts
    // -------------------------------------------------------------------------

    /**
     * @throws Registrar_Exception
     */
    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->_assertWriteAllowed('modify nameservers');

        $domainNameId = $this->_getDomainNameId($domain->getName());

        $params = [
            'domainNameId' => $domainNameId,
            'websiteName' => $domain->getName(),
            'nameServer1' => $domain->getNs1(),
            'nameServer2' => $domain->getNs2(),
        ];

        if ($domain->getNs3()) {
            $params['nameServer3'] = $domain->getNs3();
        }

        if ($domain->getNs4()) {
            $params['nameServer4'] = $domain->getNs4();
        }

        $this->_makeRequest('UpdateNameServer', $params);

        return true;
    }

    /**
     * @throws Registrar_Exception
     */
    public function modifyContact(Registrar_Domain $domain): bool
    {
        $this->_assertWriteAllowed('modify contact');

        $c = $domain->getContactRegistrar()
            ?? $domain->getContactAdmin()
            ?? $domain->getContactTech()
            ?? $domain->getContactBilling();

        if ($c === null) {
            $placeholders = [':action:' => __trans('update contact — no contact data provided'), ':type:' => 'ConnectReseller'];

            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
        }

        [$domainNameId, $data] = $this->_viewDomain($domain->getName());

        // Preferred flow (per implementation plan): create a NEW contact and assign it to
        // all four roles via updatecontact. Editing the existing contact in place would
        // silently change contact data on any other domain sharing that contact record.
        $customerId = $data['customerId'] ?? $data['clientId'] ?? null;
        if (!empty($customerId)) {
            try {
                $result = $this->_makeRequest('AddRegistrantContact', array_merge(
                    $this->_contactToParams($c),
                    ['Id' => (int) $customerId]
                ));

                $rd = is_array($result['responseData'] ?? null) ? $result['responseData'] : [];
                $newContactId = $rd['registrantContactId']
                    ?? $rd['contactId']
                    ?? $rd['RegistrantContactId']
                    ?? (is_numeric($result['responseData'] ?? null) ? $result['responseData'] : null);

                if (!empty($newContactId)) {
                    $this->_makeRequest('updatecontact', [
                        'domainNameId' => $domainNameId,
                        'websiteName' => $domain->getName(),
                        'registrantContactId' => (int) $newContactId,
                        'adminContactId' => (int) $newContactId,
                        'technicalContactId' => (int) $newContactId,
                        'billingContactId' => (int) $newContactId,
                    ]);

                    return true;
                }
            } catch (Registrar_Exception $e) {
                $this->getLog()->warning('ConnectReseller: create-and-assign contact failed for ' . $domain->getName() . ', falling back to in-place modification: ' . $e->getMessage());
            }
        }

        // Fallback: modify the current registrant contact in place.
        $contactId = $data['registrantContactId'] ?? null;
        if (!$contactId) {
            $placeholders = [':action:' => __trans('resolve registrant contact ID'), ':type:' => 'ConnectReseller'];

            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
        }

        $this->_makeRequest('ModifyRegistrantContact', array_merge(
            $this->_contactToParams($c),
            ['RegistrantContactId' => $contactId]
        ));

        return true;
    }

    // -------------------------------------------------------------------------
    // Lock / Unlock
    // -------------------------------------------------------------------------

    /**
     * @throws Registrar_Exception
     */
    public function lock(Registrar_Domain $domain): bool
    {
        $this->_assertWriteAllowed('lock domain');

        [$domainNameId, $data] = $this->_viewDomain($domain->getName());

        if ((bool) ($data['isDomainLocked'] ?? false)) {
            return true; // Already locked
        }

        $this->_makeRequest('ManageDomainLock', [
            'domainNameId' => $domainNameId,
            'websiteName' => $domain->getName(),
            'isDomainLocked' => 'true',
        ]);

        return true;
    }

    /**
     * @throws Registrar_Exception
     */
    public function unlock(Registrar_Domain $domain): bool
    {
        $this->_assertWriteAllowed('unlock domain');

        [$domainNameId, $data] = $this->_viewDomain($domain->getName());

        if (!(bool) ($data['isDomainLocked'] ?? false)) {
            return true; // Already unlocked
        }

        $result = $this->_makeRequest('ManageDomainLock', [
            'domainNameId' => $domainNameId,
            'websiteName' => $domain->getName(),
            'isDomainLocked' => 'false',
        ], [200, 400]);

        if ((int) ($result['responseMsg']['statusCode'] ?? 0) !== 200) {
            $placeholders = [':action:' => __trans('unlock domain'), ':type:' => 'ConnectReseller'];

            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Privacy protection
    // -------------------------------------------------------------------------

    /**
     * Enable WHOIS privacy.
     * Note: parameter name is all-lowercase 'iswhoisprotected' per CR API V11.
     *
     * @throws Registrar_Exception
     */
    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->_assertWriteAllowed('enable privacy protection');

        [$domainNameId, $data] = $this->_viewDomain($domain->getName());

        if ((bool) ($data['isPrivacyProtection'] ?? false)) {
            return true; // Already enabled
        }

        $this->_makeRequest('ManageDomainPrivacyProtection', [
            'domainNameId' => $domainNameId,
            'websiteName' => $domain->getName(),
            'iswhoisprotected' => 'true',
        ]);

        return true;
    }

    /**
     * Disable WHOIS privacy.
     * Note: parameter name is all-lowercase 'iswhoisprotected' per CR API V11.
     *
     * @throws Registrar_Exception
     */
    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->_assertWriteAllowed('disable privacy protection');

        [$domainNameId, $data] = $this->_viewDomain($domain->getName());

        if (!(bool) ($data['isPrivacyProtection'] ?? false)) {
            return true; // Already disabled
        }

        $this->_makeRequest('ManageDomainPrivacyProtection', [
            'domainNameId' => $domainNameId,
            'websiteName' => $domain->getName(),
            'iswhoisprotected' => 'false',
        ]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Theft protection
    // -------------------------------------------------------------------------

    /**
     * @throws Registrar_Exception
     */
    public function enableThiefProtection(Registrar_Domain $domain): bool
    {
        $this->_assertWriteAllowed('enable theft protection');

        [$domainNameId, $data] = $this->_viewDomain($domain->getName());

        if ((bool) ($data['isThiefProtected'] ?? false)) {
            return true; // Already enabled
        }

        $this->_makeRequest('ManageTheftProtection', [
            'domainNameId' => $domainNameId,
            'websiteName' => $domain->getName(),
            'isTheftProtection' => 'true',
        ]);

        return true;
    }

    /**
     * @throws Registrar_Exception
     */
    public function disableThiefProtection(Registrar_Domain $domain): bool
    {
        $this->_assertWriteAllowed('disable theft protection');

        [$domainNameId, $data] = $this->_viewDomain($domain->getName());

        if (!(bool) ($data['isThiefProtected'] ?? false)) {
            return true; // Already disabled
        }

        $this->_makeRequest('ManageTheftProtection', [
            'domainNameId' => $domainNameId,
            'websiteName' => $domain->getName(),
            'isTheftProtection' => 'false',
        ]);

        return true;
    }

    // -------------------------------------------------------------------------
    // DNS Zone Management
    //
    // CR API V11 DNS flow:
    //   1. Resolve domainNameId from ViewDomain.
    //   2. GET ViewDNSRecord?WebsiteId=<domainNameId> — each record carries DNSZoneID + DNSZoneRecordID.
    //   3. GET AddDNSRecord?DNSZoneID=<zoneId>&RecordName=...&RecordType=...&RecordValue=...
    //   4. GET DeleteDNSRecord?DNSZoneID=<zoneId>&DNSZoneRecordID=<recId>
    // -------------------------------------------------------------------------

    /**
     * Retrieve all DNS records for a domain.
     *
     * @return array[]
     *
     * @throws Registrar_Exception
     */
    public function getDnsRecords(Registrar_Domain $domain): array
    {
        $domainNameId = $this->_getDomainNameId($domain->getName());
        $result = $this->_makeRequest('ViewDNSRecord', ['WebsiteId' => $domainNameId]);
        $records = $result['responseData'] ?? [];

        if (!is_array($records)) {
            return [];
        }

        $normalized = [];
        foreach ($records as $r) {
            $normalized[] = [
                'id' => $r['DNSZoneRecordID'] ?? $r['dnsZoneRecordId'] ?? null,
                'zoneId' => $r['DNSZoneID'] ?? $r['dnsZoneId'] ?? null,
                'type' => strtoupper($r['RecordType'] ?? $r['recordType'] ?? ''),
                'host' => $r['RecordName'] ?? $r['recordName'] ?? '',
                'value' => $r['RecordValue'] ?? $r['recordValue'] ?? '',
                'ttl' => (int) ($r['RecordTTL'] ?? $r['recordTTL'] ?? 43200),
                'priority' => (int) ($r['RecordPriority'] ?? $r['recordPriority'] ?? 0),
            ];
        }

        return $normalized;
    }

    /**
     * Add a new DNS record.
     *
     * @throws Registrar_Exception
     */
    public function addDnsRecord(Registrar_Domain $domain, string $type, string $host, string $value, int $ttl = 43200, int $priority = 0): bool
    {
        $this->_assertWriteAllowed('add DNS record');

        $domainNameId = $this->_getDomainNameId($domain->getName());
        $zoneId = $this->_getDnsZoneId($domainNameId);

        if ($zoneId === null) {
            $placeholders = [':action:' => __trans('add DNS record — no DNS zone found'), ':type:' => 'ConnectReseller'];

            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
        }

        $this->_makeRequest('AddDNSRecord', [
            'DNSZoneID' => $zoneId,
            'RecordName' => $host,
            'RecordType' => strtoupper($type),
            'RecordValue' => $value,
            'RecordPriority' => max(0, $priority),
            'RecordTTL' => max(60, min(86400, $ttl)),
        ]);

        return true;
    }

    /**
     * Delete a DNS record by its DNSZoneRecordID.
     *
     * @throws Registrar_Exception
     */
    public function deleteDnsRecord(Registrar_Domain $domain, string $recordId): bool
    {
        $this->_assertWriteAllowed('delete DNS record');

        $domainNameId = $this->_getDomainNameId($domain->getName());
        $zoneId = $this->_getDnsZoneId($domainNameId);

        if ($zoneId === null) {
            $placeholders = [':action:' => __trans('delete DNS record — no DNS zone found'), ':type:' => 'ConnectReseller'];

            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
        }

        $this->_makeRequest('DeleteDNSRecord', [
            'DNSZoneID' => $zoneId,
            'DNSZoneRecordID' => $recordId,
        ]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * ConnectReseller has no sandbox endpoint — every API call hits production.
     * Block all write operations while FOSSBilling test mode is enabled so test
     * activations can never create real orders or mutate live domains.
     *
     * @throws Registrar_Exception
     */
    private function _assertWriteAllowed(string $action): void
    {
        if ($this->_testMode) {
            throw new Registrar_Exception('ConnectReseller does not provide a sandbox API. Refusing to :action while test mode is enabled.', [':action' => $action]);
        }
    }

    /**
     * Map a FOSSBilling contact to ConnectReseller contact parameters
     * (shared by AddRegistrantContact and ModifyRegistrantContact).
     */
    private function _contactToParams(Registrar_Domain_Contact $c): array
    {
        $name = trim($c->getFirstName() . ' ' . $c->getLastName()) ?: ($c->getName() ?: 'Domain Contact');

        return [
            'Name' => $name,
            'EmailAddress' => $c->getEmail(),
            'CompanyName' => $c->getCompany() ?: 'N/A',
            'Address' => $c->getAddress1(),
            'City' => $c->getCity(),
            'StateName' => $c->getState() ?: 'N/A',
            'CountryName' => $this->_countryName($c->getCountry()),
            'Zip' => $c->getZip(),
            'PhoneNo_cc' => $c->getTelCc(),
            'PhoneNo' => $c->getTel(),
        ];
    }

    /**
     * ConnectReseller expects full country names ("Nepal"), while FOSSBilling
     * stores ISO 3166-1 alpha-2 codes ("NP"). Convert when a code is detected.
     */
    private function _countryName(?string $country): string
    {
        $country = trim((string) $country);

        if (strlen($country) === 2 && Countries::exists(strtoupper($country))) {
            return Countries::getName(strtoupper($country), 'en');
        }

        return $country;
    }

    /**
     * Get or create a ConnectReseller customer ID for the domain registrant.
     *
     * 1. Look up the client by email via ViewClient.
     * 2. If not found, create via AddClient, then re-fetch.
     *
     * @throws Registrar_Exception
     */
    private function _getOrCreateCustomerId(Registrar_Domain $domain): int
    {
        $contact = $domain->getContactRegistrar();

        if (!$contact || empty($contact->getEmail())) {
            $placeholders = [':action:' => __trans('resolve customer — registrant contact or email missing'), ':type:' => 'ConnectReseller'];

            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
        }

        $email = $contact->getEmail();

        try {
            $result = $this->_makeRequest('ViewClient', ['UserName' => $email]);
            $clientId = $result['responseData']['clientId'] ?? null;
            if (!empty($clientId)) {
                return (int) $clientId;
            }
        } catch (Registrar_Exception) {
            // Client not found — fall through to create
        }

        $this->_createClient($contact);

        $result = $this->_makeRequest('ViewClient', ['UserName' => $email]);
        $clientId = $result['responseData']['clientId'] ?? null;

        if (empty($clientId)) {
            $placeholders = [':action:' => __trans('resolve client ID after creation'), ':type:' => 'ConnectReseller'];

            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
        }

        return (int) $clientId;
    }

    /**
     * Create a new ConnectReseller client from a registrant contact.
     *
     * API: AddClient?...&Id=<reseller_brand_id>
     * The Id parameter is the reseller's own Brand/User ID (from config user_id),
     * NOT a customer ID. Required by CR API V11 to associate the new client
     * under the correct reseller account.
     *
     * @throws Registrar_Exception
     */
    private function _createClient(Registrar_Domain_Contact $contact): void
    {
        $name = trim($contact->getFirstName() . ' ' . $contact->getLastName()) ?: 'Domain Registrant';

        $this->_makeRequest('AddClient', [
            'FirstName' => $name,
            'UserName' => $contact->getEmail(),
            'Password' => bin2hex(random_bytes(12)),
            'CompanyName' => $contact->getCompany() ?: $name,
            'Address1' => $contact->getAddress1() ?: 'N/A',
            'City' => $contact->getCity() ?: 'N/A',
            'StateName' => $contact->getState() ?: 'N/A',
            'CountryName' => $this->_countryName($contact->getCountry()) ?: 'United States',
            'Zip' => $contact->getZip() ?: '00000',
            'PhoneNo_cc' => $contact->getTelCc() ?: '1',
            'PhoneNo' => $contact->getTel() ?: '0000000000',
            'Id' => $this->config['user_id'],
        ]);
    }

    /**
     * Resolve the customer ID for an existing domain from ViewDomain or ViewClient.
     *
     * @throws Registrar_Exception
     */
    private function _getCustomerIdForDomain(string $domainName): int
    {
        [, $data] = $this->_viewDomain($domainName);

        $id = $data['customerId'] ?? $data['clientId'] ?? null;
        if (!empty($id)) {
            return (int) $id;
        }

        // Fallback: look up by registrant email from ViewDomain data
        $email = $data['registrantEmail'] ?? $data['email'] ?? null;
        if (!empty($email)) {
            try {
                $result = $this->_makeRequest('ViewClient', ['UserName' => $email]);
                $clientId = $result['responseData']['clientId'] ?? null;
                if (!empty($clientId)) {
                    return (int) $clientId;
                }
            } catch (Registrar_Exception) {
                // fall through
            }
        }

        $placeholders = [':action:' => __trans('resolve customer ID for domain'), ':type:' => 'ConnectReseller'];

        throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
    }

    /**
     * Get the numeric domainNameId for a domain name.
     * Many CR API endpoints require this integer ID rather than the domain name string.
     *
     * @throws Registrar_Exception
     */
    private function _getDomainNameId(string $domainName): int
    {
        [$id] = $this->_viewDomain($domainName);

        return $id;
    }

    /**
     * Fetch (and cache within the request) a ViewDomain response.
     *
     * @return array{0: int, 1: array<string, mixed>}
     *
     * @throws Registrar_Exception
     */
    private function _viewDomain(string $domainName): array
    {
        $key = strtolower($domainName);

        if (!isset($this->_viewDomainCache[$key])) {
            $result = $this->_makeRequest('ViewDomain', ['websiteName' => $domainName]);
            $data = $result['responseData'] ?? [];
            $id = $data['domainNameId'] ?? null;

            if (!$id) {
                $placeholders = [':action:' => 'resolve domain ' . $domainName, ':type:' => 'ConnectReseller'];

                throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
            }

            $this->_viewDomainCache[$key] = [(int) $id, $data];
        }

        return $this->_viewDomainCache[$key];
    }

    /**
     * Retrieve the DNS zone ID for a domain by reading the first record from ViewDNSRecord.
     * Returns null if no records exist or the zone has not been created yet.
     */
    private function _getDnsZoneId(int $domainNameId): ?int
    {
        try {
            $result = $this->_makeRequest('ViewDNSRecord', ['WebsiteId' => $domainNameId]);
            $records = $result['responseData'] ?? [];
            if (is_array($records) && !empty($records)) {
                $first = reset($records);
                $zoneId = $first['DNSZoneID'] ?? $first['dnsZoneId'] ?? null;

                return $zoneId ? (int) $zoneId : null;
            }
        } catch (Registrar_Exception) {
            // Zone not found or no records
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // HTTP transport
    // -------------------------------------------------------------------------

    /**
     * Make a GET request to the ConnectReseller API V11.
     *
     * All requests are GET-only. Authentication is via the APIKey query parameter.
     *
     * Response envelope:
     *   { "responseMsg": { "statusCode": 200, "message": "..." }, "responseData": {...} }
     *
     * @param string $endpoint     API endpoint name (e.g. 'checkdomainavailable')
     * @param array  $params       Request parameters (excluding APIKey)
     * @param int[]  $allowedCodes Status codes treated as valid responses (default [200])
     *
     * @return array Decoded JSON response
     *
     * @throws Registrar_Exception
     */
    protected function _makeRequest(string $endpoint, array $params = [], array $allowedCodes = [200]): array
    {
        // The ConnectReseller V11 API is GET-only and authenticates via a query
        // parameter. We pass the APIKey through Symfony's 'query' option rather than
        // embedding it in the URL string, so it never appears in exception messages,
        // stack traces, or any string variable that might leak into logs.
        $baseUrl = self::API_URL . $endpoint;

        $client = ($this->_httpClient ?? $this->getHttpClient())->withOptions([
            'timeout' => 30,
            'verify_peer' => true,
            'verify_host' => true,
        ]);

        // Log request with APIKey redacted to prevent credential exposure in activity log
        $redacted = array_merge(['APIKey' => '***REDACTED***'], $params);
        $this->getLog()->debug('ConnectReseller API REQUEST: ' . $baseUrl . '?' . http_build_query($redacted));

        try {
            $response = $client->request('GET', $baseUrl, [
                'query' => array_merge(['APIKey' => $this->config['api-key']], $params),
            ]);
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface|HttpExceptionInterface $e) {
            // Do NOT include $e->getMessage() directly — it may contain the request URL
            // with the APIKey query parameter. Log a sanitized message instead.
            $this->getLog()->err('ConnectReseller HttpClientException on [' . $endpoint . ']: ' . $e::class);

            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', [':action:' => $endpoint, ':type:' => 'ConnectReseller']);
        }

        // Log response — full body in debug_mode, truncated otherwise to limit PII in logs
        if (!empty($this->config['debug_mode'])) {
            $this->getLog()->info('ConnectReseller API RESULT [' . $endpoint . ']: ' . $content);
        } else {
            $preview = strlen($content) > 500 ? substr($content, 0, 500) . '...[truncated — enable debug_mode for full response]' : $content;
            $this->getLog()->info('ConnectReseller API RESULT [' . $endpoint . ']: ' . $preview);
        }

        if ($content === '') {
            $placeholders = [':action:' => $endpoint, ':type:' => 'ConnectReseller'];

            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
        }

        $json = json_decode($content, true);

        if (!is_array($json)) {
            $this->getLog()->err('ConnectReseller non-JSON response for [' . $endpoint . ']: ' . $content);
            $placeholders = [':action:' => $endpoint, ':type:' => 'ConnectReseller'];

            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
        }

        $code = (int) ($json['responseMsg']['statusCode'] ?? $json['statusCode'] ?? 0);

        if (!in_array($code, $allowedCodes, true)) {
            $message = ($json['responseMsg']['message'] ?? '')
                ?: ($json['responseData']['message'] ?? '')
                ?: ($json['responseText'] ?? '')
                ?: ($json['statusText'] ?? '')
                ?: ('status ' . $code);

            $this->getLog()->err('ConnectReseller API error [' . $endpoint . '] (status ' . $code . '): ' . $message);

            // Include the registrar's message so admin-side errors are actionable.
            // The guest Servicedomain API sanitizes these before they reach customers.
            throw new Registrar_Exception('ConnectReseller error on :action: :message', [':action' => $endpoint, ':message' => $message]);
        }

        return $json;
    }
}
