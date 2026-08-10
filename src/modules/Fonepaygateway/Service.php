<?php

declare(strict_types=1);

/**
 * Fonepay Gateway Support — cron reconciliation.
 *
 * The Fonepay Checkout adapter (library/Payment/Adapter/Fonepay.php) settles most payments
 * in real time via the browser WebSocket / status API. This module provides the safety net:
 * on every cron run it asks the adapter to re-verify payments that were initiated but never
 * settled in-session (browser closed before returning — common with mobile deep-linking) and
 * credit any that Fonepay reports as successful.
 *
 * The listener is registered via the Hook batchConnect mechanism when this module is
 * activated (a `mod_hook` listener row in extension_meta). After a bare DB import, run
 * `POST /api/admin/hook/batch_connect {"mod":"fonepaygateway"}` (or reactivate the module).
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Fonepaygateway;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'hide_permissions' => true,
        ];
    }

    /**
     * Reconcile abandoned-but-completed Fonepay payments on each cron run.
     */
    public static function onAfterAdminCronRun(\Box_Event $event): void
    {
        $di = $event->getDi();

        try {
            $gateway = $di['db']->findOne('PayGateway', "gateway = 'Fonepay'");
            if (!$gateway || !$gateway->enabled) {
                return;
            }

            $adapter = $di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway);
            if (!method_exists($adapter, 'reconcilePending')) {
                return;
            }

            $settled = (int) $adapter->reconcilePending();
            if ($settled > 0) {
                $di['logger']->info('Fonepay reconciliation settled %s pending payment(s).', $settled);
            }
        } catch (\Throwable $e) {
            $di['logger']->error('Fonepay reconciliation cron failed: ' . $e->getMessage());
        }
    }
}
