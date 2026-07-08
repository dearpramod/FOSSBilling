<?php

declare(strict_types=1);

/**
 * QR Payment Gateway Adapter for FOSSBilling.
 *
 * Manual payment gateway that displays a QR code image,
 * payment instructions, and billing contact details.
 * Admin verifies payment manually and marks invoice as paid.
 *
 * SPDX-License-Identifier: Apache-2.0
 */
class Payment_Adapter_QRPayment
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
            'description' => 'QR Payment gateway displays a QR code image with payment instructions and billing contact details. Payments are verified manually by admin.',
            'logo' => [
                'logo' => 'custom.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form' => [
                'qr_image_url' => [
                    'text', [
                        'label' => 'QR Code Image URL',
                        'description' => 'Full URL to the QR code image (e.g. https://example.com/qr.png). You can also use a base64 data URI.',
                        'required' => true,
                    ],
                ],
                'instructions' => [
                    'textarea', [
                        'label' => 'Payment Instructions',
                        'description' => 'Instructions displayed to the client below the QR code. HTML is supported.',
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
                        'description' => 'Full WhatsApp chat link (e.g. https://wa.me/9779812345678).',
                        'required' => false,
                    ],
                ],
                'viber_link' => [
                    'text', [
                        'label' => 'Viber Link',
                        'description' => 'Full Viber chat link (e.g. viber://chat?number=%2B9779812345678).',
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

        $qrImageUrl = $this->config['qr_image_url'] ?? '';
        $contactName = htmlspecialchars($this->config['contact_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $contactEmail = htmlspecialchars($this->config['contact_email'] ?? '', ENT_QUOTES, 'UTF-8');
        $contactPhone = htmlspecialchars($this->config['contact_phone'] ?? '', ENT_QUOTES, 'UTF-8');
        $whatsappLink = htmlspecialchars($this->config['whatsapp_link'] ?? '', ENT_QUOTES, 'UTF-8');
        $viberLink = htmlspecialchars($this->config['viber_link'] ?? '', ENT_QUOTES, 'UTF-8');

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

        // Sanitize QR image URL — allow http(s) and data: URIs only
        $safeQrUrl = '';
        if (!empty($qrImageUrl)) {
            if (preg_match('#^https?://#i', $qrImageUrl) || preg_match('#^data:image/#i', $qrImageUrl)) {
                $safeQrUrl = htmlspecialchars($qrImageUrl, ENT_QUOTES, 'UTF-8');
            }
        }

        $svgWa = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';

        $html = '<style>';
        $html .= '.qrpay-wrap{max-width:520px;margin:0 auto;text-align:center}';
        $html .= '.qrpay-instructions{text-align:left;margin-bottom:24px;padding:20px 24px;background:rgba(250,204,21,.06);border:1px solid rgba(250,204,21,.2);border-radius:14px}';
        $html .= '.qrpay-instructions h4{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fbbf24;margin:0 0 12px;display:flex;align-items:center;gap:8px}';
        $html .= '.qrpay-instructions .qrpay-text{font-size:14px;color:#e5e7eb;line-height:1.8}';
        $html .= '.qrpay-contact{text-align:left;padding:20px 24px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:14px}';
        $html .= '.qrpay-contact h4{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin:0 0 14px;display:flex;align-items:center;gap:8px}';
        $html .= '.qrpay-contact .qrpay-text{font-size:14px;color:#d1d5db;line-height:2}';
        $html .= '.qrpay-note{margin-top:24px;font-size:12px;color:#6b7280}';
        $html .= '.qrpay-badge{display:inline-block;padding:10px 24px;background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.25);border-radius:24px;font-size:14px;color:#a5b4fc;font-weight:600}';
        $html .= '.qrpay-hint{margin-top:10px;font-size:11px;color:#6b7280}';
        $html .= 'html[data-theme="light"] .qrpay-instructions{background:rgba(250,204,21,.08);border-color:rgba(202,138,4,.25)}';
        $html .= 'html[data-theme="light"] .qrpay-instructions h4{color:#b45309}';
        $html .= 'html[data-theme="light"] .qrpay-instructions .qrpay-text{color:#1e293b}';
        $html .= 'html[data-theme="light"] .qrpay-contact{background:rgba(0,0,0,.02);border-color:rgba(0,0,0,.1)}';
        $html .= 'html[data-theme="light"] .qrpay-contact h4{color:#475569}';
        $html .= 'html[data-theme="light"] .qrpay-contact .qrpay-text{color:#1e293b}';
        $html .= 'html[data-theme="light"] .qrpay-note{color:#64748b}';
        $html .= 'html[data-theme="light"] .qrpay-badge{background:rgba(99,102,241,.08);border-color:rgba(99,102,241,.2);color:#4338ca}';
        $html .= 'html[data-theme="light"] .qrpay-hint{color:#64748b}';
        $html .= '</style>';

        $html .= '<div class="qrpay-wrap">';

        // QR Code image
        if ($safeQrUrl) {
            $html .= '<div style="margin-bottom:28px;">';
            $html .= '<div style="display:inline-block;padding:20px;background:#ffffff;border-radius:20px;box-shadow:0 4px 24px rgba(0,0,0,.15);">';
            $html .= '<img src="' . $safeQrUrl . '" alt="Scan to Pay" style="max-width:280px;width:100%;height:auto;display:block;" />';
            $html .= '</div>';
            $html .= '<p class="qrpay-hint">Scan this QR code with your payment app</p>';
            $html .= '</div>';
        } else {
            $html .= '<div style="margin-bottom:28px;padding:40px;border:2px dashed rgba(255,255,255,.15);border-radius:16px;">';
            $html .= '<p style="color:#9ca3af;font-size:14px;margin:0;">QR code not configured. Please contact support.</p>';
            $html .= '</div>';
        }

        // Amount badge
        $html .= '<div style="margin-bottom:24px;"><span class="qrpay-badge">';
        $html .= 'Amount: ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ' ' . number_format((float) $amount, 2);
        $html .= ' &nbsp;&middot;&nbsp; Invoice #' . $invoiceNr;
        $html .= '</span></div>';

        // Instructions
        if (!empty($renderedInstructions)) {
            $html .= '<div class="qrpay-instructions"><h4><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M9 14l2 2 4-4"/></svg> Payment Instructions</h4>';
            $html .= '<div class="qrpay-text">' . $renderedInstructions . '</div></div>';
        }

        // Billing contact
        $hasContact = !empty($contactName) || !empty($contactEmail) || !empty($contactPhone) || !empty($whatsappLink) || !empty($viberLink);
        if ($hasContact) {
            $html .= '<div class="qrpay-contact"><h4><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg> Billing Contact</h4>';
            $html .= '<div class="qrpay-text">';
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

        $html .= '<p class="qrpay-note">After completing payment, please allow some time for verification. Your invoice will be marked as paid once confirmed.</p>';
        $html .= '</div>';

        return $html;
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id): bool
    {
        if (!$this->isIpnValid($data)) {
            throw new Payment_Exception('QR Payment callbacks must be confirmed by an administrator.');
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
