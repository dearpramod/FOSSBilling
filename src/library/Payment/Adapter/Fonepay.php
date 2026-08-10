<?php

declare(strict_types=1);

/**
 * Fonepay Checkout (Intent) payment adapter for FOSSBilling / MeroPanel.
 *
 * Implements the Fonepay "Checkout Intent" web flow (QR-based):
 *   1. oAuth login (Basic auth + RSA-SHA256 signature) -> JWT access token
 *   2. generate-intent-qr -> qrString + websocketId + prn (reference)
 *   3. Render the QR for the customer to scan/pay with any Fonepay bank/wallet app
 *   4. Verify server-side via thirdPartyDynamicQrGetStatus (authoritative) -> credit invoice
 *
 * PHASE 1+2 (this file): config, signature/JWT login, generate-intent-qr, QR render,
 * the real-time browser WebSocket (websocketId) that settles the payment the instant it
 * completes, and authoritative server-side verification (thirdPartyDynamicQrGetStatus)
 * + crediting via the standard ipn.php callback. A manual "check status" button is the
 * fallback when the WebSocket is unavailable. PHASE 3 (not yet): mobile bank-list
 * deep-linking and a cron reconciliation sweep for payments abandoned mid-flow.
 *
 * Amounts: Fonepay processes NPR as a DECIMAL amount (e.g. 100.00), min 1, max 9,999,999.
 * Signature: Base64(RSA-SHA256(exact_request_body)) using the merchant PKCS8 private key.
 *
 * Docs: "Checkout Intent Flow" v1.10 (Fonepay).
 *
 * SPDX-License-Identifier: Apache-2.0
 */

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use FOSSBilling\InjectionAwareInterface;
use Pimple\Container;

class Payment_Adapter_Fonepay implements InjectionAwareInterface
{
    // UAT is documented; production base differs — the admin can override it in config.
    private const string SANDBOX_BASE = 'https://uat-new-merchant-api.fonepay.com';
    private const string PRODUCTION_BASE = 'https://merchant-api.fonepay.com';

    // Login lives on a different service path than the other third-party endpoints.
    private const string LOGIN_PATH = '/api/merchant/merchantDetailsForThirdParty/v2/login';
    private const string API_PATH = '/api/merchant/third-party/v2';

    private const float MIN_AMOUNT = 1.0;
    private const float MAX_AMOUNT = 9_999_999.0;

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
            'description' => 'Accept payments via Fonepay Checkout (Intent) — customers pay by scanning a QR with any Fonepay-enabled mobile banking or wallet app in Nepal.',
            'logo' => [
                'logo' => 'fonepay.png',
                'height' => '40px',
                'width' => '120px',
            ],
            'form' => [
                'merchant_username' => [
                    'text', [
                        'label' => 'Merchant username (oAuth)',
                        'required' => false,
                        'hint' => 'Provided by Fonepay for third-party/Intent API access.',
                        'required_when' => ['enabled' => true],
                    ],
                ],
                'merchant_password' => [
                    'password', [
                        'label' => 'Merchant password (oAuth)',
                        'required' => false,
                        'required_when' => ['enabled' => true],
                    ],
                ],
                'private_key' => [
                    'textarea', [
                        'label' => 'Merchant private key (PKCS8, Base64)',
                        'required' => false,
                        'hint' => 'Base64 PKCS8 private key WITHOUT the PEM header/footer lines. Used to sign requests (RSA-SHA256). Fonepay verifies with your public key. Keep this secret.',
                        'required_when' => ['enabled' => true],
                    ],
                ],
                'terminal_id' => [
                    'text', [
                        'label' => 'Terminal ID',
                        'required' => false,
                        'hint' => 'Your Fonepay merchant terminal/PAN number (max 16 chars).',
                        'required_when' => ['enabled' => true],
                    ],
                ],
                'api_base_url' => [
                    'text', [
                        'label' => 'API base URL (optional override)',
                        'required' => false,
                        'hint' => 'Leave blank to use the built-in sandbox/production URL based on Test mode. Set this to the exact base URL Fonepay gave you if it differs (no trailing slash).',
                    ],
                ],
                'convert_to_npr' => [
                    'checkbox', [
                        'label' => 'Convert invoice currency to NPR',
                        'description' => 'Fonepay only processes NPR. Enable this to convert non-NPR invoices to NPR before payment. When disabled, only NPR invoices can be paid via Fonepay.',
                    ],
                ],
                'manual_npr_rate' => [
                    'text', [
                        'label' => 'Manual NPR conversion rate',
                        'required' => false,
                        'hint' => 'NPR per 1 unit of your default currency (e.g. 135 if 1 USD = 135 NPR). Overrides the currency-table rate. Leave blank to use the currency table.',
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice = $this->di['db']->load('Invoice', $invoice_id);

        try {
            return $this->generateForm($invoice);
        } catch (Payment_Exception $e) {
            $this->log('Fonepay getHtml: ' . $e->getMessage(), 'error');

            return $this->renderError($e->getMessage());
        }
    }

    private function renderError(string $message): string
    {
        return '<div style="text-align:center;padding:32px 24px;">'
            . '<p style="font-size:15px;font-weight:600;color:#dc2626;margin:0 0 8px;">Fonepay is currently unavailable.</p>'
            . '<p style="font-size:14px;color:#6b7280;margin:0;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div>';
    }

    public function getInvoiceId($data)
    {
        $get = $data['get'] ?? [];

        return $get['bb_invoice_id'] ?? $get['invoice_id'] ?? null;
    }

    /**
     * Server-side verification + crediting. Reached via ipn.php when the client is sent to
     * the callback URL — driven by the real-time Fonepay WebSocket, or the manual "check
     * status" button as a fallback. The authoritative source is the Fonepay status API;
     * the browser WebSocket signal is never trusted on its own.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $params = array_merge($data['post'] ?? [], $data['get'] ?? []);
        $referenceLabel = trim((string) ($params['reference_label'] ?? $params['referenceLabel'] ?? ''));

        /** @var Model_Transaction $tx */
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);

        if ($referenceLabel === '') {
            $referenceLabel = trim((string) ($tx->txn_id ?? ''));
        }

        if ($referenceLabel === '') {
            $this->failTx($tx, 'Fonepay: missing reference label in callback — cannot verify.');

            throw new Payment_Exception('Fonepay: missing reference label in callback.');
        }

        $intent = $this->loadPaymentIntent($referenceLabel);
        $invoiceId = $intent['invoice_id'] ?? ($tx->invoice_id ?? null);
        $terminalId = $intent['terminal_id'] ?? trim((string) ($this->config['terminal_id'] ?? ''));

        if (!$invoiceId) {
            $this->failTx($tx, 'Fonepay: cannot resolve invoice for reference ' . $referenceLabel . ' (intent cache cold).');

            throw new Payment_Exception('Fonepay: cannot identify invoice for this payment.');
        }

        // === Authoritative status check ===
        $status = $this->getPaymentStatus($terminalId, $referenceLabel);
        $paymentStatus = strtolower((string) ($status['paymentStatus'] ?? 'unknown'));
        $verifiedAmount = (float) ($status['totalTransactionAmount'] ?? 0);
        $traceId = (string) ($status['fonepayTraceId'] ?? '');

        $tx->invoice_id = (int) $invoiceId;
        $tx->txn_id = $traceId !== '' ? $traceId : $referenceLabel;
        $tx->currency = 'NPR';
        $tx->amount = $verifiedAmount;
        $tx->updated_at = date('Y-m-d H:i:s');

        if ($paymentStatus !== 'success') {
            $tx->txn_status = $paymentStatus;
            $tx->status = in_array($paymentStatus, ['pending', 'unknown'], true) ? 'pending' : 'error';
            $tx->error = 'Fonepay payment status: ' . ($status['paymentMessage'] ?? $paymentStatus);
            $this->di['db']->store($tx);

            return;
        }

        // Amount check against the snapshot taken at initiation.
        $expected = (float) ($intent['expected_amount'] ?? 0);
        if ($expected > 0 && abs($verifiedAmount - $expected) > 0.01) {
            $this->failTx($tx, sprintf('SECURITY: amount mismatch. expected=%.2f NPR, received=%.2f NPR, ref=%s', $expected, $verifiedAmount, $referenceLabel));

            throw new Payment_Exception('Fonepay: payment amount does not match the invoice. Flagged.');
        }

        // Duplicate guard on the Fonepay trace id.
        if ($traceId !== '') {
            $dupe = $this->di['db']->findOne('Transaction', 'txn_id = ? AND status = ? AND id != ?', [$traceId, 'processed', $id]);
            if ($dupe) {
                $this->failTx($tx, 'SECURITY: duplicate transaction. fonepayTraceId=' . $traceId . ' already processed in #' . $dupe->id);

                throw new Payment_Exception('Fonepay: this transaction has already been processed.');
            }
        }

        if ($tx->status === 'processed') {
            return;
        }

        /** @var Model_Invoice $invoiceModel */
        $invoiceModel = $this->di['db']->getExistingModelById('Invoice', $invoiceId);

        try {
            $invoiceService = $this->di['mod_service']('Invoice');
            $clientService = $this->di['mod_service']('client');
            $client = $this->di['db']->getExistingModelById('Client', $invoiceModel->client_id);

            // Credit the invoice total in the invoice's own currency (client balance is
            // currency-unaware). Then approve + pay-with-credits (or markAsPaid for deposit).
            $invoiceTotal = (float) $invoiceService->getTotalWithTax($invoiceModel);
            $clientService->addFunds($client, $invoiceTotal, 'Fonepay payment — ref: ' . $referenceLabel, [
                'amount' => $invoiceTotal,
                'description' => 'Fonepay payment — ref: ' . $referenceLabel,
                'type' => 'transaction',
                'rel_id' => $tx->id,
            ]);

            if (!$invoiceModel->approved) {
                $invoiceService->approveInvoice($invoiceModel, ['use_credits' => false]);
            }

            if ($invoiceService->isInvoiceTypeDeposit($invoiceModel)) {
                $invoiceService->markAsPaid($invoiceModel);
            } else {
                $invoiceService->payInvoiceWithCredits($invoiceModel);
            }
            $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
        } catch (Exception $e) {
            $this->failTx($tx, 'Post-payment processing error: ' . $e->getMessage());

            throw new Payment_Exception('Fonepay: ' . $e->getMessage());
        }

        $tx->txn_status = 'success';
        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        // Let ipn.php redirect the client back to the paid invoice.
        $_GET['invoice_hash'] = $invoiceModel->hash;
        $this->di['request']->query->set('invoice_hash', $invoiceModel->hash);

        $this->deletePaymentIntent($referenceLabel);
    }

    private function failTx(Model_Transaction $tx, string $error): void
    {
        $tx->txn_status = 'failed';
        $tx->error = $error;
        $tx->status = 'error';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
        $this->log($error, 'error');
    }

    // -------------------------------------------------------------------------
    // Payment initiation + QR rendering
    // -------------------------------------------------------------------------

    private function generateForm(Model_Invoice $invoice): string
    {
        $invoiceCurrency = strtoupper($invoice->currency ?? 'NPR');
        $convertEnabled = !empty($this->config['convert_to_npr']);

        if ($invoiceCurrency !== 'NPR' && !$convertEnabled) {
            throw new Payment_Exception('Fonepay only accepts NPR. Enable "Convert invoice currency to NPR" in the Fonepay gateway settings to pay non-NPR invoices.');
        }

        $amount = round($this->getAmountInNpr($invoice), 2);
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            throw new Payment_Exception('Fonepay amount out of range (NPR 1 – 9,999,999). Invoice converts to NPR ' . number_format($amount, 2) . '.');
        }

        $terminalId = trim((string) ($this->config['terminal_id'] ?? ''));
        if ($terminalId === '') {
            throw new Payment_Exception('Fonepay terminal ID is not configured.');
        }

        $referenceLabel = $this->buildReferenceLabel($invoice);
        $billId = substr('INV' . $invoice->serie . $invoice->nr, 0, 25);

        $qr = $this->generateIntentQr($amount, $billId, $terminalId, $referenceLabel);
        $qrString = (string) ($qr['qrMessage'] ?? $qr['qrString'] ?? '');
        if ($qrString === '') {
            throw new Payment_Exception('Fonepay did not return a QR payload.');
        }

        $this->storePaymentIntent($referenceLabel, (int) $invoice->id, $amount, $terminalId, (string) ($qr['prn'] ?? $referenceLabel));

        $qrImg = $this->renderQrDataUri($qrString);
        $websocketUrl = (string) ($qr['websocketId'] ?? $qr['thirdpartyQRWebSocketUrl'] ?? '');

        // redirect_url already carries gateway_id, invoice_id, invoice_hash & redirect=1.
        // Appending the reference lets processTransaction() resolve the intent and verify
        // against the authoritative status API; ipn.php then redirects to the paid invoice.
        $callbackUrl = ($this->config['redirect_url'] ?? '') . '&reference_label=' . urlencode($referenceLabel);

        $amountHtml = number_format($amount, 2);
        $refHtml = htmlspecialchars($referenceLabel, ENT_QUOTES, 'UTF-8');
        $qrImgHtml = htmlspecialchars($qrImg, ENT_QUOTES, 'UTF-8');
        $callbackJs = json_encode($callbackUrl);
        $wsJs = json_encode($websocketUrl);

        return <<<HTML
            <div id="fonepay-block" style="text-align:center;padding:16px 0;font-family:inherit;">
                <p style="margin:0 0 12px;color:#6b7280;font-size:14px;">Scan the QR below with any Fonepay-enabled mobile banking or wallet app to pay <strong>NPR {$amountHtml}</strong>.</p>
                <img src="{$qrImgHtml}" alt="Fonepay QR" style="width:280px;max-width:100%;height:auto;border:1px solid #eee;border-radius:12px;padding:8px;background:#fff;" />
                <p style="margin:10px 0 4px;font-size:12px;color:#9ca3af;">Reference: {$refHtml}</p>
                <div id="fonepay-status" style="margin-top:12px;font-size:14px;color:#6b7280;">Waiting for payment…</div>
                <button type="button" id="fonepay-check" style="margin-top:12px;display:inline-flex;align-items:center;gap:8px;padding:10px 24px;background:#CC0001;color:#fff;border:0;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;">I have paid — check status</button>
                <script>
                (function(){
                    var callbackUrl = {$callbackJs};
                    var wsUrl = {$wsJs};
                    var statusEl = document.getElementById('fonepay-status');
                    var btn = document.getElementById('fonepay-check');
                    var done = false;
                    // Navigate through ipn.php: it re-verifies with the Fonepay status API,
                    // settles the invoice, then redirects to the paid invoice page.
                    function settle(){ if (done) { return; } done = true; (window.top || window).location.href = callbackUrl; }

                    // Real-time: connect to the Fonepay QR WebSocket and settle the moment the
                    // payment completes. The browser signal is NEVER trusted on its own — the
                    // server re-verifies via the status API before crediting.
                    if (wsUrl && wsUrl.slice(0, 6) === 'wss://' && ('WebSocket' in window)) {
                        try {
                            var ws = new WebSocket(wsUrl);
                            ws.onmessage = function(ev){
                                try {
                                    var msg = JSON.parse(ev.data);
                                    var ts = msg.transactionStatus;
                                    if (typeof ts === 'string') { ts = JSON.parse(ts); }
                                    if (ts && (ts.paymentSuccess === true || ts.paymentSuccess === 'true')) {
                                        statusEl.textContent = 'Payment received — finalising…';
                                        setTimeout(settle, 1200); // let the acquirer settle before we verify
                                    } else if (ts && (ts.QRVerified === true || ts.QRVerified === 'true' || ts.message === 'VERIFIED')) {
                                        statusEl.textContent = 'QR scanned — approve the payment in your bank app…';
                                    }
                                } catch (e) {}
                            };
                            ws.onerror = function(){ statusEl.textContent = 'Live updates unavailable — tap the button below after you pay.'; };
                        } catch (e) {}
                    }
                    btn.addEventListener('click', settle);
                })();
                </script>
            </div>
            HTML;
    }

    private function renderQrDataUri(string $payload): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'svgWidth' => 280,
            'addQuietzone' => true,
        ]);

        return (new QRCode($options))->render($payload);
    }

    private function buildReferenceLabel(Model_Invoice $invoice): string
    {
        // Alphanumeric only, <= 30 chars, unique per attempt (Fonepay requirement).
        $suffix = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));

        return substr('INV' . (int) $invoice->id . 'R' . $suffix, 0, 30);
    }

    // -------------------------------------------------------------------------
    // Fonepay API calls
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function generateIntentQr(float $amount, string $billId, string $terminalId, string $referenceLabel): array
    {
        $body = json_encode([
            'amount' => $amount,
            'billId' => $billId,
            'terminalId' => $terminalId,
            'paymentMode' => 'QR',
            'referenceLabel' => $referenceLabel,
            'qrType' => 'INTENT_QR',
        ], JSON_UNESCAPED_SLASHES);

        [$status, $json, $raw] = $this->signedRequest('POST', self::API_PATH . '/generate-intent-qr', $body);

        if ($status !== 200 || !is_array($json) || empty($json['qrString'] ?? $json['qrMessage'] ?? null)) {
            $msg = is_array($json) ? $this->extractError($json) : 'HTTP ' . $status;
            $this->log('Fonepay generate-intent-qr failed [' . $status . ']: ' . $msg . ' | ' . mb_substr($raw, 0, 400), 'error');

            throw new Payment_Exception('Fonepay: could not create payment QR. ' . $msg);
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function getPaymentStatus(string $terminalId, string $referenceLabel): array
    {
        $body = json_encode(['terminalId' => $terminalId, 'referenceLabel' => $referenceLabel], JSON_UNESCAPED_SLASHES);

        [$status, $json, $raw] = $this->signedRequest('POST', self::API_PATH . '/thirdPartyDynamicQrGetStatus', $body);

        if (!is_array($json)) {
            throw new Payment_Exception('Fonepay: status API returned an unexpected response (HTTP ' . $status . ').');
        }

        return $json;
    }

    /**
     * Perform a Bearer + signature authenticated request.
     *
     * @return array{0:int,1:array<string,mixed>|null,2:string} [statusCode, decodedJson|null, rawBody]
     */
    private function signedRequest(string $method, string $path, string $body): array
    {
        $token = $this->login();
        $url = $this->getBaseUrl() . $path;

        try {
            $response = $this->getHttpClient()->request($method, $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => $this->bearer($token),
                    'signature' => $this->sign($body),
                ],
                'body' => $body,
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (Exception $e) {
            throw new Payment_Exception('Fonepay: request to ' . $path . ' failed — ' . $e->getMessage());
        }

        return [$status, json_decode($raw, true), $raw];
    }

    /**
     * oAuth login — returns a JWT (with "Bearer " prefix), cached until shortly before expiry.
     */
    private function login(): string
    {
        $cached = $this->loadToken();
        if ($cached !== null) {
            return $cached;
        }

        $username = (string) ($this->config['merchant_username'] ?? '');
        $password = (string) ($this->config['merchant_password'] ?? '');
        if ($username === '' || $password === '') {
            throw new Payment_Exception('Fonepay merchant username/password is not configured.');
        }

        $body = json_encode(['username' => $username, 'password' => $password], JSON_UNESCAPED_SLASHES);

        try {
            $response = $this->getHttpClient()->request('POST', $this->getBaseUrl() . self::LOGIN_PATH, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
                    'signature' => $this->sign($body),
                ],
                'body' => $body,
            ]);
            $status = $response->getStatusCode();
            $json = json_decode($response->getContent(false), true);
        } catch (Exception $e) {
            throw new Payment_Exception('Fonepay: login request failed — ' . $e->getMessage());
        }

        if ($status !== 200 || !is_array($json) || empty($json['accessToken'])) {
            $msg = is_array($json) ? $this->extractError($json) : 'HTTP ' . $status;

            throw new Payment_Exception('Fonepay: login failed. ' . $msg);
        }

        $token = (string) $json['accessToken'];
        $ttl = max(60, (int) ($json['expiresIn'] ?? 3600) - 60);
        $this->storeToken($token, $ttl);

        return $token;
    }

    private function bearer(string $token): string
    {
        return str_starts_with($token, 'Bearer ') ? $token : 'Bearer ' . $token;
    }

    /**
     * Sign the EXACT request body with the merchant PKCS8 private key (RSA-SHA256, Base64).
     * The signed bytes must equal the bytes sent as the request body.
     */
    private function sign(string $body): string
    {
        $pem = $this->getPrivateKeyPem();
        $signature = '';
        if (!openssl_sign($body, $signature, $pem, OPENSSL_ALGO_SHA256)) {
            throw new Payment_Exception('Fonepay: failed to sign the request. Check the merchant private key.');
        }

        return base64_encode($signature);
    }

    private function getPrivateKeyPem(): string
    {
        $raw = trim((string) ($this->config['private_key'] ?? ''));
        if ($raw === '') {
            throw new Payment_Exception('Fonepay merchant private key is not configured.');
        }
        if (str_contains($raw, 'BEGIN')) {
            return $raw;
        }

        $b64 = preg_replace('/\s+/', '', $raw) ?? '';

        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split($b64, 64, "\n") . "-----END PRIVATE KEY-----\n";
    }

    private function getBaseUrl(): string
    {
        $override = trim((string) ($this->config['api_base_url'] ?? ''));
        if ($override !== '') {
            return rtrim($override, '/');
        }

        return ((bool) ($this->config['test_mode'] ?? false)) ? self::SANDBOX_BASE : self::PRODUCTION_BASE;
    }

    /** @param array<string, mixed> $response */
    private function extractError(array $response): string
    {
        $msg = $response['message'] ?? null;
        if (is_string($msg)) {
            return $msg;
        }
        if (is_array($msg)) {
            $parts = [];
            foreach ($msg as $field => $err) {
                $parts[] = $field . ': ' . (is_array($err) ? implode(', ', array_map(strval(...), $err)) : (string) $err);
            }

            return implode('. ', $parts);
        }

        return isset($response['status']) ? 'status ' . $response['status'] : 'Unexpected Fonepay API response.';
    }

    // -------------------------------------------------------------------------
    // Currency conversion (ported from the Khalti adapter)
    // -------------------------------------------------------------------------

    private function getAmountInNpr(Model_Invoice $invoice): float
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $total = (float) $invoiceService->getTotalWithTax($invoice);

        return $this->convertToNpr($invoice->currency ?? 'NPR', $total);
    }

    private function convertToNpr(string $currency, float $amount): float
    {
        if (strtoupper($currency) === 'NPR') {
            return $amount;
        }

        $currencyService = $this->di['mod_service']('currency');
        $repo = $currencyService->getCurrencyRepository();
        $defaultCurrency = $repo->findDefault();
        $baseCurrency = $defaultCurrency ? strtoupper((string) $defaultCurrency->getCode()) : 'NPR';

        try {
            $amountInBase = $currencyService->toBaseCurrency($currency, $amount);
        } catch (FOSSBilling\Exception $e) {
            throw new Payment_Exception('Fonepay: cannot convert ' . strtoupper($currency) . ' to NPR — ' . $e->getMessage());
        }

        if ($baseCurrency === 'NPR') {
            return $amountInBase;
        }

        $manualRate = isset($this->config['manual_npr_rate']) ? (float) $this->config['manual_npr_rate'] : 0.0;
        if ($manualRate > 0) {
            return $amountInBase * $manualRate;
        }

        $nprRate = $repo->getRateByCode('NPR');
        if ($nprRate === null || $nprRate <= 0) {
            throw new Payment_Exception('Fonepay: NPR exchange rate is not configured. Set a manual rate in the Fonepay settings or add NPR under Admin → System → Currencies.');
        }

        return $amountInBase * $nprRate;
    }

    // -------------------------------------------------------------------------
    // Infra: HTTP client, token + intent cache, logging
    // -------------------------------------------------------------------------

    public function getHttpClient(): Symfony\Contracts\HttpClient\HttpClientInterface
    {
        if ($this->httpClient === null) {
            $this->httpClient = Symfony\Component\HttpClient\HttpClient::create(['bindto' => BIND_TO]);
        }

        return $this->httpClient;
    }

    private function storeToken(string $token, int $ttl): void
    {
        try {
            $cache = $this->getIntentCache();
            $item = $cache->getItem($this->tokenKey());
            $item->set($token);
            $item->expiresAfter($ttl);
            $cache->save($item);
        } catch (Throwable) {
        }
    }

    private function loadToken(): ?string
    {
        try {
            $item = $this->getIntentCache()->getItem($this->tokenKey());
            if ($item->isHit()) {
                return (string) $item->get();
            }
        } catch (Throwable) {
        }

        return null;
    }

    private function tokenKey(): string
    {
        return 'token_' . hash('sha256', (string) ($this->config['merchant_username'] ?? '') . '|' . $this->getBaseUrl());
    }

    private function storePaymentIntent(string $ref, int $invoiceId, float $expectedAmount, string $terminalId, string $prn, int $ttl = 3600): void
    {
        try {
            $cache = $this->getIntentCache();
            $item = $cache->getItem($this->intentKey($ref));
            $item->set([
                'invoice_id' => $invoiceId,
                'expected_amount' => $expectedAmount,
                'terminal_id' => $terminalId,
                'prn' => $prn,
                'created_at' => time(),
            ]);
            $item->expiresAfter($ttl);
            $cache->save($item);
        } catch (Throwable $e) {
            $this->log('Fonepay: failed to store intent for ' . $ref . ': ' . $e->getMessage(), 'warn');
        }
    }

    /** @return array<string, mixed>|null */
    private function loadPaymentIntent(string $ref): ?array
    {
        try {
            $item = $this->getIntentCache()->getItem($this->intentKey($ref));
            if ($item->isHit()) {
                return $item->get();
            }
        } catch (Throwable) {
        }

        return null;
    }

    private function deletePaymentIntent(string $ref): void
    {
        try {
            $this->getIntentCache()->deleteItem($this->intentKey($ref));
        } catch (Throwable) {
        }
    }

    private function getIntentCache(): Symfony\Component\Cache\Adapter\AdapterInterface
    {
        if ($this->intentCache === null) {
            $dataPath = defined('BB_PATH_DATA') ? BB_PATH_DATA : sys_get_temp_dir();
            $this->intentCache = new Symfony\Component\Cache\Adapter\FilesystemAdapter('fonepay', 7200, $dataPath . '/cache');
        }

        return $this->intentCache;
    }

    private function intentKey(string $ref): string
    {
        return 'intent_' . hash('sha256', $ref);
    }

    private function log(string $message, string $level = 'info'): void
    {
        try {
            $logger = $this->di['logger'];
            match ($level) {
                'error' => $logger->err($message),
                'warn' => $logger->warn($message),
                default => $logger->info($message),
            };
        } catch (Throwable) {
            error_log($message);
        }
    }
}
