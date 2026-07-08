<?php

declare(strict_types=1);

/**
 * eSewa Payment Gateway Adapter for FOSSBilling.
 *
 * Implements the eSewa ePay v2 flow:
 *   1. Merchant generates HMAC-SHA256 signature and POSTs a form to eSewa
 *   2. Customer logs in to eSewa and confirms payment
 *   3. On success, eSewa redirects back to success_url with a Base64-encoded JSON response
 *   4. Adapter decodes the response, verifies the signature, then calls the
 *      Status Check API for final confirmation before crediting the invoice
 *
 * eSewa ePay docs: https://developer.esewa.com.np/pages/Epay
 *
 * SPDX-License-Identifier: Apache-2.0
 */

use FOSSBilling\InjectionAwareInterface;
use Pimple\Container;

class Payment_Adapter_Esewa implements InjectionAwareInterface
{
    private const string SANDBOX_EPAY_URL = 'https://rc-epay.esewa.com.np/api/epay/main/v2/form';
    private const string PRODUCTION_EPAY_URL = 'https://epay.esewa.com.np/api/epay/main/v2/form';
    private const string SANDBOX_STATUS_URL = 'https://rc.esewa.com.np/api/epay/transaction/status/';
    private const string PRODUCTION_STATUS_URL = 'https://esewa.com.np/api/epay/transaction/status/';
    private const string SANDBOX_SECRET = '8gBm/:&EnhH.1/q';
    private const string SANDBOX_PRODUCT_CODE = 'EPAYTEST';

    protected ?Container $di = null;

    public function __construct(private array $config)
    {
    }

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => "Accept payments via eSewa — Nepal's leading digital wallet. Customers pay using their eSewa balance, mobile banking, or eBanking.",
            'logo' => [
                'logo' => 'esewa.png',
                'height' => '40px',
                'width' => '120px',
            ],
            'form' => [
                'product_code' => [
                    'text',
                    [
                        'label' => 'Merchant / Product Code',
                        'required' => false,
                        'hint' => 'Provided by eSewa when you register as a merchant. Use EPAYTEST for sandbox testing.',
                    ],
                ],
                'secret_key' => [
                    'text',
                    [
                        'label' => 'Secret Key (HMAC)',
                        'required' => false,
                        'hint' => 'HMAC secret key provided by eSewa. In test mode the sandbox key is used automatically.',
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);

        try {
            return $this->generateForm($invoiceModel);
        } catch (Payment_Exception $e) {
            $this->log('eSewa getHtml: ' . $e->getMessage(), 'error');

            return $this->renderGatewayError('eSewa');
        }
    }

    private function renderGatewayError(string $name): string
    {
        $n = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        return '<div style="text-align:center;padding:40px 24px;">'
            . '<p style="font-size:15px;font-weight:600;color:#dc2626;margin:0 0 8px;">' . $n . ' is currently unavailable.</p>'
            . '<p style="font-size:14px;color:#6b7280;margin:0;">This payment method cannot be used right now. Please select a different payment method or contact support.</p>'
            . '</div>';
    }

    public function getInvoiceId($data)
    {
        $get = $data['get'] ?? [];

        return $get['bb_invoice_id'] ?? $get['invoice_id'] ?? null;
    }

    /**
     * Called by FOSSBilling's bb-ipn.php after the client returns from eSewa.
     *
     * On success, eSewa appends ?data=<base64> to the success_url. The decoded
     * JSON contains: transaction_code, status, total_amount, transaction_uuid,
     * product_code, signed_field_names, signature.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $get = $data['get'] ?? [];
        $post = $data['post'] ?? [];

        /** @var Model_Transaction $tx */
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);

        $encodedData = $get['data'] ?? $post['data'] ?? null;

        if (empty($encodedData)) {
            $tx->txn_status = 'failed';
            $tx->error = 'Missing eSewa response data in callback.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('eSewa: Missing payment data in callback.');
        }

        $decoded = json_decode(base64_decode($encodedData, true) ?: '', true);

        if (!is_array($decoded) || empty($decoded['transaction_uuid'])) {
            $tx->txn_status = 'failed';
            $tx->error = 'Invalid eSewa response data.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('eSewa: Invalid or malformed payment response.');
        }

        $transactionCode = $decoded['transaction_code'] ?? '';
        $esewaStatus = $decoded['status'] ?? '';
        $totalAmount = (float) ($decoded['total_amount'] ?? 0);
        $transactionUuid = $decoded['transaction_uuid'] ?? '';
        $productCode = $decoded['product_code'] ?? '';
        $signedFields = $decoded['signed_field_names'] ?? '';
        $signature = $decoded['signature'] ?? '';

        if (!$this->verifySignature($decoded, $signedFields, $signature)) {
            $tx->txn_status = 'failed';
            $tx->error = 'Security: Signature verification failed on eSewa callback.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $this->log('eSewa SECURITY: Signature mismatch on callback. transaction_uuid=' . $transactionUuid, 'error');

            throw new Payment_Exception('eSewa: Signature verification failed. This transaction has been flagged.');
        }

        $invoiceId = $get['bb_invoice_id'] ?? $post['bb_invoice_id'] ?? $tx->invoice_id ?? null;

        if ($invoiceId) {
            $inv = $this->di['db']->load('Invoice', $invoiceId);
            if ($inv) {
                $_GET['invoice_hash'] = $inv->hash;
            }
        }

        if (!$invoiceId) {
            $tx->txn_status = 'failed';
            $tx->error = 'Missing invoice_id in eSewa callback.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('eSewa: Missing invoice_id in callback.');
        }

        /** @var Model_Invoice $invoice */
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId);

        if ($tx->status === 'processed') {
            $this->log('eSewa: Callback received for already-processed tx #' . $id . '. Skipping.');

            return;
        }

        if ($esewaStatus !== 'COMPLETE') {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $transactionCode ?: $transactionUuid;
            $tx->txn_status = strtolower($esewaStatus);
            $tx->error = 'eSewa payment status: ' . $esewaStatus;
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('eSewa: Payment not completed. Status: ' . $esewaStatus);
        }

        $statusResult = $this->checkTransactionStatus($productCode, $transactionUuid, $totalAmount);
        $verifiedStatus = $statusResult['status'] ?? '';
        $verifiedAmount = (float) ($statusResult['total_amount'] ?? 0);

        if ($verifiedStatus !== 'COMPLETE') {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $transactionCode ?: $transactionUuid;
            $tx->txn_status = strtolower($verifiedStatus);
            $tx->error = 'eSewa status check returned: ' . $verifiedStatus;
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('eSewa: Status check verification failed. Status: ' . $verifiedStatus);
        }

        $txnIdForRecord = $transactionCode ?: $transactionUuid;
        if ($txnIdForRecord) {
            $existingTx = $this->di['db']->findOne('Transaction', 'txn_id = ? AND status = ? AND id != ?', [$txnIdForRecord, 'processed', $id]);
            if ($existingTx) {
                $tx->txn_status = 'failed';
                $tx->error = 'Security: Duplicate transaction. txn_id=' . $txnIdForRecord . ' already processed in transaction #' . $existingTx->id;
                $tx->status = 'error';
                $tx->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($tx);
                $this->log('eSewa SECURITY: Duplicate transaction attempt. transaction_uuid=' . $transactionUuid, 'error');

                throw new Payment_Exception('eSewa: This transaction has already been processed.');
            }
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        $expectedAmount = (float) $invoiceService->getTotalWithTax($invoice);

        if (abs($verifiedAmount - $expectedAmount) > 0.01) {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $txnIdForRecord;
            $tx->txn_status = 'failed';
            $tx->amount = $verifiedAmount;
            $tx->currency = 'NPR';
            $tx->error = 'Security: Amount mismatch. Expected NPR ' . $expectedAmount . ', received NPR ' . $verifiedAmount . '.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $this->log('eSewa SECURITY: Amount mismatch for invoice #' . $invoiceId . '. Expected=' . $expectedAmount . ', Received=' . $verifiedAmount, 'error');

            throw new Payment_Exception('eSewa: Payment amount does not match invoice total.');
        }

        $expectedProductCode = $this->getProductCode();
        if ($productCode !== $expectedProductCode) {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $txnIdForRecord;
            $tx->txn_status = 'failed';
            $tx->amount = $verifiedAmount;
            $tx->currency = 'NPR';
            $tx->error = 'Security: Product code mismatch. Expected ' . $expectedProductCode . ', received ' . $productCode . '.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $this->log('eSewa SECURITY: Product code mismatch for invoice #' . $invoiceId, 'error');

            throw new Payment_Exception('eSewa: Product code mismatch. This transaction has been flagged.');
        }

        $tx->invoice_id = (int) $invoiceId;
        $tx->txn_id = $txnIdForRecord;
        $tx->txn_status = 'complete';
        $tx->amount = $verifiedAmount;
        $tx->currency = 'NPR';

        try {
            $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
            $clientService = $this->di['mod_service']('client');
            $clientService->addFunds($client, $tx->amount, 'eSewa payment — txn: ' . $tx->txn_id, [
                'amount' => $tx->amount,
                'description' => 'eSewa payment — txn: ' . $tx->txn_id,
                'type' => 'transaction',
                'rel_id' => $tx->id,
            ]);

            $isDepositInvoice = $invoiceService->isInvoiceTypeDeposit($invoice);

            if ($isDepositInvoice) {
                $invoice->status = 'paid';
                $invoice->paid_at = date('Y-m-d H:i:s');
                $invoice->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($invoice);
                $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
            } else {
                $invoiceService->payInvoiceWithCredits($invoice);
                $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
            }
        } catch (Exception $e) {
            $tx->error = 'Post-payment processing error: ' . $e->getMessage();
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('eSewa: ' . $e->getMessage());
        }

        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    public function getHttpClient(): Symfony\Contracts\HttpClient\HttpClientInterface
    {
        return Symfony\Component\HttpClient\HttpClient::create(['bindto' => BIND_TO]);
    }

    private function generateForm(Model_Invoice $invoice): string
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $totalAmount = (float) $invoiceService->getTotalWithTax($invoice);
        $productCode = $this->getProductCode();
        $transactionUuid = 'INV-' . $invoice->serie . sprintf('%05d', $invoice->nr) . '-' . time();

        $successUrl = $this->buildCallbackUrl($invoice, 'success');
        $failureUrl = $this->buildCallbackUrl($invoice, 'failure');

        $signedFieldNames = 'total_amount,transaction_uuid,product_code';
        $signatureInput = 'total_amount=' . $totalAmount . ',transaction_uuid=' . $transactionUuid . ',product_code=' . $productCode;
        $signature = $this->generateSignature($signatureInput);

        $formUrl = $this->isTestMode() ? self::SANDBOX_EPAY_URL : self::PRODUCTION_EPAY_URL;

        $fields = [
            'amount' => $totalAmount,
            'tax_amount' => 0,
            'total_amount' => $totalAmount,
            'transaction_uuid' => $transactionUuid,
            'product_code' => $productCode,
            'product_service_charge' => 0,
            'product_delivery_charge' => 0,
            'success_url' => $successUrl,
            'failure_url' => $failureUrl,
            'signed_field_names' => $signedFieldNames,
            'signature' => $signature,
        ];

        $html = '<div id="esewa-payment-block" style="text-align:center;padding:24px 0;">';
        $html .= '<p style="margin-bottom:16px;color:#6c757d;">You will be redirected to eSewa to complete your payment.</p>';
        $html .= '<form id="esewa-payment-form" action="' . htmlspecialchars($formUrl, ENT_QUOTES, 'UTF-8') . '" method="POST">';

        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
        }

        $html .= '<button type="submit" style="display:inline-flex;align-items:center;gap:10px;padding:12px 32px;background:#60BB46;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:15px;">';
        $html .= '<span style="letter-spacing:0.5px;">Pay with eSewa</span></button>';
        $html .= '</form>';
        $html .= '<p style="margin-top:12px;font-size:12px;color:#aaa;">Transaction ID: ' . htmlspecialchars($transactionUuid, ENT_QUOTES, 'UTF-8') . '</p>';
        $html .= '<script>setTimeout(function(){ document.getElementById("esewa-payment-form").submit(); }, 1500);</script>';
        $html .= '</div>';

        return $html;
    }

    private function generateSignature(string $message): string
    {
        return base64_encode(hash_hmac('sha256', $message, $this->getSecretKey(), true));
    }

    private function verifySignature(array $data, string $signedFields, string $signature): bool
    {
        if (empty($signedFields) || empty($signature)) {
            return false;
        }

        $fields = explode(',', $signedFields);
        $parts = [];

        foreach ($fields as $field) {
            $field = trim($field);
            if (!array_key_exists($field, $data)) {
                return false;
            }
            $parts[] = $field . '=' . $data[$field];
        }

        $expected = $this->generateSignature(implode(',', $parts));

        return hash_equals($expected, $signature);
    }

    private function checkTransactionStatus(string $productCode, string $transactionUuid, float $totalAmount): array
    {
        $baseUrl = $this->isTestMode() ? self::SANDBOX_STATUS_URL : self::PRODUCTION_STATUS_URL;
        $statusUrl = $baseUrl . '?' . http_build_query([
            'product_code' => $productCode,
            'total_amount' => $totalAmount,
            'transaction_uuid' => $transactionUuid,
        ]);

        try {
            $response = $this->getHttpClient()->request('GET', $statusUrl);
            $body = json_decode($response->getContent(false), true);
        } catch (Exception $e) {
            throw new Payment_Exception('eSewa: Status check API request failed — ' . $e->getMessage());
        }

        if (!is_array($body)) {
            throw new Payment_Exception('eSewa: Status check API returned an unexpected response.');
        }

        return $body;
    }

    private function buildCallbackUrl(Model_Invoice $invoice, string $type = 'success'): string
    {
        $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "Esewa"');
        $restoreToken = FOSSBilling\Tools::createSessionRestoreToken(session_id());

        $params = [
            'bb_gateway_id' => $payGateway->id,
            'bb_invoice_id' => $invoice->id,
            'invoice_hash' => $invoice->hash,
            'redirect' => 1,
            'restore_token' => $restoreToken,
            'esewa_type' => $type,
        ];

        $systemService = $this->di['mod_service']('System');
        $baseUrl = rtrim((string) ($systemService->getParamValue('url') ?: ''), '/');

        return $baseUrl . '/bb-ipn.php?' . http_build_query($params);
    }

    private function getProductCode(): string
    {
        if ($this->isTestMode()) {
            return self::SANDBOX_PRODUCT_CODE;
        }

        $code = $this->config['product_code'] ?? '';
        if (empty($code)) {
            throw new Payment_Exception('eSewa: Merchant product code is not configured. Please set it under Configuration → Payments.');
        }

        return $code;
    }

    private function getSecretKey(): string
    {
        if ($this->isTestMode()) {
            return self::SANDBOX_SECRET;
        }

        $key = $this->config['secret_key'] ?? '';
        if (empty($key)) {
            throw new Payment_Exception('eSewa: Secret key is not configured. Please set it under Configuration → Payments.');
        }

        return $key;
    }

    private function isTestMode(): bool
    {
        return (bool) ($this->config['test_mode'] ?? false);
    }

    private function log(string $message, string $level = 'info'): void
    {
        try {
            $logger = $this->di['logger'];
            match ($level) {
                'error' => $logger->err($message),
                'warn' => $logger->warn($message),
                'debug' => $logger->debug($message),
                default => $logger->info($message),
            };
        } catch (Throwable) {
            error_log($message);
        }
    }
}
