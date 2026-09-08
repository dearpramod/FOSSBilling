<?php

declare(strict_types=1);

/**
 * Bank Transfer Payment Gateway Adapter for FOSSBilling.
 *
 * Manual payment gateway that displays bank account details,
 * payment instructions, and billing contact information.
 * Supports up to two bank accounts. Admin verifies payment manually.
 *
 * SPDX-License-Identifier: Apache-2.0
 */
class Payment_Adapter_BankTransfer
{
    protected ?Pimple\Container $di = null;
    private const string TRUSTED_SOURCE = 'admin';

    public function __construct(private array $config)
    {
    }

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public static function getConfig(): array
    {
        return [
            'can_load_in_iframe' => true,
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Bank Transfer gateway displays bank account details with payment instructions and billing contact information. Supports up to two bank accounts. Payments are verified manually by admin.',
            'logo' => [
                'logo' => 'custom.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form' => [
                'bank1_name' => [
                    'text', [
                        'label' => 'Bank 1 — Bank Name',
                        'description' => 'e.g. Nepal Bank Limited, NIC Asia, Global IME',
                        'required' => true,
                    ],
                ],
                'bank1_account_name' => [
                    'text', [
                        'label' => 'Bank 1 — Account Holder Name',
                        'required' => true,
                    ],
                ],
                'bank1_account_number' => [
                    'text', [
                        'label' => 'Bank 1 — Account Number',
                        'required' => true,
                    ],
                ],
                'bank1_branch' => [
                    'text', [
                        'label' => 'Bank 1 — Branch Name',
                        'description' => 'Optional branch name.',
                        'required' => false,
                    ],
                ],
                'bank1_swift' => [
                    'text', [
                        'label' => 'Bank 1 — SWIFT/BIC Code',
                        'description' => 'Optional, for international transfers.',
                        'required' => false,
                    ],
                ],
                'bank2_name' => [
                    'text', [
                        'label' => 'Bank 2 — Bank Name (Optional)',
                        'description' => 'Leave blank if you only have one bank account.',
                        'required' => false,
                    ],
                ],
                'bank2_account_name' => [
                    'text', [
                        'label' => 'Bank 2 — Account Holder Name',
                        'required' => false,
                    ],
                ],
                'bank2_account_number' => [
                    'text', [
                        'label' => 'Bank 2 — Account Number',
                        'required' => false,
                    ],
                ],
                'bank2_branch' => [
                    'text', [
                        'label' => 'Bank 2 — Branch Name',
                        'required' => false,
                    ],
                ],
                'bank2_swift' => [
                    'text', [
                        'label' => 'Bank 2 — SWIFT/BIC Code',
                        'required' => false,
                    ],
                ],
                'instructions' => [
                    'textarea', [
                        'label' => 'Payment Instructions',
                        'description' => 'Instructions displayed to the client. HTML and Twig variables ({{ invoice.* }}) are supported.',
                        'required' => false,
                    ],
                ],
                'contact_name' => [
                    'text', [
                        'label' => 'Billing Contact Name',
                        'required' => false,
                    ],
                ],
                'contact_email' => [
                    'text', [
                        'label' => 'Billing Contact Email',
                        'required' => false,
                    ],
                ],
                'contact_phone' => [
                    'text', [
                        'label' => 'Billing Contact Phone',
                        'required' => false,
                    ],
                ],
                'whatsapp_link' => [
                    'text', [
                        'label' => 'WhatsApp Link',
                        'description' => 'e.g. https://wa.me/9779812345678',
                        'required' => false,
                    ],
                ],
                'viber_link' => [
                    'text', [
                        'label' => 'Viber Link',
                        'description' => 'e.g. viber://chat?number=%2B9779812345678',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');
        $invoice = $invoiceService->toApiArray($invoiceModel, true);

        $c = fn (string $key): string => htmlspecialchars($this->config[$key] ?? '', ENT_QUOTES, 'UTF-8');

        $amount = $invoice['total'];
        $currency = $invoice['currency'];
        $invoiceNr = htmlspecialchars($invoice['serie'] . $invoice['nr'], ENT_QUOTES, 'UTF-8');

        // Render instructions via Twig
        $renderedInstructions = '';
        if (!empty($this->config['instructions'])) {
            $systemService = $this->di['mod_service']('System');
            $renderedInstructions = $systemService->renderAdapterTplString($this->config['instructions'], [
                'invoice' => $invoice,
            ]);
        }

        // Build bank accounts array
        $banks = [];
        if (!empty($this->config['bank1_name']) && !empty($this->config['bank1_account_number'])) {
            $banks[] = [
                'name' => $c('bank1_name'),
                'account_name' => $c('bank1_account_name'),
                'account_number' => $c('bank1_account_number'),
                'branch' => $c('bank1_branch'),
                'swift' => $c('bank1_swift'),
            ];
        }
        if (!empty($this->config['bank2_name']) && !empty($this->config['bank2_account_number'])) {
            $banks[] = [
                'name' => $c('bank2_name'),
                'account_name' => $c('bank2_account_name'),
                'account_number' => $c('bank2_account_number'),
                'branch' => $c('bank2_branch'),
                'swift' => $c('bank2_swift'),
            ];
        }

        $contactName = $c('contact_name');
        $contactEmail = $c('contact_email');
        $contactPhone = $c('contact_phone');
        $whatsappLink = $this->sanitizeContactLink($this->config['whatsapp_link'] ?? '');
        $viberLink = $this->sanitizeContactLink($this->config['viber_link'] ?? '');

        $svgBank = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M3 10h18"/><path d="M12 3l9 7H3l9-7z"/><path d="M5 10v8"/><path d="M9 10v8"/><path d="M15 10v8"/><path d="M19 10v8"/></svg>';
        $svgClipboard = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M9 14l2 2 4-4"/></svg>';
        $svgUsers = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>';
        $svgCopy = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;cursor:pointer;opacity:0.5;" class="bt-copy"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
        $svgWa = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';

        $html = '<style>';
        $html .= '.bt-wrap{max-width:520px;margin:0 auto;text-align:center}';
        $html .= '.bt-bank-card{text-align:left;margin-bottom:16px;padding:20px 24px;background:rgba(99,102,241,.04);border:1px solid rgba(99,102,241,.15);border-radius:14px}';
        $html .= '.bt-bank-card h4{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#818cf8;margin:0 0 16px;display:flex;align-items:center;gap:8px}';
        $html .= '.bt-bank-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}';
        $html .= '.bt-bank-row:last-child{border-bottom:none}';
        $html .= '.bt-bank-label{color:#9ca3af;font-weight:500}.bt-bank-value{color:#e5e7eb;font-weight:600;display:flex;align-items:center;gap:6px}';
        $html .= '.bt-instructions{text-align:left;margin-bottom:24px;padding:20px 24px;background:rgba(250,204,21,.06);border:1px solid rgba(250,204,21,.2);border-radius:14px}';
        $html .= '.bt-instructions h4{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fbbf24;margin:0 0 12px;display:flex;align-items:center;gap:8px}';
        $html .= '.bt-instructions .bt-text{font-size:14px;color:#e5e7eb;line-height:1.8}';
        $html .= '.bt-contact{text-align:left;padding:20px 24px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:14px}';
        $html .= '.bt-contact h4{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin:0 0 14px;display:flex;align-items:center;gap:8px}';
        $html .= '.bt-contact .bt-text{font-size:14px;color:#d1d5db;line-height:2}';
        $html .= '.bt-note{margin-top:24px;font-size:12px;color:#6b7280}';
        $html .= '.bt-badge{display:inline-block;padding:10px 24px;background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.25);border-radius:24px;font-size:14px;color:#a5b4fc;font-weight:600}';
        $html .= 'html[data-theme="light"] .bt-bank-card{background:rgba(99,102,241,.04);border-color:rgba(99,102,241,.15)}';
        $html .= 'html[data-theme="light"] .bt-bank-card h4{color:#4338ca}';
        $html .= 'html[data-theme="light"] .bt-bank-label{color:#64748b}html[data-theme="light"] .bt-bank-value{color:#1e293b}';
        $html .= 'html[data-theme="light"] .bt-bank-row{border-bottom-color:rgba(0,0,0,.06)}';
        $html .= 'html[data-theme="light"] .bt-instructions{background:rgba(250,204,21,.08);border-color:rgba(202,138,4,.25)}';
        $html .= 'html[data-theme="light"] .bt-instructions h4{color:#b45309}';
        $html .= 'html[data-theme="light"] .bt-instructions .bt-text,html[data-theme="light"] .bt-contact .bt-text{color:#1e293b}';
        $html .= 'html[data-theme="light"] .bt-contact{background:rgba(0,0,0,.02);border-color:rgba(0,0,0,.1)}';
        $html .= 'html[data-theme="light"] .bt-contact h4{color:#475569}';
        $html .= 'html[data-theme="light"] .bt-note{color:#64748b}';
        $html .= 'html[data-theme="light"] .bt-badge{background:rgba(99,102,241,.08);border-color:rgba(99,102,241,.2);color:#4338ca}';
        $html .= '</style>';

        $html .= '<div class="bt-wrap">';

        // Amount badge
        $html .= '<div style="margin-bottom:24px;"><span class="bt-badge">';
        $html .= 'Amount: ' . htmlspecialchars((string) $currency, ENT_QUOTES, 'UTF-8') . ' ' . number_format((float) $amount, 2);
        $html .= ' &nbsp;&middot;&nbsp; Invoice #' . $invoiceNr;
        $html .= '</span></div>';

        // Bank account cards
        if (empty($banks)) {
            $html .= '<div style="margin-bottom:24px;padding:32px;border:2px dashed rgba(255,255,255,.15);border-radius:16px;">';
            $html .= '<p style="color:#9ca3af;font-size:14px;margin:0;">Bank account details not configured. Please contact support.</p>';
            $html .= '</div>';
        } else {
            foreach ($banks as $i => $bank) {
                $label = count($banks) > 1 ? 'Bank Account ' . ($i + 1) . ' — ' . $bank['name'] : $bank['name'];
                $html .= '<div class="bt-bank-card"><h4>' . $svgBank . ' ' . $label . '</h4>';
                if ($bank['account_name']) {
                    $html .= '<div class="bt-bank-row"><span class="bt-bank-label">Account Holder</span><span class="bt-bank-value">' . $bank['account_name'] . '</span></div>';
                }
                $html .= '<div class="bt-bank-row"><span class="bt-bank-label">Account Number</span><span class="bt-bank-value">' . $bank['account_number'] . ' ' . $svgCopy . '</span></div>';
                if ($bank['branch']) {
                    $html .= '<div class="bt-bank-row"><span class="bt-bank-label">Branch</span><span class="bt-bank-value">' . $bank['branch'] . '</span></div>';
                }
                if ($bank['swift']) {
                    $html .= '<div class="bt-bank-row"><span class="bt-bank-label">SWIFT / BIC</span><span class="bt-bank-value">' . $bank['swift'] . '</span></div>';
                }
                $html .= '</div>';
            }
        }

        // Instructions
        if (!empty($renderedInstructions)) {
            $html .= '<div class="bt-instructions"><h4>' . $svgClipboard . ' Payment Instructions</h4>';
            $html .= '<div class="bt-text">' . $renderedInstructions . '</div></div>';
        }

        // Billing contact
        $hasContact = !empty($contactName) || !empty($contactEmail) || !empty($contactPhone) || !empty($whatsappLink) || !empty($viberLink);
        if ($hasContact) {
            $html .= '<div class="bt-contact"><h4>' . $svgUsers . ' Billing Contact</h4><div class="bt-text">';
            if ($contactName) {
                $html .= '<div style="display:flex;align-items:center;gap:8px;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> ' . $contactName . '</div>';
            }
            if ($contactEmail) {
                $html .= '<div style="display:flex;align-items:center;gap:8px;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> <a href="mailto:' . $contactEmail . '" style="text-decoration:none;">' . $contactEmail . '</a></div>';
            }
            if ($contactPhone) {
                $html .= '<div style="display:flex;align-items:center;gap:8px;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg> <a href="tel:' . $contactPhone . '" style="text-decoration:none;">' . $contactPhone . '</a></div>';
            }
            $html .= '</div>';
            if (!empty($whatsappLink) || !empty($viberLink)) {
                $html .= '<div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">';
                if (!empty($whatsappLink)) {
                    $html .= '<a href="' . $whatsappLink . '" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:rgba(37,211,102,.1);border:1px solid rgba(37,211,102,.25);border-radius:10px;color:#25d366;font-size:13px;font-weight:600;text-decoration:none;">' . $svgWa . ' WhatsApp</a>';
                }
                if (!empty($viberLink)) {
                    $html .= '<a href="' . $viberLink . '" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:rgba(121,92,174,.1);border:1px solid rgba(121,92,174,.25);border-radius:10px;color:#7360f2;font-size:13px;font-weight:600;text-decoration:none;"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M11.4 0C5.112 0 0 4.797 0 10.713c0 3.075 1.397 5.852 3.644 7.758v4.573l.052.029c.388.22.873.02 1.032-.397l1.186-3.078c1.678.628 3.528.975 5.486.975 6.288 0 11.4-4.797 11.4-10.713V10.713C22.8 4.797 17.688 0 11.4 0z"/></svg> Viber</a>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '<script>document.querySelectorAll(".bt-copy").forEach(function(el){el.addEventListener("click",function(){var val=this.parentElement.textContent.trim();if(navigator.clipboard){navigator.clipboard.writeText(val).then(function(){el.style.opacity="1";setTimeout(function(){el.style.opacity="0.5";},800);});}});});</script>';
        $html .= '<p class="bt-note">After completing the transfer, please allow some time for verification. Your invoice will be marked as paid once the payment is confirmed.</p>';

        return $html . '</div>';
    }

    /**
     * Only allow http(s)/viber schemes through to href="" — these fields are admin-configured
     * free text (whatsapp_link / viber_link), not restricted to a URL type, so an unvalidated
     * javascript: URI here would execute in every client's authenticated portal session the
     * moment they click the WhatsApp/Viber button on the payment page.
     */
    private function sanitizeContactLink(string $url): string
    {
        $url = trim($url);

        if ($url === '' || !preg_match('#^(https?://|viber://)#i', $url)) {
            return '';
        }

        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id): bool
    {
        if (!$this->isIpnValid($data)) {
            throw new Payment_Exception('Bank Transfer payment callbacks must be confirmed by an administrator.');
        }

        try {
            $tx = $this->di['db']->getExistingModelById('Transaction', $id);
            $invoice = $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id);
            $gateway = $this->di['db']->load('PayGateway', $tx->gateway_id);

            $clientService = $this->di['mod_service']('Client');
            $client = $clientService->get(['id' => $invoice->client_id]);

            $invoiceService = $this->di['mod_service']('Invoice');
            $invoiceTotal = $invoiceService->getTotalWithTax($invoice);

            $txDesc = $gateway->title . ' transaction No: ' . $tx->txn_id;
            $clientService->addFunds($client, $invoiceTotal, $txDesc, []);
            $invoiceService->markAsPaid($invoice, true, true);

            $tx->status = Model_Transaction::STATUS_PROCESSED;
            $tx->amount = $invoiceTotal;
            $tx->note = $txDesc;
            $tx->currency = $invoice->currency;
            $tx->updated_at = date('Y-m-d H:i:s');

            return (bool) $this->di['db']->store($tx);
        } catch (Exception) {
            return false;
        }
    }

    public function isIpnValid(array $data): bool
    {
        return ($data['source'] ?? null) === self::TRUSTED_SOURCE;
    }
}
