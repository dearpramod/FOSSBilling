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

/**
 * Domain service management.
 */
class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Change domain nameservers. Method sends action to registrar.
     *
     * @optional string $ns3 - 3 Nameserver hostname, ie: ns3.mydomain.com
     * @optional string $ns4 - 4 Nameserver hostname, ie: ns4.mydomain.com
     *
     * @return true
     */
    public function update_nameservers($data): bool
    {
        $s = $this->_getService($data);

        $this->getDi()['events_manager']->fire(['event' => 'onBeforeClientChangeNameservers', 'params' => $data]);

        try {
            $this->getService()->updateNameservers($s, $data);
        } catch (\FOSSBilling\InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning(sprintf('Domain NS update failed for order %s: %s', $data['order_id'] ?? '?', $e->getMessage()));

            throw $this->_registrarError($e, 'Failed to update nameservers. Please verify the nameserver addresses and try again.');
        }

        $this->getDi()['events_manager']->fire(['event' => 'onAfterClientChangeNameservers', 'params' => $data]);

        return true;
    }

    /**
     * Change domain WHOIS contact details. Method sends action to registrar.
     *
     * @return true
     */
    public function update_contacts($data)
    {
        $s = $this->_getService($data);

        try {
            return $this->getService()->updateContacts($s, $data);
        } catch (\FOSSBilling\InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning(sprintf('Domain contact update failed for order %s: %s', $data['order_id'] ?? '?', $e->getMessage()));

            throw $this->_registrarError($e, 'Failed to update contact details. Please try again.');
        }
    }

    /**
     * Enable domain privacy protection.
     *
     * @return true
     */
    public function enable_privacy_protection($data)
    {
        $s = $this->_getService($data);

        try {
            return $this->getService()->enablePrivacyProtection($s);
        } catch (\FOSSBilling\InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning(sprintf('Domain privacy enable failed for order %s: %s', $data['order_id'] ?? '?', $e->getMessage()));

            throw $this->_registrarError($e, 'Failed to enable privacy protection. Please try again.');
        }
    }

    /**
     * Disable domain privacy protection.
     *
     * @return true
     */
    public function disable_privacy_protection($data)
    {
        $s = $this->_getService($data);

        try {
            return $this->getService()->disablePrivacyProtection($s);
        } catch (\FOSSBilling\InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning(sprintf('Domain privacy disable failed for order %s: %s', $data['order_id'] ?? '?', $e->getMessage()));

            throw $this->_registrarError($e, 'Failed to disable privacy protection. Please try again.');
        }
    }

    /**
     * Retrieve domain transfer code.
     *
     * @return string - transfer code
     */
    public function get_transfer_code($data)
    {
        $s = $this->_getService($data);

        try {
            return $this->getService()->getTransferCode($s);
        } catch (\FOSSBilling\InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning(sprintf('Domain transfer code retrieval failed for order %s: %s', $data['order_id'] ?? '?', $e->getMessage()));

            throw $this->_registrarError($e, 'Failed to retrieve the transfer code. Please try again.');
        }
    }

    /**
     * Get WHOIS contact details from the local database (no registrar call).
     * Returns contact data keyed by role: registrant, admin, tech, billing.
     * Since ConnectReseller assigns one contact to all four roles, each role
     * returns the same data.
     *
     * @return array{registrant: array, admin: array, tech: array, billing: array}
     */
    public function get_whois_info($data): array
    {
        $s = $this->_getService($data);

        return $this->getService()->getWhoisInfo($s);
    }

    /**
     * Fetch live WHOIS contact data from the registrar, persist to DB, return by role.
     * Makes a live registrar API call — use sparingly.
     *
     * @return array{registrant: array, admin: array, tech: array, billing: array}
     */
    public function sync_whois_info($data): array
    {
        $s = $this->_getService($data);

        try {
            return $this->getService()->syncWhoisInfo($s);
        } catch (\FOSSBilling\InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning(sprintf('Domain WHOIS sync failed for order %s: %s', $data['order_id'] ?? '?', $e->getMessage()));

            throw $this->_registrarError($e, 'Failed to sync WHOIS data from the registrar. Please try again.');
        }
    }

    /**
     * Lock domain.
     *
     * @return bool
     */
    public function lock($data)
    {
        $s = $this->_getService($data);

        try {
            return $this->getService()->lock($s);
        } catch (\FOSSBilling\InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning(sprintf('Domain lock failed for order %s: %s', $data['order_id'] ?? '?', $e->getMessage()));

            throw $this->_registrarError($e, 'Failed to lock the domain. Please try again.');
        }
    }

    /**
     * Unlock domain.
     *
     * @return bool
     */
    public function unlock($data)
    {
        $s = $this->_getService($data);

        try {
            return $this->getService()->unlock($s);
        } catch (\FOSSBilling\InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning(sprintf('Domain unlock failed for order %s: %s', $data['order_id'] ?? '?', $e->getMessage()));

            throw $this->_registrarError($e, 'Failed to unlock the domain. Please try again.');
        }
    }

    protected function _getService($data)
    {
        if (!isset($data['order_id'])) {
            throw new \FOSSBilling\Exception('Order ID is required');
        }
        $orderService = $this->getDi()['mod_service']('order');

        $order = $orderService->findForClientById($this->getIdentity(), $data['order_id']);
        if (!$order instanceof \Model_ClientOrder) {
            throw new \FOSSBilling\InformationException('Order not found');
        }

        $s = $orderService->getOrderService($order);
        if (!$s instanceof \Model_ServiceDomain || $order->status !== \Model_ClientOrder::STATUS_ACTIVE) {
            throw new \FOSSBilling\Exception('Order is not activated');
        }

        return $s;
    }

    /**
     * Convert a registrar exception into a user-safe InformationException.
     *
     * When the registrar returned a specific error (format: "… error on <endpoint>: <detail>"),
     * the detail is extracted and used as the client message — it comes from the registrar's
     * own API response and does not contain internal infrastructure information.
     * For generic transport/auth failures the provided $fallback is used instead.
     */
    private function _registrarError(\Throwable $e, string $fallback): \FOSSBilling\InformationException
    {
        // Match "… error on <word>: <detail>" — the detail is the registrar's own API error text
        if (preg_match('/error on \S+?:\s*(.+)/i', $e->getMessage(), $m)) {
            $detail = rtrim(trim($m[1]), '.');
            if ($detail !== '' && strlen($detail) <= 200) {
                return new \FOSSBilling\InformationException($detail);
            }
        }

        return new \FOSSBilling\InformationException($fallback);
    }
}
