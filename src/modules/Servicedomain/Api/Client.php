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

        $this->_guardRegistrar(
            fn () => $this->getService()->updateNameservers($s, $data),
            'Nameservers could not be updated right now. Please try again later.'
        );

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

        return $this->_guardRegistrar(
            fn () => $this->getService()->updateContacts($s, $data),
            'Contact details could not be updated right now. Please try again later.'
        );
    }

    /**
     * Enable domain privacy protection.
     *
     * @return true
     */
    public function enable_privacy_protection($data)
    {
        $s = $this->_getService($data);

        return $this->_guardRegistrar(
            fn () => $this->getService()->enablePrivacyProtection($s),
            'Privacy protection could not be enabled right now. Please try again later.'
        );
    }

    /**
     * Disable domain privacy protection.
     *
     * @return true
     */
    public function disable_privacy_protection($data)
    {
        $s = $this->_getService($data);

        return $this->_guardRegistrar(
            fn () => $this->getService()->disablePrivacyProtection($s),
            'Privacy protection could not be disabled right now. Please try again later.'
        );
    }

    /**
     * Synchronize domain registration details with the registrar.
     *
     * @return true
     */
    public function sync($data)
    {
        $s = $this->_getService($data);
        $this->getService()->synchronizeDomain($s);

        return true;
    }

    /**
     * Retrieve domain transfer code.
     *
     * @return string - transfer code
     */
    public function get_transfer_code($data)
    {
        $s = $this->_getService($data);

        return $this->_guardRegistrar(
            fn () => $this->getService()->getTransferCode($s),
            'The transfer authorization (EPP) code could not be retrieved right now. Please try again later.'
        );
    }

    /**
     * Lock domain.
     *
     * @return bool
     */
    public function lock($data)
    {
        $s = $this->_getService($data);

        return $this->_guardRegistrar(
            fn () => $this->getService()->lock($s),
            'The domain could not be locked right now. Please try again later.'
        );
    }

    /**
     * Unlock domain.
     *
     * @return bool
     */
    public function unlock($data)
    {
        $s = $this->_getService($data);

        return $this->_guardRegistrar(
            fn () => $this->getService()->unlock($s),
            'The domain could not be unlocked right now. Please try again later.'
        );
    }

    /**
     * Run a registrar-backed action, preventing adapter internals (registrar name,
     * internal API endpoint/action names, backend error text) from reaching the
     * client. Safe, controlled InformationExceptions still surface; anything else
     * is logged server-side and replaced with a generic message.
     *
     * @param callable():mixed $action
     */
    private function _guardRegistrar(callable $action, string $failureMessage): mixed
    {
        try {
            return $action();
        } catch (\FOSSBilling\InformationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getDi()['logger']->err('Domain management action failed: ' . $e->getMessage());

            throw new \FOSSBilling\InformationException($failureMessage);
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

        $orderService->assertOrderUsable($order);

        $s = $orderService->getOrderService($order);
        if (!$s instanceof \Model_ServiceDomain || $order->status !== \Model_ClientOrder::STATUS_ACTIVE) {
            throw new \FOSSBilling\Exception('Order is not activated');
        }

        return $s;
    }
}
