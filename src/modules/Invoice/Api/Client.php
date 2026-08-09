<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

/**
 *Invoice management.
 */

namespace Box\Mod\Invoice\Api;

use FOSSBilling\PaginationOptions;
use FOSSBilling\Validation\Api\RequiredParams;

class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Get paginated list of invoices.
     *
     * @return array
     */
    public function get_list($data)
    {
        $data['client_id'] = $this->getIdentity()->id;
        $data['approved'] = true;
        $data['summary'] = true;

        [$sql, $params] = $this->getService()->getSearchQuery($data);
        $pager = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));

        foreach ($pager['list'] as $key => $item) {
            $pager['list'][$key] = $this->getService()->toApiSummaryArray($item);
        }

        return $pager;
    }

    /**
     * Get invoice statistics for the authenticated client.
     *
     * Returns counts, outstanding total, paid total, and last paid invoice in 2 SQL queries.
     * Use this instead of calling invoice_get_list multiple times for stat cards.
     *
     * @return array{total:int, unpaid_count:int, paid_count:int, outstanding_total:float, paid_total:float, currency:string, last_paid:array|null}
     */
    public function get_stats($data = []): array
    {
        $clientId = $this->getIdentity()->id;
        $db = $this->getDi()['db'];

        $statsSql = "
            SELECT
                COALESCE(SUM(1), 0) AS total,
                COALESCE(SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_count,
                COALESCE(SUM(CASE WHEN status = 'paid'   THEN 1 ELSE 0 END), 0) AS paid_count,
                COALESCE(SUM(CASE WHEN status = 'unpaid' THEN invoice_total ELSE 0 END), 0) AS outstanding_total,
                COALESCE(SUM(CASE WHEN status = 'paid'   THEN invoice_total ELSE 0 END), 0) AS paid_total,
                MIN(CASE WHEN status = 'unpaid' THEN currency ELSE NULL END) AS currency
            FROM (
                SELECT
                    i.id,
                    i.status,
                    i.currency,
                    COALESCE(SUM(ii.price * ii.quantity), 0) +
                        CASE WHEN COALESCE(i.taxrate, 0) > 0
                             THEN ROUND(
                                 COALESCE(SUM(CASE WHEN ii.taxed = 1 THEN ii.price * ii.quantity ELSE 0 END), 0)
                                 * i.taxrate / 100, 2)
                             ELSE 0
                        END AS invoice_total
                FROM invoice i
                LEFT JOIN invoice_item ii ON ii.invoice_id = i.id
                WHERE i.client_id = :client_id AND i.approved = 1
                GROUP BY i.id, i.status, i.currency, i.taxrate
            ) AS per_invoice";

        $statsRow = $db->getRow($statsSql, ['client_id' => $clientId]);

        $lastPaidSql = "
            SELECT i.hash, i.currency, i.taxrate, i.paid_at, i.updated_at,
                   COALESCE(SUM(ii.price * ii.quantity), 0) AS subtotal,
                   COALESCE(SUM(CASE WHEN ii.taxed = 1 THEN ii.price * ii.quantity ELSE 0 END), 0) AS taxable_subtotal
            FROM invoice i
            LEFT JOIN invoice_item ii ON ii.invoice_id = i.id
            WHERE i.client_id = :client_id AND i.approved = 1 AND i.status = 'paid'
            GROUP BY i.id, i.hash, i.currency, i.taxrate, i.paid_at, i.updated_at
            ORDER BY i.paid_at DESC, i.id DESC
            LIMIT 1";

        $lastPaidRow = $db->getRow($lastPaidSql, ['client_id' => $clientId]);

        $lastPaid = null;
        if ($lastPaidRow) {
            $taxRate = (float) ($lastPaidRow['taxrate'] ?? 0);
            $taxableSubtotal = (float) $lastPaidRow['taxable_subtotal'];
            $tax = ($taxRate > 0 && $taxableSubtotal !== 0.0)
                ? round($taxableSubtotal * $taxRate / 100, 2)
                : 0.0;
            $lastPaid = [
                'hash'       => $lastPaidRow['hash'],
                'total'      => round((float) $lastPaidRow['subtotal'] + $tax, 2),
                'currency'   => $lastPaidRow['currency'],
                'paid_at'    => $lastPaidRow['paid_at'],
                'updated_at' => $lastPaidRow['updated_at'],
            ];
        }

        return [
            'total'             => (int) ($statsRow['total'] ?? 0),
            'unpaid_count'      => (int) ($statsRow['unpaid_count'] ?? 0),
            'paid_count'        => (int) ($statsRow['paid_count'] ?? 0),
            'outstanding_total' => round((float) ($statsRow['outstanding_total'] ?? 0), 2),
            'paid_total'        => round((float) ($statsRow['paid_total'] ?? 0), 2),
            'currency'          => $statsRow['currency'] ?? '',
            'last_paid'         => $lastPaid,
        ];
    }

    /**
     * Get invoice details.
     *
     * @return array
     *
     * @throws \FOSSBilling\Exception
     */
    #[RequiredParams(['hash' => 'Invoice hash was not passed'])]
    public function get($data)
    {
        $identity = $this->getIdentity();
        $model = $this->getDi()['db']->findOne('Invoice', 'hash = :hash AND client_id = :client_id', ['hash' => $data['hash'], 'client_id' => $identity->id]);
        if (!$model) {
            throw new \FOSSBilling\InformationException('Invoice was not found');
        }

        return $this->getService()->toApiArray($model, true, $identity);
    }

    /**
     * Generates new invoice for selected order. If unpaid invoice for selected order
     * already exists, new invoice will not be generated, and old invoice hash
     * is returned.
     *
     * @return string - invoice hash
     *
     * @throws \FOSSBilling\Exception
     */
    #[RequiredParams(['order_id' => 'Order ID (order_id) was not passed'])]
    public function renewal_invoice($data)
    {
        $model = $this->getDi()['db']->findOne('ClientOrder', 'client_id = ? and id = ?', [$this->getIdentity()->id, $data['order_id']]);
        if (!$model instanceof \Model_ClientOrder) {
            throw new \FOSSBilling\InformationException('Order not found');
        }
        $service = $this->getService();
        $invoice = $service->generateForOrder($model);
        $service->approveInvoice($invoice, ['id' => $invoice->id, 'use_credits' => true]);
        $this->getDi()['logger']->info('Generated new renewal invoice #%s', $invoice->id);

        return $invoice->hash;
    }

    /**
     * Deposit money in advance. Generates new invoice for depositing money.
     * Clients currency must be defined.
     *
     * @return string - invoice hash
     */
    #[RequiredParams(['amount' => 'Amount is required'])]
    public function funds_invoice($data)
    {
        if (!is_numeric($data['amount'])) {
            throw new \FOSSBilling\InformationException('You need to enter numeric value');
        }

        $service = $this->getService();
        $invoice = $service->generateFundsInvoice($this->getIdentity(), $data['amount']);
        $service->approveInvoice($invoice, ['id' => $invoice->id]);
        $this->getDi()['logger']->info('Generated add funds invoice #%s', $invoice->id);

        return $invoice->hash;
    }

    /**
     * Get paginated list of transactions.
     *
     * @optional string $invoice_hash - filter transactions by invoice hash
     * @optional int $gateway_id - filter transactions by payment gateway id
     * @optional string $status - filter transactions by status
     * @optional string $currency - filter transactions by currency code
     * @optional string $date_from - filter transactions by date
     * @optional string $date_to - filter transactions by date
     *
     * @return array
     */
    public function transaction_get_list($data)
    {
        $data['client_id'] = $this->getIdentity()->id;
        $data['status'] = 'processed';
        $transactionService = $this->getDi()['mod_service']('Invoice', 'Transaction');
        [$sql, $params] = $transactionService->getSearchQuery($data);

        $pager = $this->getDi()['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));

        foreach ($pager['list'] as $key => $item) {
            $pager['list'][$key] = $transactionService->searchResultToApiArray($item);
        }

        return $pager;
    }

    public function get_tax_rate()
    {
        $service = $this->getDi()['mod_service']('Invoice', 'Tax');

        return $service->getTaxRateForClient($this->getIdentity());
    }

    /**
     * Pay an invoice using account credit balance.
     * Requires the client's currency to match the invoice currency.
     *
     * @return array{type: string}
     *
     * @throws \FOSSBilling\InformationException
     */
    #[RequiredParams(['hash' => 'Invoice hash was not passed'])]
    public function pay_with_credits($data): array
    {
        $identity = $this->getIdentity();
        $invoice = $this->getDi()['db']->findOne('Invoice', 'hash = :hash AND client_id = :client_id', [
            'hash' => $data['hash'],
            'client_id' => $identity->id,
        ]);
        if (!$invoice instanceof \Model_Invoice) {
            throw new \FOSSBilling\InformationException('Invoice was not found');
        }
        if ($invoice->status === \Model_Invoice::STATUS_PAID) {
            throw new \FOSSBilling\InformationException('Invoice is already paid');
        }
        if ($identity->currency && $invoice->currency && $identity->currency !== $invoice->currency) {
            throw new \FOSSBilling\InformationException(
                sprintf(
                    'Account balance is in %s but invoice is in %s. Please use a payment gateway to pay this invoice.',
                    $identity->currency,
                    $invoice->currency
                )
            );
        }
        $paid = $this->getService()->tryPayWithCredits($invoice);
        if (!$paid) {
            throw new \FOSSBilling\InformationException('Insufficient account credit to cover this invoice.');
        }

        return ['type' => 'full'];
    }
}
