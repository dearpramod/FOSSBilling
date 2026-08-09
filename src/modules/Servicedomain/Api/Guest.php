<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Servicedomain\Api;

use FOSSBilling\Validation\Api\RequiredParams;

/**
 * Domain service management.
 */
class Guest extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Get configured TLDs which can be ordered. Shows only enabled TLDs.
     *
     * @optional bool $allow_register - shows only these TLDs which can be registered
     * @optional bool $allow_transfer - shows only these TLDs which can be transferred
     *
     * @return array - list of TLDs
     */
    public function tlds($data = []): array
    {
        $allow_register = $data['allow_register'] ?? null;
        $allow_transfer = $data['allow_transfer'] ?? null;

        $where = [];
        $where[] = 'active = 1';

        if ($allow_register !== null) {
            $where[] = 'allow_register = 1';
        }

        if ($allow_transfer !== null) {
            $where[] = 'allow_transfer = 1';
        }

        $query = implode(' AND ', $where);

        $tlds = $this->getDi()['db']->find('Tld', $query, []);
        $result = [];
        foreach ($tlds as $model) {
            $result[] = $this->getService()->tldToApiArray($model);
        }

        return $result;
    }

    /**
     * Get TLD pricing information.
     *
     * @return array
     */
    #[RequiredParams(['tld' => 'TLD is missing'])]
    public function pricing($data)
    {
        $model = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$model instanceof \Model_Tld) {
            throw new \FOSSBilling\InformationException('TLD not found');
        }

        return $this->getService()->tldToApiArray($model);
    }

    /**
     * Check if domain is available for registration. Domain registrar must be
     * configured in order to get correct results.
     *
     * @return true
     */
    #[RequiredParams([
        'tld' => 'TLD is missing',
        'sld' => 'SLD is missing',
    ])]
    public function check($data): bool
    {
        $this->getDi()['rate_limiter']->consumeOrThrow('domain_lookup_ip', (string) $this->getIp());

        $sld = htmlspecialchars((string) $data['sld'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $validator = $this->getDi()['validator'];
        if (!$validator->isSldValid($sld)) {
            throw new \FOSSBilling\InformationException('Domain :domain is invalid', [':domain' => $sld]);
        }

        $tld = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$tld instanceof \Model_Tld) {
            throw new \FOSSBilling\InformationException('Domain availability could not be determined. TLD is not active.');
        }

        // Never leak registrar adapter internals (registrar name, internal API
        // endpoint/action names, backend error text) to unauthenticated guests.
        // Safe, controlled InformationExceptions still surface; anything else from
        // the registrar adapter is logged server-side and replaced with a generic
        // message. (Local hardening — upstream lets these exceptions propagate.)
        try {
            $available = $this->getService()->isDomainAvailable($tld, $sld);
        } catch (\FOSSBilling\InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getDi()['logger']->err('Domain availability check failed for a guest: ' . $e->getMessage());

            throw new \FOSSBilling\InformationException('Domain availability could not be determined right now. Please try again later.');
        }

        if (!$available) {
            throw new \FOSSBilling\InformationException('Domain is not available.');
        }

        return true;
    }

    /**
     * Check if domain can be transferred. Domain registrar must be
     * configured in order to get correct results.
     *
     * @return true
     */
    #[RequiredParams([
        'tld' => 'TLD is missing',
        'sld' => 'SLD is missing',
    ])]
    public function can_be_transferred($data): bool
    {
        $this->getDi()['rate_limiter']->consumeOrThrow('domain_lookup_ip', (string) $this->getIp());

        $tld = $this->getService()->tldFindOneByTld($data['tld']);
        if (!$tld instanceof \Model_Tld) {
            throw new \FOSSBilling\InformationException('TLD is not active.');
        }

        // Same guard as check(): do not expose registrar adapter internals to guests.
        try {
            $canBeTransferred = $this->getService()->canBeTransferred($tld, $data['sld']);
        } catch (\FOSSBilling\InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getDi()['logger']->err('Domain transfer check failed for a guest: ' . $e->getMessage());

            throw new \FOSSBilling\InformationException('Domain transfer eligibility could not be determined right now. Please try again later.');
        }

        if (!$canBeTransferred) {
            throw new \FOSSBilling\InformationException('Domain cannot be transferred.');
        }

        return true;
    }
}
