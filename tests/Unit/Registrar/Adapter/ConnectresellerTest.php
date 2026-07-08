<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const CR_TEST_API_KEY = 'secret-test-api-key-123';

/**
 * Build an adapter wired to a MockHttpClient. Responses are served in order;
 * every request URL (including query string) is captured into $requests.
 *
 * @param array{0: int, 1: array|string}[] $responses [statusCode-in-envelope, responseData] pairs, or raw body strings
 * @param string[]                         $requests  captured request URLs (by reference)
 */
function cr_adapter(array $responses, array &$requests = []): Registrar_Adapter_Connectreseller
{
    $queue = [];
    foreach ($responses as $r) {
        if (is_string($r)) {
            $queue[] = new MockResponse($r); // raw body (e.g. malformed JSON)
        } else {
            [$status, $data] = $r;
            $queue[] = new MockResponse(json_encode([
                'responseMsg' => ['statusCode' => $status, 'message' => $data['__message'] ?? 'msg'],
                'responseData' => $data,
            ]));
        }
    }

    $i = 0;
    $client = new MockHttpClient(function (string $method, string $url) use (&$requests, &$i, $queue) {
        $requests[] = $url;
        if (!isset($queue[$i])) {
            throw new RuntimeException('Unexpected extra HTTP request: ' . $url);
        }

        return $queue[$i++];
    });

    $adapter = new Registrar_Adapter_Connectreseller([
        'api-key' => CR_TEST_API_KEY,
        'user_id' => 777,
    ]);
    $adapter->setHttpClient($client);
    $adapter->setLog(new Tests\Helpers\TestLogger());

    return $adapter;
}

function cr_domain(string $sld = 'example', string $tld = '.com'): Registrar_Domain
{
    $domain = new Registrar_Domain();
    $domain->setSld($sld);
    $domain->setTld($tld);
    $domain->setNs1('ns1.host.com');
    $domain->setNs2('ns2.host.com');
    $domain->setRegistrationPeriod(1);

    $contact = new Registrar_Domain_Contact();
    $contact->setFirstName('John');
    $contact->setLastName('Doe');
    $contact->setEmail('john@example.com');
    $contact->setAddress1('123 Main St');
    $contact->setCity('Kathmandu');
    $contact->setCountry('NP');
    $contact->setZip('44600');
    $contact->setTelCc('977');
    $contact->setTel('9800000000');
    $domain->setContactRegistrar($contact);

    return $domain;
}

// ── Availability ────────────────────────────────────────────────────────────

test('available domain returns true', function (): void {
    $adapter = cr_adapter([[200, ['available' => true, 'domainType' => 'Standard']]]);

    expect($adapter->isDomainAvailable(cr_domain()))->toBeTrue();
});

test('unavailable domain (status 400) returns false', function (): void {
    $adapter = cr_adapter([[400, []]]);

    expect($adapter->isDomainAvailable(cr_domain()))->toBeFalse();
});

test('premium domain is rejected even when available', function (): void {
    $adapter = cr_adapter([[200, ['available' => true, 'domainType' => 'Premium']]]);

    expect($adapter->isDomainAvailable(cr_domain()))->toBeFalse();
});

test('available=false with 200 status returns false', function (): void {
    $adapter = cr_adapter([[200, ['available' => false, 'domainType' => 'Standard']]]);

    expect($adapter->isDomainAvailable(cr_domain()))->toBeFalse();
});

// ── Registration ────────────────────────────────────────────────────────────

test('registerDomain provisions customer, orders domain and assigns contact', function (): void {
    $requests = [];
    $adapter = cr_adapter([
        [404, ['__message' => 'Domain not found']],                       // ViewDomain (idempotency probe → not found)
        [200, ['clientId' => 42]],                                        // ViewClient (existing customer)
        [200, ['creationDate' => 1750000000000]],                         // domainorder
        [200, ['registrantContactId' => 555]],                            // AddRegistrantContact
        [200, ['domainNameId' => 9001, 'registrantContactId' => 555]],    // ViewDomain (for updatecontact)
        [200, []],                                                        // updatecontact
    ], $requests);

    expect($adapter->registerDomain(cr_domain()))->toBeTrue();

    expect($requests[0])->toContain('ViewDomain');
    expect($requests[1])->toContain('ViewClient');
    expect($requests[2])->toContain('domainorder')
        ->and($requests[2])->toContain('ns1=ns1.host.com')
        ->and($requests[2])->toContain('isEnablePremium=0')
        ->and($requests[2])->toContain('Id=42');
    expect($requests[3])->toContain('AddRegistrantContact')
        ->and($requests[3])->toContain('CountryName=Nepal'); // ISO NP → full name
    expect($requests[5])->toContain('updatecontact')
        ->and($requests[5])->toContain('registrantContactId=555')
        ->and($requests[5])->toContain('billingContactId=555');
});

test('registerDomain creates the customer when missing', function (): void {
    $requests = [];
    $adapter = cr_adapter([
        [404, ['__message' => 'Domain not found']],   // ViewDomain probe
        [400, ['__message' => 'No client']],          // ViewClient → not found
        [200, []],                                    // AddClient
        [200, ['clientId' => 43]],                    // ViewClient again
        [200, []],                                    // domainorder
        [400, ['__message' => 'contact failed']],     // AddRegistrantContact (best-effort, swallowed)
    ], $requests);

    expect($adapter->registerDomain(cr_domain()))->toBeTrue();
    expect($requests[2])->toContain('AddClient')
        ->and($requests[2])->toContain('CountryName=Nepal');
    expect($requests[4])->toContain('domainorder');
});

test('registerDomain is idempotent — existing domain is reconciled, not re-ordered', function (): void {
    $requests = [];
    $adapter = cr_adapter([
        [200, ['domainNameId' => 9001, 'isPrivacyProtection' => false, 'isDomainLocked' => false]], // ViewDomain → exists
        [200, []],                                                                                   // UpdateNameServer
        // privacy already disabled → no further calls
    ], $requests);

    expect($adapter->registerDomain(cr_domain()))->toBeTrue();

    $urls = implode(' ', $requests);
    expect($urls)->not->toContain('domainorder');
    expect($requests[1])->toContain('UpdateNameServer');
});

test('registerDomain requires two nameservers', function (): void {
    $adapter = cr_adapter([]);
    $domain = cr_domain();
    $domain->setNs2('');

    $adapter->registerDomain($domain);
})->throws(Registrar_Exception::class, 'nameservers');

test('registerDomain rejects .us domains', function (): void {
    $adapter = cr_adapter([]);

    $adapter->registerDomain(cr_domain('example', '.us'));
})->throws(Registrar_Exception::class, 'Nexus');

test('write operations are blocked in test mode (no sandbox exists)', function (): void {
    $requests = [];
    $adapter = cr_adapter([], $requests);
    $adapter->enableTestMode();

    try {
        $adapter->registerDomain(cr_domain());
        $this->fail('Expected Registrar_Exception');
    } catch (Registrar_Exception $e) {
        expect($e->getMessage())->toContain('test mode');
    }

    expect($requests)->toBeEmpty(); // guard fires before any HTTP call
});

// ── Transfer ────────────────────────────────────────────────────────────────

test('transferDomain submits OrderType 4 with EPP code', function (): void {
    $requests = [];
    $adapter = cr_adapter([
        [200, ['clientId' => 42]],  // ViewClient
        [200, []],                  // TransferOrder
    ], $requests);

    $domain = cr_domain();
    $domain->setEpp('epp-auth-123');

    expect($adapter->transferDomain($domain))->toBeTrue();
    expect($requests[1])->toContain('TransferOrder')
        ->and($requests[1])->toContain('OrderType=4')
        ->and($requests[1])->toContain('AuthCode=epp-auth-123');
});

test('transferDomain requires an EPP code', function (): void {
    $adapter = cr_adapter([]);

    $adapter->transferDomain(cr_domain());
})->throws(Registrar_Exception::class, 'EPP');

// ── Renewal ─────────────────────────────────────────────────────────────────

test('renewDomain sends RenewalOrder with expiry year and privacy quirk encoding', function (): void {
    $requests = [];
    $expiryMs = strtotime('2027-05-01') * 1000;
    $adapter = cr_adapter([
        [200, ['domainNameId' => 9001, 'customerId' => 42, 'isPrivacyProtection' => true, 'expirationDate' => $expiryMs]], // ViewDomain
        [200, []], // RenewalOrder
    ], $requests);

    expect($adapter->renewDomain(cr_domain()))->toBeTrue();
    expect($requests[1])->toContain('RenewalOrder')
        ->and($requests[1])->toContain('OrderType=2')
        ->and($requests[1])->toContain('Expiryyear=2027')
        ->and($requests[1])->toContain('IsWhoisProtection=1'); // int 1, never string 'true'
});

// ── Domain details / EPP ───────────────────────────────────────────────────

test('getDomainDetails maps dates, nameservers, flags and contact', function (): void {
    $adapter = cr_adapter([
        [200, [
            'domainNameId' => 9001,
            'creationDate' => 1600000000000, // ms → s
            'expirationDate' => 1750000000,    // already seconds
            'nameserver1' => 'ns1.host.com',
            'nameserver2' => 'ns2.host.com',
            'isDomainLocked' => true,
            'isPrivacyProtection' => false,
            'registrantContactId' => 321,
        ]],
        [200, [
            'Name' => 'Jane Roe',
            'emailaddress' => 'jane@example.com',
            'city' => 'Pokhara',
            'countryName' => 'Nepal',
            'zip' => '33700',
            'phoneNo_cc' => '977',
            'phoneNo' => '9811111111',
        ]],
    ]);

    $d = $adapter->getDomainDetails(cr_domain());

    expect($d->getRegistrationTime())->toBe(1600000000)
        ->and($d->getExpirationTime())->toBe(1750000000)
        ->and($d->getNs1())->toBe('ns1.host.com')
        ->and($d->getLocked())->toBeTrue()
        ->and($d->getPrivacyEnabled())->toBeFalse();

    $c = $d->getContactRegistrar();
    expect($c->getFirstName())->toBe('Jane')
        ->and($c->getLastName())->toBe('Roe')
        ->and($c->getEmail())->toBe('jane@example.com');
});

test('getEpp falls back to ViewEPPCode when ViewDomain has no auth code', function (): void {
    $requests = [];
    $adapter = cr_adapter([
        [200, ['domainNameId' => 9001]],             // ViewDomain — no authCode
        [200, ['DomainSecretKey' => 'epp-secret']],  // ViewEPPCode
    ], $requests);

    expect($adapter->getEpp(cr_domain()))->toBe('epp-secret');
    expect($requests[1])->toContain('ViewEPPCode');
});

// ── Nameservers / Contact ──────────────────────────────────────────────────

test('modifyNs submits UpdateNameServer', function (): void {
    $requests = [];
    $adapter = cr_adapter([
        [200, ['domainNameId' => 9001]], // ViewDomain
        [200, []],                       // UpdateNameServer
    ], $requests);

    expect($adapter->modifyNs(cr_domain()))->toBeTrue();
    expect($requests[1])->toContain('UpdateNameServer')
        ->and($requests[1])->toContain('nameServer1=ns1.host.com');
});

test('modifyContact creates a new contact and assigns it to all four roles', function (): void {
    $requests = [];
    $adapter = cr_adapter([
        [200, ['domainNameId' => 9001, 'customerId' => 42, 'registrantContactId' => 321]], // ViewDomain
        [200, ['registrantContactId' => 999]],                                             // AddRegistrantContact
        [200, []],                                                                         // updatecontact
    ], $requests);

    expect($adapter->modifyContact(cr_domain()))->toBeTrue();
    expect($requests[1])->toContain('AddRegistrantContact');
    expect($requests[2])->toContain('updatecontact')
        ->and($requests[2])->toContain('registrantContactId=999')
        ->and($requests[2])->toContain('technicalContactId=999');
});

test('modifyContact falls back to in-place modification when no contact ID is returned', function (): void {
    $requests = [];
    $adapter = cr_adapter([
        [200, ['domainNameId' => 9001, 'customerId' => 42, 'registrantContactId' => 321]], // ViewDomain
        [200, []],                                                                         // AddRegistrantContact — no ID
        [200, []],                                                                         // ModifyRegistrantContact
    ], $requests);

    expect($adapter->modifyContact(cr_domain()))->toBeTrue();
    expect($requests[2])->toContain('ModifyRegistrantContact')
        ->and($requests[2])->toContain('RegistrantContactId=321');
});

// ── Lock / Privacy idempotence ─────────────────────────────────────────────

test('lock is a no-op when the domain is already locked', function (): void {
    $requests = [];
    $adapter = cr_adapter([
        [200, ['domainNameId' => 9001, 'isDomainLocked' => true]], // ViewDomain only
    ], $requests);

    expect($adapter->lock(cr_domain()))->toBeTrue();
    expect($requests)->toHaveCount(1);
});

test('enablePrivacyProtection submits when currently disabled', function (): void {
    $requests = [];
    $adapter = cr_adapter([
        [200, ['domainNameId' => 9001, 'isPrivacyProtection' => false]],
        [200, []],
    ], $requests);

    expect($adapter->enablePrivacyProtection(cr_domain()))->toBeTrue();
    expect($requests[1])->toContain('ManageDomainPrivacyProtection')
        ->and($requests[1])->toContain('iswhoisprotected=true');
});

// ── Error handling / security ──────────────────────────────────────────────

test('malformed JSON responses raise Registrar_Exception', function (): void {
    $adapter = cr_adapter(['this is not json {']);

    $adapter->isDomainAvailable(cr_domain());
})->throws(Registrar_Exception::class);

test('registrar error messages surface in exceptions without the API key', function (): void {
    $adapter = cr_adapter([[500, ['__message' => 'Insufficient reseller balance']]]);

    try {
        $adapter->modifyNs(cr_domain());
        $this->fail('Expected Registrar_Exception');
    } catch (Registrar_Exception $e) {
        expect($e->getMessage())->toContain('Insufficient reseller balance')
            ->and($e->getMessage())->not->toContain(CR_TEST_API_KEY);
    }
});

test('deleteDomain is explicitly unsupported', function (): void {
    $adapter = cr_adapter([]);

    $adapter->deleteDomain(cr_domain());
})->throws(Registrar_Exception::class);
