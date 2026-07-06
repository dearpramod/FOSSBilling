<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Index;

use FOSSBilling\InjectionAwareInterface;

class Service implements InjectionAwareInterface
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

    /**
     * Get dashboard data for a client.
     *
     * This method aggregates all data needed for the client dashboard into a single
     * response. It fetches profile information, ticket statistics, invoice statistics,
     * order statistics, and recent items.
     *
     * @param \Model_Client $client The client model to get dashboard data for
     *
     * @return array Dashboard data containing profile, tickets, invoices, orders, recent_orders, and recent_tickets
     */
    public function getDashboardData(\Model_Client $client): array
    {
        $data['client_id'] = $client->id;

        return [
            'profile' => $this->getProfile($client),
            'tickets' => $this->getTicketsData($data),
            'invoices' => $this->getInvoicesData($data),
            'orders' => $this->getOrdersData($data),
            'recent_orders' => $this->getRecentOrders($data),
            'recent_tickets' => $this->getRecentTickets($data),
            'balance' => $this->getClientBalance($client),
            'recent_invoices' => $this->getRecentInvoices($data),
            'recent_emails' => $this->getRecentEmails($data),
        ];
    }

    private function getClientBalance(\Model_Client $client): float
    {
        $service = $this->di['mod_service']('Client', 'Balance');

        return (float) $service->getClientBalance($client);
    }

    private function getRecentInvoices(array $data): array
    {
        // Single JOIN replaces 16 queries (1 ID fetch + 5 loads + 5×2 item/toArray) with 1.
        // Dashboard only needs id, serie, nr, hash, status, currency, created_at, total.
        $sql = 'SELECT i.id, i.serie, i.nr, i.hash, i.status, i.currency, i.created_at,
                       COALESCE(SUM(ii.price * ii.quantity), 0) AS total
                FROM invoice i
                LEFT JOIN invoice_item ii ON ii.invoice_id = i.id
                WHERE i.client_id = :client_id AND i.approved = 1
                GROUP BY i.id, i.serie, i.nr, i.hash, i.status, i.currency, i.created_at
                ORDER BY i.id DESC
                LIMIT 5';

        return $this->di['db']->getAll($sql, $data);
    }

    private function getRecentEmails(array $data): array
    {
        $sql = 'SELECT id, subject, created_at
                 FROM activity_client_email
                 WHERE client_id = :client_id
                 ORDER BY created_at DESC LIMIT 5';

        return $this->di['db']->getAll($sql, $data);
    }

    private function getProfile(\Model_Client $client): array
    {
        $clientService = $this->di['mod_service']('client');

        // deep=false: balance is already returned as a separate key; avoid fetching it twice
        return $clientService->toApiArray($client, false);
    }

    private function getTicketsData(array $data): array
    {
        $sql = 'SELECT status, COUNT(*) as total
                 FROM support_ticket
                 WHERE client_id = :client_id
                 GROUP BY status';

        $results = $this->di['db']->getAll($sql, $data);

        $counts = [
            'total' => 0,
            'open' => 0,
            'on_hold' => 0,
            'closed' => 0,
        ];

        foreach ($results as $row) {
            $counts['total'] += (int) $row['total'];

            $status = (string) $row['status'];
            if (array_key_exists($status, $counts) && $status !== 'total') {
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }

    private function getRecentTickets(array $data): array
    {
        $sql = 'SELECT st.id
                 FROM support_ticket st
                 WHERE st.client_id = :client_id
                 ORDER BY st.updated_at DESC
                 LIMIT 5';

        $rows = $this->di['db']->getAll($sql, $data);

        $ids = array_column($rows, 'id');

        if (empty($ids)) {
            return [];
        }

        $supportService = $this->di['mod_service']('support');

        return $supportService->getBatchForApi($ids, false, $this->di['loggedin_client']);
    }

    private function getInvoicesData(array $data): array
    {
        $sql = 'SELECT status, COUNT(*) as total
                 FROM invoice
                 WHERE client_id = :client_id
                 AND approved = 1
                 GROUP BY status';

        $results = $this->di['db']->getAll($sql, $data);

        $counts = [
            'total' => 0,
            'paid' => 0,
            'unpaid' => 0,
        ];

        foreach ($results as $row) {
            $counts['total'] += (int) $row['total'];

            $status = (string) $row['status'];
            if (in_array($status, ['paid', 'unpaid'], true)) {
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }

    private function getOrdersData(array $data): array
    {
        $systemService = $this->di['mod_service']('system');
        $daysUntilExpiration = (int) $systemService->getParamValue('invoice_issue_days_before_expire', 14);

        // Single query replaces two separate round-trips: status counts + expiring count.
        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(status = 'active') AS active,
                    SUM(
                        status = 'active'
                        AND invoice_option = 'issue-invoice'
                        AND period IS NOT NULL
                        AND expires_at IS NOT NULL
                        AND unpaid_invoice_id IS NULL
                        AND DATEDIFF(expires_at, NOW()) <= :days
                    ) AS expiring
                FROM client_order
                WHERE client_id = :client_id
                AND group_master = 1";

        $row = $this->di['db']->getRow($sql, [
            'client_id' => $data['client_id'],
            'days'      => $daysUntilExpiration,
        ]);

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'active'   => (int) ($row['active'] ?? 0),
            'expiring' => (int) ($row['expiring'] ?? 0),
        ];
    }

    private function getRecentOrders(array $data): array
    {
        $sql = 'SELECT co.id
                 FROM client_order co
                 WHERE co.client_id = :client_id
                 AND co.group_master = 1
                 ORDER BY co.updated_at DESC
                 LIMIT 5';

        $rows = $this->di['db']->getAll($sql, $data);

        $ids = array_column($rows, 'id');

        if (empty($ids)) {
            return [];
        }

        $orderService = $this->di['mod_service']('order');

        return $orderService->getBatchForApi($ids, $this->di['loggedin_client']);
    }
}
