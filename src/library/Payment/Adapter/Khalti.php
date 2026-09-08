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
                        'required_when' => ['enabled' => true, 'test_mode' => false],
                    ],
                ],
                'test_secret_key' => [
                    'text',
                    [
                        'label' => 'Test / Sandbox Secret Key',
                        'required' => false,
                        'hint' => 'Found under Settings > API in your Khalti test dashboard (test-admin.khalti.com).',
                        'required_when' => ['enabled' => true, 'test_mode' => true],
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
                        'hint' => 'NPR for 1 unit of your default currency — or, when NPR IS your default currency, NPR for 1 unit of the invoice currency (e.g. enter 135 if 1 USD = 135 NPR). Overrides the currency table. Leave blank to use the currency table rate.',
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

        $pidx = trim((string) ($params['pidx'] ?? ''));

        /** @var Model_Transaction $tx */
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);

        if ($pidx === '') {
            $tx->txn_status = 'failed';
            $tx->error = 'Missing pidx in callback — cannot verify with Khalti.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('Khalti: Missing pidx in callback.');
        }

        // === STEP 1: Call the Lookup API first — the authoritative source for the payment's
        // status, amount, transaction id and refund flag. Browser-supplied values are never trusted
        // for these.
        //
        // IMPORTANT: Khalti's Lookup API returns ONLY pidx, total_amount, status, transaction_id,
        // fee and refunded. It does NOT return purchase_order_id / purchase_order_name — those come
        // back only in (a) the server-side intent snapshot we wrote at initiation, and (b) the
        // browser return-URL params. Reading purchase_order_id from the Lookup response (as this
        // adapter previously did) always yielded null, so the order-binding gate below rejected
        // EVERY completed payment ("Lookup API did not return purchase_order_id") and no invoice was
        // ever credited. The order binding is now established from the intent snapshot instead.
        $lookupResult = $this->lookupPayment($pidx);
        $verifiedStatus = $lookupResult['status'] ?? 'Unknown';
        $verifiedAmount = (int) ($lookupResult['total_amount'] ?? 0);
        $verifiedTxnId = $lookupResult['transaction_id'] ?? null;
        $verifiedFee = (int) ($lookupResult['fee'] ?? 0);
        $isRefunded = (bool) ($lookupResult['refunded'] ?? false);

        // === STEP 2: Resolve invoice + establish the order binding ===
        //
        // ipn.php redirects the client to /invoice/{hash} using the invoice resolved here, so the
        // hash must NEVER derive from an attacker-controllable value. $invoiceIdVerified is set ONLY
        // when the invoice came from the server-side intent snapshot (Priority 1); it alone gates the
        // hash hydration used for the redirect, so a crafted callback (valid pidx + a guessed
        // purchase_order_id, on a cold intent) can never turn the redirect into an invoice-hash
        // disclosure oracle. Crediting, by contrast, is gated by the strict amount check further down.
        //
        // The intent snapshot is the authoritative pidx→invoice binding: we wrote it at initiation
        // keyed on the unguessable, Khalti-issued pidx, and it also stores the purchase_order_id we
        // sent to Khalti.
        $intent = $this->loadPaymentIntent($pidx);

        // Order id: prefer the server-side intent snapshot. The callback param is
        // attacker-controllable and used only as a cold-intent fallback to resolve the invoice —
        // where the amount recompute in the crediting section is the real protection.
        $intentOrderId = $intent['purchase_order_id'] ?? null;
        $callbackOrderId = trim((string) ($params['purchase_order_id'] ?? '')) ?: null;
        $verifiedOrderId = $intentOrderId ?? $callbackOrderId;

        // Priority 1: intent cache (written server-side at initiation — most reliable).
        $invoiceId = $intent['invoice_id'] ?? null;
        $invoiceIdVerified = $invoiceId !== null;

        // Priority 2: decode from the purchase_order_id when the intent is cold (eviction / server
        // restart). With a cold intent $verifiedOrderId comes from the browser callback, so it is
        // NOT authoritative on its own — the strict amount check before crediting is what ties the
        // Lookup-verified payment to this invoice. Not marked verified: no hash hydration/redirect.
        if ($invoiceId === null && $verifiedOrderId !== null) {
            $invoiceId = $this->decodeInvoiceIdFromOrderId($verifiedOrderId);
            if ($invoiceId !== null) {
                $this->log('Khalti: Decoded invoice_id=' . $invoiceId . ' from purchase_order_id=' . $verifiedOrderId . ' (cold intent).', 'warn');
            }
        }

        // Priority 3: transaction record — set by ipn.php from the URL invoice_id, so it is
        // attacker-controllable. Trust it as a last resort ONLY when it matches the callback
        // purchase_order_id; otherwise refuse it (leaving $invoiceId null → generic "cannot
        // identify" below). Not marked verified: no hash hydration/redirect off this path, and
        // crediting still runs through the strict amount check.
        if ($invoiceId === null && ($tx->invoice_id ?? null)) {
            $candidateId = (int) $tx->invoice_id;
            $candidate = $this->di['db']->load('Invoice', $candidateId);
            if ($candidate && $verifiedOrderId !== null
                && substr('INV-' . $candidate->serie . sprintf('%05d', $candidate->nr), 0, 64) === $verifiedOrderId) {
                $invoiceId = $candidateId;
                $this->log('Khalti: Intent cache cold for pidx=' . $pidx . '. Using tx->invoice_id (matches callback purchase_order_id).', 'warn');
            } else {
                $this->log('Khalti SECURITY: URL invoice_id=' . $candidateId . ' does not match purchase_order_id=' . ($verifiedOrderId ?? 'null') . ' — refusing to trust it. pidx=' . $pidx, 'error');
            }
        }

        // Hydrate invoice_hash so ipn.php can redirect the client after processing — ONLY from a
        // server-verified invoice id (never the raw URL), so the redirect cannot leak another
        // client's invoice hash. Set on both $_GET and the DI request object: the Symfony Request
        // was already constructed before processTransaction() runs, so $_GET alone would be missed
        // by $request->query. Setting both keeps compatibility.
        if ($invoiceId && $invoiceIdVerified) {
            $inv = $this->di['db']->load('Invoice', $invoiceId);
            if ($inv) {
                $_GET['invoice_hash'] = $inv->hash;
                $this->di['request']->query->set('invoice_hash', $inv->hash);
            }
        }

        if (!$invoiceId) {
            $tx->txn_status = 'failed';
            $tx->error = 'Cannot identify invoice for pidx=' . $pidx . '. purchase_order_id=' . ($verifiedOrderId ?? 'null') . ' (intent snapshot cold and no decodable callback order id).';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $this->log('Khalti: Could not resolve invoice for pidx=' . $pidx, 'error');

            throw new Payment_Exception('Khalti: Cannot identify invoice — intent cache cold and purchase_order_id not decodable.');
        }

        // Status gate FIRST — mirrors the official Stripe adapter (status checked before amount;
        // validatePaymentAmount only runs for a succeeded payment). Khalti's Lookup returns
        // total_amount=0 for a canceled/pending payment (e.g. the user abandons the Mobile Banking /
        // connectIPS bank redirect), so running the amount check first would wrongly reject it as an
        // underpayment "amount mismatch" and flag the transaction, instead of cleanly recording the
        // cancel/pending and redirecting the user back to the invoice. Only Completed proceeds below.
        if ($verifiedStatus !== 'Completed') {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $verifiedTxnId ?? $pidx;
            $tx->amount = $verifiedAmount / 100;
            $tx->currency = 'NPR';
            $tx->updated_at = date('Y-m-d H:i:s');

            if (in_array($verifiedStatus, ['Pending', 'Initiated'], true)) {
                // Transaction is in progress — keep the intent alive
                $tx->txn_status = 'pending';
                $tx->error = 'Khalti payment is ' . $verifiedStatus . '. Awaiting completion.';
                $tx->status = 'pending';
            } else {
                // User canceled / Expired / Refunded / Partially Refunded / Unknown
                $tx->txn_status = strtolower(str_replace(' ', '_', $verifiedStatus));
                $tx->error = 'Khalti payment status: ' . $verifiedStatus . '. Payment not completed.';
                $tx->status = 'error';
                if ($pidx) {
                    $this->deletePaymentIntent($pidx);
                }
            }

            $this->di['db']->store($tx);

            return;
        }

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

        // Enforce the amount only against the snapshot taken at initiation — the exact NPR paisa
        // we asked Khalti to charge. Never recompute the conversion at callback time: the USD→NPR
        // rate may have drifted since initiation, and getAmountInPaisa() can even throw when a rate
        // is mis/unconfigured — either of which would wrongly reject (or crash) an already-paid
        // callback. When the snapshot is gone (intent expired/evicted), Khalti's Lookup API has
        // already verified the payment and the purchase_order_id binding below ties it to this
        // invoice, so trust the Lookup-verified total_amount rather than hard-fail on a guess.
        $expectedAmountPaisa = $intent['expected_paisa'] ?? null;

        if ($expectedAmountPaisa !== null) {
            $this->log(sprintf(
                'Khalti amount check: invoice_id=%s, expected=%d paisa, received=%d paisa',
                $invoiceId,
                $expectedAmountPaisa,
                $verifiedAmount
            ));

            // Delegate to the core validator used by the official Stripe/PayPalEmail adapters:
            // it rejects underpayment (0.01 tolerance == 1 paisa) and only warns on overpayment,
            // so a slightly-over or misdirected payment is flagged but still provisions the order.
            // Both amounts are NPR here (gateway currency == the snapshot currency).
            try {
                $this->di['mod_service']('Invoice')->validatePaymentAmount($verifiedAmount / 100, (int) $expectedAmountPaisa / 100);
            } catch (FOSSBilling\Exception $e) {
                $tx->invoice_id = (int) $invoiceId;
                $tx->txn_id = $verifiedTxnId ?? $pidx;
                $tx->txn_status = 'failed';
                $tx->amount = $verifiedAmount / 100;
                $tx->currency = 'NPR';
                $tx->error = 'SECURITY: ' . $e->getMessage() . ' (invoice_id=' . $invoiceId . ')';
                $tx->status = 'error';
                $tx->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($tx);
                $this->log('Khalti SECURITY: Amount mismatch for invoice #' . $invoiceId, 'error');

                throw new Payment_Exception('Khalti: Payment amount does not match invoice total. This transaction has been flagged.');
            }
        } else {
            // Intent snapshot is cold (expired/evicted, or the reconciliation cron re-verifying an
            // abandoned payment). We lost the exact paisa we asked Khalti to charge, so RE-DERIVE it
            // rather than fail open. getAmountInPaisa() is conversion-free/exact for NPR invoices but
            // can throw (unconfigured rate) or drift for converted invoices — handle each explicitly.
            try {
                $recomputedPaisa = $this->getAmountInPaisa($invoice);
            } catch (Exception $e) {
                // Cannot establish ANY expected amount (e.g. the invoice currency's rate is now
                // unconfigured). Do not trust the gateway-reported total — hold for operator review.
                $tx->invoice_id = (int) $invoiceId;
                $tx->txn_id = $verifiedTxnId ?? $pidx;
                $tx->txn_status = 'failed';
                $tx->amount = $verifiedAmount / 100;
                $tx->currency = 'NPR';
                $tx->error = 'SECURITY: intent snapshot cold and amount could not be recomputed for verification (' . $e->getMessage() . '). Held for operator review. invoice_id=' . $invoiceId;
                $tx->status = 'error';
                $tx->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($tx);
                $this->log('Khalti SECURITY: cold intent + recompute failed for invoice #' . $invoiceId . ' pidx=' . $pidx . ' — held for review.', 'error');

                throw new Payment_Exception('Khalti: Payment received but the amount could not be verified. It has been flagged for manual review.');
            }

            if (strtoupper((string) ($invoice->currency ?? 'NPR')) === 'NPR') {
                // NPR invoice: recompute is exact (no FX), so enforce it exactly like the warm path.
                $this->log(sprintf(
                    'Khalti amount check (cold intent, NPR): invoice_id=%s, recomputed=%d paisa, received=%d paisa',
                    $invoiceId,
                    $recomputedPaisa,
                    $verifiedAmount
                ), 'warn');

                try {
                    $this->di['mod_service']('Invoice')->validatePaymentAmount($verifiedAmount / 100, (int) $recomputedPaisa / 100);
                } catch (FOSSBilling\Exception $e) {
                    $tx->invoice_id = (int) $invoiceId;
                    $tx->txn_id = $verifiedTxnId ?? $pidx;
                    $tx->txn_status = 'failed';
                    $tx->amount = $verifiedAmount / 100;
                    $tx->currency = 'NPR';
                    $tx->error = 'SECURITY: ' . $e->getMessage() . ' (cold intent, invoice_id=' . $invoiceId . ')';
                    $tx->status = 'error';
                    $tx->updated_at = date('Y-m-d H:i:s');
                    $this->di['db']->store($tx);
                    $this->log('Khalti SECURITY: Amount mismatch (cold intent) for invoice #' . $invoiceId, 'error');

                    throw new Payment_Exception('Khalti: Payment amount does not match invoice total. This transaction has been flagged.');
                }
            } else {
                // Converted (non-NPR) invoice: the USD→NPR rate may have legitimately drifted between
                // initiation and this callback, so a strict compare against the recompute would falsely
                // reject a real payment. Trust Khalti's server-verified total (the purchase_order_id
                // binding below still ties it to THIS invoice), but log the recomputed reference so an
                // operator can reconcile any material discrepancy.
                $this->log(sprintf(
                    'Khalti: intent cold for converted invoice_id=%s pidx=%s — recomputed≈%d paisa vs Lookup-verified total=%d paisa; trusting Lookup total (rate may have drifted). Flagged for review.',
                    $invoiceId,
                    $pidx,
                    $recomputedPaisa,
                    $verifiedAmount
                ), 'warn');
            }
        }

        // Status = Completed but Khalti flagged it as refunded — do not credit
        if ($isRefunded) {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $verifiedTxnId ?? $pidx;
            $tx->txn_status = 'refunded';
            $tx->amount = $verifiedAmount / 100;
            $tx->currency = 'NPR';
            $tx->error = 'Khalti reports transaction as refunded. Service not provisioned.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $this->log('Khalti: Refunded transaction received for invoice #' . $invoiceId . '. pidx=' . $pidx, 'warn');

            if ($pidx) {
                $this->deletePaymentIntent($pidx);
            }

            return;
        }

        if ($tx->status === 'processed') {
            $this->log('Khalti: Callback for already-processed tx #' . $id . '. Skipping. pidx=' . $pidx);

            return;
        }

        // $verifiedOrderId is the purchase_order_id from the intent snapshot (warm) or the browser
        // callback (cold) — NOT the Lookup API, which does not return it. Must match what we sent
        // during initiation — same truncation applied. For the warm path this genuinely confirms the
        // intent's order == the resolved invoice; for the cold path (invoice decoded from the same
        // callback order id) it is satisfied by construction and the amount check above is the guard.
        $expectedOrderId = substr('INV-' . $invoice->serie . sprintf('%05d', $invoice->nr), 0, 64);

        if ($verifiedOrderId === null) {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $verifiedTxnId ?? $pidx;
            $tx->txn_status = 'failed';
            $tx->amount = $verifiedAmount / 100;
            $tx->currency = 'NPR';
            $tx->error = 'Security: no purchase_order_id available (intent snapshot cold and none in callback). Cannot verify order binding.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $this->log('Khalti SECURITY: no purchase_order_id from intent or callback. pidx=' . $pidx, 'error');

            throw new Payment_Exception('Khalti: Cannot verify order identity — purchase_order_id unavailable.');
        }

        if ($verifiedOrderId !== $expectedOrderId) {
            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $verifiedTxnId ?? $pidx;
            $tx->txn_status = 'failed';
            $tx->amount = $verifiedAmount / 100;
            $tx->currency = 'NPR';
            $tx->error = 'Security: purchase_order_id mismatch. Expected ' . $expectedOrderId . ', got ' . $verifiedOrderId . '.';
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $this->log('Khalti SECURITY: Order ID mismatch for invoice #' . $invoiceId . '. pidx=' . $pidx, 'error');

            throw new Payment_Exception('Khalti: Payment was made for a different order. This transaction has been flagged.');
        }

        // Serialize concurrent callbacks for the SAME Khalti payment with a DB advisory lock —
        // mirrors the official Stripe adapter's GET_LOCK on the charge id. Without it, two
        // concurrent replays of the return_url create SEPARATE transaction rows (createAndProcess
        // dispenses a new row per callback), both pass the earlier duplicate-txn_id check before
        // either commits 'processed', and both credit the client → double-credit (an attacker can
        // parallel-replay one completed payment to be credited multiple times). The lock makes the
        // loser wait, then the in-lock re-check below sees the winner's processed tx and skips.
        $lockKey = $verifiedTxnId ?: $pidx;
        $lockName = 'fb:khalti:' . substr(hash('sha256', $gateway_id . ':' . $lockKey), 0, 54);
        if ((int) $this->di['dbal']->fetchOne('SELECT GET_LOCK(:l, 10)', ['l' => $lockName]) !== 1) {
            $this->log('Khalti: timed out acquiring payment lock for ' . $lockKey . ' pidx=' . $pidx, 'warn');

            throw new Payment_Exception('Khalti: Timed out while processing this payment. Please try again.');
        }

        try {
            // Authoritative duplicate re-check UNDER THE LOCK — a concurrent callback that won the
            // race has already credited this payment (same Khalti txn_id) and set its tx 'processed'.
            if ($verifiedTxnId) {
                $dupe = $this->di['db']->findOne('Transaction', 'txn_id = ? AND status = ? AND id != ?', [$verifiedTxnId, 'processed', $id]);
                if ($dupe) {
                    $tx->invoice_id = (int) $invoiceId;
                    $tx->txn_id = $verifiedTxnId;
                    $tx->txn_status = 'duplicate';
                    $tx->amount = $verifiedAmount / 100;
                    $tx->currency = 'NPR';
                    $tx->error = 'Duplicate concurrent callback — payment already processed in tx #' . $dupe->id . '.';
                    $tx->status = 'error';
                    $tx->updated_at = date('Y-m-d H:i:s');
                    $this->di['db']->store($tx);
                    $this->log('Khalti: concurrent duplicate for txn=' . $verifiedTxnId . ' already in tx #' . $dupe->id . '. Skipping credit.', 'warn');

                    return;
                }
            }

            // Atomically claim this tx row before crediting (row-level state machine; with the
            // lock above this is belt-and-suspenders). Another worker holding it returns early.
            $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
            if (!$transactionService->claimForProcessing((int) $tx->id)) {
                $this->log('Khalti: tx #' . $id . ' already claimed for processing. pidx=' . $pidx, 'warn');

                return;
            }

            $tx->invoice_id = (int) $invoiceId;
            $tx->txn_id = $verifiedTxnId ?? $pidx;
            $tx->txn_status = 'complete';
            $tx->amount = $verifiedAmount / 100;
            $tx->currency = 'NPR';
            if ($verifiedFee > 0) {
                $tx->error = 'Khalti fee: NPR ' . number_format($verifiedFee / 100, 2);
            }

            $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
            $invoiceService = $this->di['mod_service']('Invoice');
            $isDepositInvoice = $invoiceService->isInvoiceTypeDeposit($invoice);

            // Credit the invoice total in the invoice's own currency, not the NPR
            // tx->amount. FOSSBilling's client balance is currency-unaware: crediting 1350
            // NPR against a $10 USD invoice would leave 1340 phantom credits in the account.
            $invoiceTotal = (float) $invoiceService->getTotalWithTax($invoice);
            $clientService = $this->di['mod_service']('client');
            $clientService->addFunds($client, $invoiceTotal, 'Khalti payment — txn: ' . $tx->txn_id, [
                'amount' => $invoiceTotal,
                'description' => 'Khalti payment — txn: ' . $tx->txn_id,
                'type' => 'transaction',
                'rel_id' => $tx->id,
            ]);

            // Approve the invoice first. payInvoiceWithCredits() -> tryPayWithCredits()
            // returns false and does nothing on an UNAPPROVED invoice, which would leave
            // the client credited but the invoice unpaid and the order unprovisioned.
            // Mirrors the official Stripe/PayPalEmail adapters.
            if (!$invoice->approved) {
                $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
            }

            if ($isDepositInvoice) {
                // Deposit ("add funds") invoice — markAsPaid records it properly
                // (approved flag, paid serie, item marking) instead of a raw status write.
                // Do NOT auto-settle pending invoices from the new balance here: a top-up
                // should only add funds, matching the official Stripe adapter (which calls
                // markAsPaid alone for deposits). The client's balance is applied to open
                // invoices through the normal payment flow / cron, not as a side effect.
                $invoiceService->markAsPaid($invoice);
            } else {
                $invoiceService->payInvoiceWithCredits($invoice);
                $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
            }

            $tx->status = 'processed';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            if ($pidx) {
                $this->deletePaymentIntent($pidx);
            }
        } catch (Exception $e) {
            $tx->error = 'Post-payment processing error: ' . $e->getMessage();
            $tx->status = 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('Khalti: ' . $e->getMessage());
        } finally {
            // Always release the advisory lock — success, early-return (dup/claim), or exception.
            $this->di['dbal']->fetchOne('SELECT RELEASE_LOCK(:l)', ['l' => $lockName]);
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

        // SYSTEM_URL is built in load.php with the correct scheme (https:// or http://).
        // getParamValue('url') returns the raw config value which has no scheme, so
        // Khalti's API rejects it (HTTP 500 on their URL validator). Use SYSTEM_URL.
        $websiteUrl = defined('SYSTEM_URL') ? rtrim((string) SYSTEM_URL, '/') : '';
        if ($websiteUrl === '') {
            $systemService = $this->di['mod_service']('System');
            $websiteUrl = rtrim((string) ($systemService->getParamValue('url') ?: ''), '/');
        }

        // Khalti's live API returns HTTP 500 (empty body) when return_url or website_url
        // contains a non-public host (localhost, 127.x, LAN IPs). Detect this early and
        // show a clear error instead of a cryptic Khalti failure.
        $parsedSite = parse_url($websiteUrl);
        $siteHost = strtolower($parsedSite['host'] ?? '');
        $isLocalHost = $siteHost === 'localhost'
            || $siteHost === '127.0.0.1'
            || $siteHost === '::1'
            || preg_match('/^192\.168\.|^10\.|^172\.(1[6-9]|2\d|3[01])\./', $siteHost);

        if ($isLocalHost) {
            return '<p style="color:#dc2626;font-weight:600;">Khalti requires a public domain.</p>'
                . '<p style="color:#6b7280;font-size:14px;">The site URL is set to <strong>' . htmlspecialchars($siteHost, ENT_QUOTES, 'UTF-8') . '</strong>, which is a local/private address. '
                . 'Khalti\'s API rejects local URLs and returns HTTP 500. '
                . 'To test locally, use <a href="https://ngrok.com" target="_blank">ngrok</a> and update the Site URL under <strong>Admin &rarr; Settings &rarr; General</strong>. '
                . 'On the live server this will work automatically once the correct domain is set.</p>';
        }

        // Keep return_url short — Khalti enforces a 255-char limit and returns HTTP 500
        // (empty body) when exceeded. invoice_hash is NOT included here; it is injected
        // into $_GET inside processTransaction() after the payment intent is resolved.
        $restoreToken = FOSSBilling\Tools::createSessionRestoreToken(session_id());
        $returnUrl = ($this->config['notify_url'] ?? '') . '&redirect=1&restore_token=' . urlencode($restoreToken);

        $invoiceTitle = $this->buildInvoiceTitle($invoice);
        $customerInfo = $this->buildCustomerInfo($invoice);

        $purchaseOrderId = substr('INV-' . $invoice->serie . sprintf('%05d', $invoice->nr), 0, 64);
        $purchaseOrderName = mb_substr($invoiceTitle, 0, 100);

        $payload = [
            'return_url' => $returnUrl,
            'website_url' => $websiteUrl ?: 'https://example.com',
            'amount' => $amountPaisa,
            'purchase_order_id' => $purchaseOrderId,
            'purchase_order_name' => $purchaseOrderName,
        ];

        if (!empty($customerInfo)) {
            $payload['customer_info'] = $customerInfo;
        }

        $initiateUrl = $this->getApiBase() . '/epayment/initiate/';

        $this->log('Khalti initiate payload: ' . json_encode($payload));

        try {
            $response = $this->getHttpClient()->request('POST', $initiateUrl, [
                'headers' => [
                    'Authorization' => 'Key ' . $this->getSecretKey(),
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]);

            $statusCode = $response->getStatusCode();
            $rawContent = $response->getContent(false);
            $responseBody = json_decode($rawContent, true);
        } catch (Exception $e) {
            return '<p style="color:#dc2626;">Khalti: Failed to connect to payment gateway. ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if ($statusCode !== 200 || empty($responseBody['payment_url'])) {
            $errorMessage = $this->extractErrorMessage($responseBody ?? [], $statusCode);
            $this->log('Khalti initiate failed [HTTP ' . $statusCode . ']: ' . $errorMessage . ' | raw: ' . mb_substr($rawContent, 0, 500), 'error');

            return '<p style="color:#dc2626;">Khalti: Could not initiate payment. [HTTP ' . $statusCode . '] ' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $rawPaymentUrl = $responseBody['payment_url'];
        $rawPidx = $responseBody['pidx'] ?? '';
        // expires_in is in seconds; default to 1800 (30 min) per Khalti docs if not returned
        $expiresIn = isset($responseBody['expires_in']) ? max(60, (int) $responseBody['expires_in']) : 1800;

        // Validate payment_url is a genuine Khalti domain
        $allowedHosts = ['pay.khalti.com', 'test-pay.khalti.com'];
        $parsedUrl = parse_url((string) $rawPaymentUrl);
        $urlHost = $parsedUrl['host'] ?? '';
        $urlScheme = $parsedUrl['scheme'] ?? '';

        if ($urlScheme !== 'https' || !in_array($urlHost, $allowedHosts, true)) {
            $this->log('Khalti SECURITY: Unexpected payment_url domain: ' . $rawPaymentUrl, 'error');

            return '<p style="color:#dc2626;">Khalti: Payment URL failed security validation. Please contact support.</p>';
        }

        if (!empty($rawPidx)) {
            // Add buffer: store intent for 2× the link lifetime so callbacks arriving
            // just before expiry can still be verified
            $this->storePaymentIntent($rawPidx, (int) $invoice->id, $amountPaisa, $purchaseOrderId, $expiresIn * 2);
        }

        $paymentUrlHtml = htmlspecialchars((string) $rawPaymentUrl, ENT_QUOTES, 'UTF-8');
        $pidxHtml = htmlspecialchars((string) $rawPidx, ENT_QUOTES, 'UTF-8');
        $paymentUrlJs = json_encode($rawPaymentUrl);
        $expiryMinutes = (int) ceil($expiresIn / 60);

        $html = '<div id="khalti-payment-block" style="text-align:center;padding:24px 0;">';
        $html .= '<p style="margin-bottom:16px;color:#6c757d;">You will be redirected to Khalti to complete your payment.</p>';
        $html .= '<a href="' . $paymentUrlHtml . '" target="_top" id="khalti-pay-btn"';
        $html .= ' style="display:inline-flex;align-items:center;gap:10px;padding:12px 32px;background:#CC0001;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">';
        $html .= '<span style="letter-spacing:0.5px;">Pay with Khalti</span></a>';
        $html .= '<p style="margin-top:12px;font-size:12px;color:#aaa;">Payment link expires in ' . $expiryMinutes . ' minutes &mdash; Reference: ' . $pidxHtml . '</p>';
        // Use window.top so the redirect escapes the sandboxed gateway iframe in mod_invoice_banklink
        $html .= '<script>setTimeout(function(){ (window.top||window).location.href=' . $paymentUrlJs . '; }, 1500);</script>';

        return $html . '</div>';
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

        // HTTP 400 is expected for terminal non-success states (Expired, User canceled).
        // Khalti still returns a structured body with a `status` field for these — return it
        // so processTransaction can record the correct txn_status and clean up the intent.
        // Only throw when there is no usable `status` field (invalid pidx, auth error, etc.).
        if ($statusCode === 400) {
            if (isset($body['status'])) {
                return $body;
            }

            $detail = isset($body['detail']) ? (string) $body['detail'] : 'Unknown error';

            throw new Payment_Exception('Khalti Lookup API error (HTTP 400): ' . $detail);
        }

        // For 2xx responses, a `detail` field indicates an API-level error (rare).
        if (isset($body['detail'])) {
            throw new Payment_Exception('Khalti Lookup API error: ' . $body['detail']);
        }

        if ($statusCode !== 200) {
            throw new Payment_Exception('Khalti: Lookup API returned unexpected HTTP ' . $statusCode . '.');
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
            $info['email'] = mb_substr((string) $invoice->buyer_email, 0, 75);
        }

        if (!empty($invoice->buyer_phone)) {
            $phone = mb_substr((string) preg_replace('/[^0-9]/', '', (string) $invoice->buyer_phone), 0, 16);
            if (!empty($phone)) {
                $info['phone'] = $phone;
            }
        }

        return $info;
    }

    /**
     * Decode a database invoice ID from a purchase_order_id string.
     *
     * Format stored during initiation: INV-{serie}{nr_zero_padded_5} (truncated to 64 chars).
     * The nr occupies exactly the last 5 characters; serie is everything between "INV-" and nr.
     *
     * Used as a server-validated last-resort fallback when the intent cache is cold.
     * The value comes from the Khalti Lookup API response, never from the browser.
     */
    private function decodeInvoiceIdFromOrderId(string $orderId): ?int
    {
        if (!str_starts_with($orderId, 'INV-') || strlen($orderId) < 9) {
            return null;
        }

        $ref = substr($orderId, 4);
        if (strlen($ref) < 5 || !ctype_digit(substr($ref, -5))) {
            return null;
        }

        $nr = (int) substr($ref, -5);
        $serie = substr($ref, 0, -5);

        $invoice = $this->di['db']->findOne('Invoice', 'serie = ? AND nr = ?', [$serie, $nr]);

        return $invoice ? (int) $invoice->id : null;
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
        $currency = strtoupper($currency);
        if ($currency === 'NPR') {
            return $amount;
        }

        $currencyService = $this->di['mod_service']('currency');
        $repo = $currencyService->getCurrencyRepository();
        $defaultCurrency = $repo->findDefault();
        $baseCurrency = $defaultCurrency ? strtoupper((string) $defaultCurrency->getCode()) : 'NPR';

        $manualRate = isset($this->config['manual_npr_rate']) ? (float) $this->config['manual_npr_rate'] : 0.0;

        // --- Default currency IS NPR ------------------------------------------------
        // toBaseCurrency() already yields NPR here. But if the invoice currency's rate is
        // still FOSSBilling's default of 1.0 (i.e. never configured), the "conversion" is a
        // no-op that returns the foreign amount unchanged — which then trips the minimum-amount
        // check with a misleading "invoice too small" message (e.g. USD 1.13 shown as NPR 1.13).
        if ($baseCurrency === 'NPR') {
            // With an NPR base there is no non-NPR pivot currency to route through, so a manual
            // rate here means "NPR for 1 unit of the invoice currency" (single-foreign-currency
            // escape hatch). It overrides the currency table.
            if ($manualRate > 0) {
                return $amount * $manualRate;
            }

            // Detect the unconfigured 1:1 rate and fail with a clear, actionable error instead
            // of silently under-charging.
            $rateFrom = $repo->getRateByCode($currency);
            if ($rateFrom === null || abs($rateFrom - 1.0) < 1e-9) {
                throw new Payment_Exception('Khalti: The ' . $currency . ' → NPR exchange rate is not configured (it is still 1:1). Set the ' . $currency . ' conversion rate under Admin → System → Currencies, or enter a Manual NPR rate in the Khalti gateway settings.');
            }

            try {
                return $currencyService->toBaseCurrency($currency, $amount);
            } catch (FOSSBilling\Exception $e) {
                throw new Payment_Exception('Khalti: Cannot convert ' . $currency . ' to NPR — ' . $e->getMessage() . '. Please verify your currency rates under Admin → System → Currencies.');
            }
        }

        // --- Default currency is something else (e.g. USD) --------------------------
        // Step 1: invoice currency → base currency.
        // toBaseCurrency() throws \FOSSBilling\Exception on a missing or zero rate,
        // which we convert to Payment_Exception so getHtml() can surface a clear error.
        try {
            $amountInBase = $currencyService->toBaseCurrency($currency, $amount);
        } catch (FOSSBilling\Exception $e) {
            throw new Payment_Exception('Khalti: Cannot convert ' . $currency . ' to NPR — ' . $e->getMessage() . '. Please verify your currency rates under Admin → System → Currencies.');
        }

        // Step 2: base currency → NPR. A manual rate ("NPR per 1 unit of the base currency")
        // takes priority over the currency table and is applied to the base-currency amount, so
        // non-default invoice currencies (e.g. EUR under a USD base) are still pivoted correctly.
        if ($manualRate > 0) {
            return $amountInBase * $manualRate;
        }

        // Throw rather than silently returning the base-currency amount as NPR
        // (which caused false "invoice too small" errors).
        $nprRate = $repo->getRateByCode('NPR');
        if ($nprRate === null || $nprRate <= 0) {
            throw new Payment_Exception('Khalti: NPR exchange rate is not configured. Set a manual rate in the Khalti gateway settings or add NPR as a currency under Admin → System → Currencies.');
        }

        return $amountInBase * $nprRate;
    }

    private function extractErrorMessage(array $response, int $statusCode = 0): string
    {
        if (empty($response)) {
            return match ($statusCode) {
                401 => 'Authentication failed — check that the secret key is correct.',
                403 => 'Access forbidden — the key may not have permission for this operation.',
                429 => 'Too many requests — please wait a moment and try again.',
                500, 502, 503 => 'Khalti server error. Please try again shortly.',
                default => 'Empty response from Khalti API. Check the application log.',
            };
        }

        // {"detail": "..."} — standard Khalti auth/server error
        if (isset($response['detail'])) {
            return is_array($response['detail'])
                ? implode(' ', array_map(strval(...), $response['detail']))
                : (string) $response['detail'];
        }

        // {"message": "..."} — alternative single-message format
        if (isset($response['message']) && is_string($response['message'])) {
            return $response['message'];
        }

        // {"error_key": "validation_error", "field": ["..."]} — Khalti validation errors
        // Also handles plain field-level errors without error_key wrapper
        $skipKeys = ['error_key', 'status', 'code'];
        $messages = [];
        foreach ($response as $field => $errors) {
            if (in_array($field, $skipKeys, true)) {
                continue;
            }
            if (is_array($errors)) {
                $isAssoc = array_keys($errors) !== range(0, count($errors) - 1);
                if ($isAssoc) {
                    foreach ($errors as $subField => $subErrors) {
                        $flat = is_array($subErrors) ? implode(', ', array_map(strval(...), $subErrors)) : (string) $subErrors;
                        $messages[] = $field . '.' . $subField . ': ' . $flat;
                    }
                } else {
                    $messages[] = $field . ': ' . implode(', ', array_map(strval(...), $errors));
                }
            } elseif (is_string($errors) && $errors !== '') {
                $messages[] = $field . ': ' . $errors;
            }
        }
        if ($messages) {
            return implode('. ', $messages);
        }

        return 'Unexpected response from Khalti API. Check the application log (search "Khalti initiate failed").';
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

    private function storePaymentIntent(string $pidx, int $invoiceId, int $expectedPaisa, string $purchaseOrderId, int $ttlSeconds = 3600): void
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
            $item->expiresAfter($ttlSeconds);
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
