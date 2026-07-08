<?php

declare(strict_types=1);

/**
 * Khalti Payment Gateway Adapter for FOSSBilling.
 *
 * Implements the Khalti ePayment Web Checkout flow:
 *   1. Merchant initiates payment → receives pidx + payment_url
 *   2. Client is redirected to Khalti hosted page
 *   3. After payment, Khalti redirects back to return_url with ?pidx=&status=...
 *   4. Adapter calls Lookup API to verify before crediting invoice
 *
 * Khalti API docs: https://docs.khalti.com/khalti-epayment/
 *
 * SPDX-License-Identifier: Apache-2.0
 */

use FOSSBilling\InjectionAwareInterface;
use Pimple\Container;

class Payment_Adapter_Khalti implements InjectionAwareInterface
{
    private const string SANDBOX_BASE = 'https://dev.khalti.com/api/v2';
    private const string PRODUCTION_BASE = 'https://khalti.com/api/v2';
    private const int MIN_PAISA = 1000;

    protected ?Container $di = null;
    private ?Symfony\Component\Cache\Adapter\AdapterInterface $intentCache = null;
    private ?Symfony\Contracts\HttpClient\HttpClientInterface $httpClient = null;

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
            'description' => "Accept payments via Khalti Digital Wallet — Nepal's leading digital payment platform. Customers pay using Khalti wallet, mobile banking, or eBanking.",
            'logo' => [
                'logo' => 'khalti.png',
                'height' => '40px',
                'width' => '120px',
            ],
            'form' => [
                'live_secret_key' => [
                    'text',
                    [
                        'label' => 'Live Secret Key',
                        'required' => false,
                        'hint' => 'Found under Settings > API in your Khalti merchant dashboard (admin.khalti.com).',
                    ],
                ],
                'test_secret_key' => [
                    'text',
                    [
                        'label' => 'Test / Sandbox Secret Key',
                        'required' => false,
                        'hint' => 'Found under Settings > API in your Khalti test dashboard (test-admin.khalti.com).',
                    ],
                ],
                'convert_to_npr' => [
                    'checkbox',
                    [
                        'label' => 'Convert invoice currency to NPR',
                        'description' => 'Khalti only processes payments in NPR. Enable this to automatically convert invoices in any currency (USD, EUR, etc.) to NPR before initiating the payment. When disabled, only NPR invoices can be paid via Khalti.',
                    ],
                ],
                'manual_npr_rate' => [
                    'text',
                    [
                        'label' => 'Manual NPR conversion rate',
                        'required' => false,
                        'hint' => 'NPR per 1 unit of your system\'s default currency (e.g. enter 135 if 1 USD = 135 NPR). Overrides the rate from Admin → System → Currencies. Leave blank to use the currency table rate.',
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
            $this->log('Khalti getHtml: ' . $e->getMessage(), 'error');

            return $this->renderGatewayError('Khalti');
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
     * Called by FOSSBilling's bb-ipn.php after the client returns from Khalti.
     *
     * Khalti sends these GET parameters to the return_url:
     *   pidx, txnId, amount, total_amount, status, mobile,
     *   tidx, purchase_order_id, purchase_order_name, transaction_id
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $get = $data['get'] ?? [];
        $post = $data['post'] ?? [];
        $params = array_merge($post, $get);

        $pidx = $params['pidx'] ?? null;

        /** @var Model_Transaction $tx */
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);

        // Resolve invoice from filesystem-backed intent — never trust browser GET params
        $intent = $pidx ? $this->loadPaymentIntent($pidx) : null;
        $invoiceId = $intent['invoice_id'] ?? null;

        if ($invoiceId === null) {
            $invoiceId = $tx->invoice_id ?? null;
            if ($invoiceId) {
                $this->log('Khalti: Payment intent not found for pidx=' . $pidx . '. Using tx->invoice_id fallback.', 'warn');
            }
        }

        if ($invoiceId === null) {
            $invoiceId = $params['merchant_invoice_id'] ?? null;
        }

        if ($invoiceId) {
            $inv = $this->di['db']->load('Invoice', $invoiceId);
            if ($inv) {
                $_GET['invoice_hash'] = $inv->hash;
            }
        }

        if (!$invoiceId || !$pidx) {
            $tx->txn_status = 'failed';
            $tx->error = 'Missing pidx or invoice_id in callback.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('Khalti: Missing pidx or invoice_id in callback.');
        }

        // Always call Lookup API — never trust browser-supplied status param
        $lookupResult = $this->lookupPayment($pidx);
        $verifiedStatus = $lookupResult['status'] ?? 'Unknown';
        $verifiedAmount = (int) ($lookupResult['total_amount'] ?? 0);
        $verifiedTxnId = $lookupResult['transaction_id'] ?? null;

        if ($verifiedTxnId) {
            $existingTx = $this->di['db']->findOne('Transaction', 'txn_id = ? AND status = ? AND id != ?', [$verifiedTxnId, 'processed', $id]);
            if ($existingTx) {
                $tx->txn_status = 'failed';
                $tx->error = 'Security: Duplicate transaction detected. txn_id=' . $verifiedTxnId . ' already processed in transaction #' . $existingTx->id;
                $tx->status = 'error';
                $tx->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($tx);
                $this->log('Khalti SECURITY: Duplicate transaction. pidx=' . $pidx, 'error');

                throw new Payment_Exception('Khalti: This transaction has already been processed.');
            }
        }

        /** @var Model_Invoice $invoice */
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId);

        // Use stored snapshot — never recompute exchange rates at callback time
        $expectedAmountPaisa = $intent['expected_paisa'] ?? (int) round($this->getAmountInPaisa($invoice));
        $paisaDiff = abs($verifiedAmount - $expectedAmountPaisa);

        $this->log(sprintf(
            'Khalti amount check: invoice_id=%s, expected=%d paisa, received=%d paisa, diff=%d',
            $invoiceId,
            $expectedAmountPaisa,
            $verifiedAmount,
            $paisaDiff
        ));

        if ($paisaDiff > 1) {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $verifiedTxnId ?? $pidx;
            $tx->txn_status = 'failed';
            $tx->amount = $verifiedAmount / 100;
            $tx->currency = 'NPR';
            $tx->error = sprintf(
                'SECURITY: Amount mismatch. Expected=%d paisa, received=%d paisa, diff=%d. invoice_id=%s',
                $expectedAmountPaisa,
                $verifiedAmount,
                $paisaDiff,
                $invoiceId
            );
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $this->log('Khalti SECURITY: Amount mismatch for invoice #' . $invoiceId, 'error');

            throw new Payment_Exception('Khalti: Payment amount does not match invoice total. This transaction has been flagged.');
        }

        // Non-completed statuses: record and return — let ipn.php redirect the client
        if ($verifiedStatus !== 'Completed') {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $verifiedTxnId ?? $pidx;
            $tx->txn_status = $verifiedStatus;
            $tx->amount = $verifiedAmount / 100;
            $tx->currency = 'NPR';
            $tx->error = 'Khalti payment status: ' . $verifiedStatus . '. Payment not completed.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            if ($pidx) {
                $this->deletePaymentIntent($pidx);
            }

            return;
        }

        if ($tx->status === 'processed') {
            $this->log('Khalti: Callback for already-processed tx #' . $id . '. Skipping. pidx=' . $pidx);

            return;
        }

        $expectedOrderId = 'INV-' . $invoice->serie . sprintf('%05d', $invoice->nr);
        $returnedOrderId = $lookupResult['purchase_order_id'] ?? null;

        if ($returnedOrderId === null) {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $verifiedTxnId ?? $pidx;
            $tx->txn_status = 'failed';
            $tx->amount = $verifiedAmount / 100;
            $tx->currency = 'NPR';
            $tx->error = 'Security: Khalti Lookup API did not return purchase_order_id. Cannot verify order binding.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $this->log('Khalti SECURITY: Lookup API missing purchase_order_id. pidx=' . $pidx, 'error');

            throw new Payment_Exception('Khalti: Cannot verify order identity — purchase_order_id missing from Lookup response.');
        }

        if ($returnedOrderId !== $expectedOrderId) {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $verifiedTxnId ?? $pidx;
            $tx->txn_status = 'failed';
            $tx->amount = $verifiedAmount / 100;
            $tx->currency = 'NPR';
            $tx->error = 'Security: Purchase order ID mismatch. Expected ' . $expectedOrderId . ', received ' . $returnedOrderId . '.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $this->log('Khalti SECURITY: Order ID mismatch for invoice #' . $invoiceId . '. pidx=' . $pidx, 'error');

            throw new Payment_Exception('Khalti: Payment was made for a different order. This transaction has been flagged.');
        }

        $tx->invoice_id = (int) $invoiceId;
        $tx->txn_id = $verifiedTxnId ?? $pidx;
        $tx->txn_status = $verifiedStatus;
        $tx->amount = $verifiedAmount / 100;
        $tx->currency = 'NPR';

        try {
            $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
            $clientService = $this->di['mod_service']('client');
            $clientService->addFunds($client, $tx->amount, 'Khalti payment — txn: ' . $tx->txn_id, [
                'amount' => $tx->amount,
                'description' => 'Khalti payment — txn: ' . $tx->txn_id,
                'type' => 'transaction',
                'rel_id' => $tx->id,
            ]);

            $invoiceService = $this->di['mod_service']('Invoice');
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

            throw new Payment_Exception('Khalti: ' . $e->getMessage());
        }

        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        if ($pidx) {
            $this->deletePaymentIntent($pidx);
        }
    }

    public function getHttpClient(): Symfony\Contracts\HttpClient\HttpClientInterface
    {
        if ($this->httpClient === null) {
            $this->httpClient = Symfony\Component\HttpClient\HttpClient::create(['bindto' => BIND_TO]);
        }

        return $this->httpClient;
    }

    private function generateForm(Model_Invoice $invoice): string
    {
        $invoiceCurrency = strtoupper($invoice->currency ?? 'NPR');
        $convertEnabled = !empty($this->config['convert_to_npr']);

        if ($invoiceCurrency !== 'NPR' && !$convertEnabled) {
            return '<p style="color:#dc2626;">Khalti only accepts NPR payments. This invoice is in '
                . htmlspecialchars($invoiceCurrency, ENT_QUOTES, 'UTF-8')
                . '. Enable &ldquo;Convert invoice currency to NPR&rdquo; in the Khalti gateway settings to allow multi-currency payments.</p>';
        }

        $amountPaisa = $this->getAmountInPaisa($invoice);

        if ($amountPaisa < self::MIN_PAISA) {
            $nprAmount = number_format($amountPaisa / 100, 2);

            return '<p style="color:#dc2626;">Khalti requires a minimum payment of NPR 10. The invoice total is NPR ' . $nprAmount . ', which is below the minimum.</p>';
        }

        $restoreToken = FOSSBilling\Tools::createSessionRestoreToken(session_id());
        $returnUrl = ($this->config['notify_url'] ?? '')
            . '&redirect=1'
            . '&invoice_hash=' . urlencode($invoice->hash)
            . '&restore_token=' . urlencode($restoreToken);

        $systemService = $this->di['mod_service']('System');
        $websiteUrl = rtrim((string) ($systemService->getParamValue('url') ?: ''), '/');

        $invoiceTitle = $this->buildInvoiceTitle($invoice);
        $customerInfo = $this->buildCustomerInfo($invoice);

        $purchaseOrderId = 'INV-' . $invoice->serie . sprintf('%05d', $invoice->nr);

        $payload = [
            'return_url' => $returnUrl,
            'website_url' => $websiteUrl ?: 'https://example.com',
            'amount' => $amountPaisa,
            'purchase_order_id' => $purchaseOrderId,
            'purchase_order_name' => $invoiceTitle,
        ];

        if (!empty($customerInfo)) {
            $payload['customer_info'] = $customerInfo;
        }

        $initiateUrl = $this->getApiBase() . '/epayment/initiate/';

        $this->log('Khalti initiate: purchase_order_id=' . $purchaseOrderId . ', amount=' . $amountPaisa . ' paisa');

        try {
            $response = $this->getHttpClient()->request('POST', $initiateUrl, [
                'headers' => [
                    'Authorization' => 'Key ' . $this->getSecretKey(),
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getContent(false), true);
        } catch (Exception $e) {
            return '<p style="color:#dc2626;">Khalti: Failed to connect to payment gateway. ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if ($statusCode !== 200 || empty($responseBody['payment_url'])) {
            $errorMessage = $this->extractErrorMessage($responseBody ?? []);
            $this->log('Khalti initiate failed [HTTP ' . $statusCode . ']: ' . $errorMessage, 'error');

            return '<p style="color:#dc2626;">Khalti: Could not initiate payment. ' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $rawPaymentUrl = $responseBody['payment_url'];
        $rawPidx = $responseBody['pidx'] ?? '';

        // Validate payment_url is a genuine Khalti domain
        $allowedHosts = ['pay.khalti.com', 'test-pay.khalti.com'];
        $parsedUrl = parse_url($rawPaymentUrl);
        $urlHost = $parsedUrl['host'] ?? '';
        $urlScheme = $parsedUrl['scheme'] ?? '';

        if ($urlScheme !== 'https' || !in_array($urlHost, $allowedHosts, true)) {
            $this->log('Khalti SECURITY: Unexpected payment_url domain: ' . $rawPaymentUrl, 'error');

            return '<p style="color:#dc2626;">Khalti: Payment URL failed security validation. Please contact support.</p>';
        }

        if (!empty($rawPidx)) {
            $this->storePaymentIntent($rawPidx, (int) $invoice->id, $amountPaisa, $purchaseOrderId);
        }

        $paymentUrlHtml = htmlspecialchars($rawPaymentUrl, ENT_QUOTES, 'UTF-8');
        $pidxHtml = htmlspecialchars($rawPidx, ENT_QUOTES, 'UTF-8');
        $paymentUrlJs = json_encode($rawPaymentUrl);

        $html = '<div id="khalti-payment-block" style="text-align:center;padding:24px 0;">';
        $html .= '<p style="margin-bottom:16px;color:#6c757d;">You will be redirected to Khalti to complete your payment.</p>';
        $html .= '<a href="' . $paymentUrlHtml . '" id="khalti-pay-btn"';
        $html .= ' style="display:inline-flex;align-items:center;gap:10px;padding:12px 32px;background:#CC0001;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">';
        $html .= '<span style="letter-spacing:0.5px;">Pay with Khalti</span></a>';
        $html .= '<p style="margin-top:12px;font-size:12px;color:#aaa;">Transaction reference: ' . $pidxHtml . '</p>';
        $html .= '<script>setTimeout(function(){ window.location.href=' . $paymentUrlJs . '; }, 1500);</script>';
        $html .= '</div>';

        return $html;
    }

    private function getAmountInPaisa(Model_Invoice $invoice): int
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $total = (float) $invoiceService->getTotalWithTax($invoice);
        $currency = $invoice->currency ?? 'NPR';

        return (int) round($this->convertToNpr($currency, $total) * 100);
    }

    private function getSecretKey(): string
    {
        $isTestMode = (bool) ($this->config['test_mode'] ?? false);

        if ($isTestMode) {
            $key = $this->config['test_secret_key'] ?? '';
            if (empty($key)) {
                throw new Payment_Exception('Khalti test secret key is not configured. Please set it under Configuration → Payments.');
            }
        } else {
            $key = $this->config['live_secret_key'] ?? '';
            if (empty($key)) {
                throw new Payment_Exception('Khalti live secret key is not configured. Please set it under Configuration → Payments.');
            }
        }

        return $key;
    }

    private function getApiBase(): string
    {
        return ((bool) ($this->config['test_mode'] ?? false)) ? self::SANDBOX_BASE : self::PRODUCTION_BASE;
    }

    private function lookupPayment(string $pidx): array
    {
        $lookupUrl = $this->getApiBase() . '/epayment/lookup/';

        try {
            $response = $this->getHttpClient()->request('POST', $lookupUrl, [
                'headers' => [
                    'Authorization' => 'Key ' . $this->getSecretKey(),
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode(['pidx' => $pidx]),
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getContent(false), true);
        } catch (Exception $e) {
            throw new Payment_Exception('Khalti: Lookup API request failed — ' . $e->getMessage());
        }

        if (!is_array($body)) {
            throw new Payment_Exception('Khalti: Lookup API returned an unexpected response (HTTP ' . $statusCode . ').');
        }

        if (isset($body['detail'])) {
            throw new Payment_Exception('Khalti Lookup API error: ' . $body['detail']);
        }

        return $body;
    }

    private function buildInvoiceTitle(Model_Invoice $invoice): string
    {
        $items = $this->di['db']->getAll(
            'SELECT title FROM invoice_item WHERE invoice_id = :invoice_id',
            [':invoice_id' => $invoice->id]
        );

        $prefix = 'Payment for invoice ' . $invoice->serie . sprintf('%05d', $invoice->nr);

        if (count($items) === 1 && !empty($items[0]['title'])) {
            return $prefix . ' [' . $items[0]['title'] . ']';
        }

        return $prefix;
    }

    private function buildCustomerInfo(Model_Invoice $invoice): array
    {
        $info = [];

        if (!empty($invoice->buyer_first_name) || !empty($invoice->buyer_last_name)) {
            $info['name'] = mb_substr(trim($invoice->buyer_first_name . ' ' . $invoice->buyer_last_name), 0, 16);
        }

        if (!empty($invoice->buyer_email)) {
            $info['email'] = mb_substr($invoice->buyer_email, 0, 75);
        }

        if (!empty($invoice->buyer_phone)) {
            $phone = mb_substr(preg_replace('/[^0-9]/', '', $invoice->buyer_phone), 0, 16);
            if (!empty($phone)) {
                $info['phone'] = $phone;
            }
        }

        return $info;
    }

    /**
     * Convert an amount from any currency to NPR using FOSSBilling's currency table.
     *
     * Uses toBaseCurrency() for step 1 so that a missing or zero rate throws a
     * Payment_Exception (caught by getHtml) rather than silently returning the raw
     * foreign-currency amount as if it were NPR, which caused false "invoice too small" errors.
     */
    private function convertToNpr(string $currency, float $amount): float
    {
        if (strtoupper($currency) === 'NPR') {
            return $amount;
        }

        $currencyService = $this->di['mod_service']('currency');
        $repo = $currencyService->getCurrencyRepository();
        $defaultCurrency = $repo->findDefault();
        $baseCurrency = $defaultCurrency ? strtoupper($defaultCurrency->getCode()) : 'NPR';

        // Step 1: invoice currency → base currency.
        // toBaseCurrency() throws \FOSSBilling\Exception on a missing or zero rate,
        // which we convert to Payment_Exception so getHtml() can surface a clear error.
        try {
            $amountInBase = $currencyService->toBaseCurrency($currency, $amount);
        } catch (FOSSBilling\Exception $e) {
            throw new Payment_Exception('Khalti: Cannot convert ' . strtoupper($currency) . ' to NPR — ' . $e->getMessage() . '. Please verify your currency rates under Admin → System → Currencies.');
        }

        if ($baseCurrency === 'NPR') {
            return $amountInBase;
        }

        // Step 2: base currency → NPR.
        // Manual rate from gateway settings takes priority over the currency table,
        // allowing NPR conversion even when NPR is not added as a system currency.
        $manualRate = isset($this->config['manual_npr_rate']) ? (float) $this->config['manual_npr_rate'] : 0.0;
        if ($manualRate > 0) {
            return $amountInBase * $manualRate;
        }

        // Fall back to the currency table. Throw rather than silently returning the
        // base-currency amount as NPR (which caused false "invoice too small" errors).
        $nprRate = $repo->getRateByCode('NPR');
        if ($nprRate === null || $nprRate <= 0) {
            throw new Payment_Exception('Khalti: NPR exchange rate is not configured. Set a manual rate in the Khalti gateway settings or add NPR as a currency under Admin → System → Currencies.');
        }

        return $amountInBase * $nprRate;
    }

    private function extractErrorMessage(array $response): string
    {
        if (isset($response['detail'])) {
            return is_array($response['detail'])
                ? implode(' ', array_map('strval', $response['detail']))
                : (string) $response['detail'];
        }

        if (isset($response['error_key'])) {
            $messages = [];
            foreach ($response as $field => $errors) {
                if ($field === 'error_key') {
                    continue;
                }
                if (is_array($errors)) {
                    $isAssoc = array_keys($errors) !== range(0, count($errors) - 1);
                    if ($isAssoc) {
                        foreach ($errors as $subField => $subErrors) {
                            $flat = is_array($subErrors) ? implode(', ', array_map('strval', $subErrors)) : (string) $subErrors;
                            $messages[] = ucfirst($field) . '.' . $subField . ': ' . $flat;
                        }
                    } else {
                        $messages[] = ucfirst($field) . ': ' . implode(', ', array_map('strval', $errors));
                    }
                }
            }
            if ($messages) {
                return implode('. ', $messages);
            }
        }

        return 'Unknown error from Khalti API.';
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

    // -------------------------------------------------------------------------
    // Payment intent — filesystem-backed conversion snapshot
    // -------------------------------------------------------------------------

    private function storePaymentIntent(string $pidx, int $invoiceId, int $expectedPaisa, string $purchaseOrderId): void
    {
        try {
            $cache = $this->getIntentCache();
            $item = $cache->getItem($this->intentKey($pidx));
            $item->set([
                'invoice_id' => $invoiceId,
                'expected_paisa' => $expectedPaisa,
                'purchase_order_id' => $purchaseOrderId,
                'created_at' => time(),
            ]);
            $item->expiresAfter(7200);
            $cache->save($item);
        } catch (Throwable $e) {
            $this->log('Khalti: Failed to store payment intent for pidx=' . $pidx . ': ' . $e->getMessage(), 'warn');
        }
    }

    private function loadPaymentIntent(string $pidx): ?array
    {
        try {
            $cache = $this->getIntentCache();
            $item = $cache->getItem($this->intentKey($pidx));
            if ($item->isHit()) {
                return $item->get();
            }
        } catch (Throwable $e) {
            $this->log('Khalti: Failed to load payment intent for pidx=' . $pidx . ': ' . $e->getMessage(), 'warn');
        }

        return null;
    }

    private function deletePaymentIntent(string $pidx): void
    {
        try {
            $this->getIntentCache()->deleteItem($this->intentKey($pidx));
        } catch (Throwable) {
        }
    }

    private function getIntentCache(): Symfony\Component\Cache\Adapter\AdapterInterface
    {
        if ($this->intentCache === null) {
            $redisCfg = FOSSBilling\Config::getProperty('redis');
            $useRedis = (!empty($redisCfg) && ($redisCfg['enabled'] ?? false))
                || FOSSBilling\Config::getProperty('cache.driver', 'filesystem') === 'redis';

            if ($useRedis && $this->di !== null) {
                try {
                    $this->intentCache = new Symfony\Component\Cache\Adapter\RedisAdapter(
                        $this->di['redis'],
                        'khalti',
                        7200,
                    );
                } catch (Throwable) {
                }
            }

            if ($this->intentCache === null) {
                $dataPath = defined('BB_PATH_DATA') ? BB_PATH_DATA : sys_get_temp_dir();
                $this->intentCache = new Symfony\Component\Cache\Adapter\FilesystemAdapter('khalti', 7200, $dataPath . '/cache');
            }
        }

        return $this->intentCache;
    }

    private function intentKey(string $pidx): string
    {
        return 'intent_' . hash('sha256', $pidx);
    }
}
