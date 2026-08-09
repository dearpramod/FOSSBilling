<?php

declare(strict_types=1);

/**
 * WhatsApp Notifications Module for FOSSBilling.
 *
 * Sends WhatsApp messages via the WaSender API whenever key billing events
 * fire (invoice paid, order activated, ticket reply, etc.).
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whatsapp;

use FOSSBilling\InjectionAwareInterface;
use FOSSBilling\PaginationOptions;
use Symfony\Component\HttpClient\HttpClient;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    private const string API_URL = 'https://www.wasenderapi.com/api/send-message';

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
            'can_always_access' => true,
            'manage_settings' => [],
        ];
    }

    public function install(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function getConfig(): array
    {
        return $this->di['mod']('whatsapp')->getConfig() ?? [];
    }

    public function isEnabled(): bool
    {
        $cfg = $this->getConfig();

        return !empty($cfg['enabled']) && !empty($cfg['api_key']);
    }

    // -------------------------------------------------------------------------
    // Core: send a WhatsApp text message
    // -------------------------------------------------------------------------

    /**
     * Send a WhatsApp text message via WaSender API.
     *
     * @param string $to   Recipient phone in E.164 format (e.g. "+9779800000000")
     * @param string $text Plain-text message body
     */
    public function sendMessage(string $to, string $text): array
    {
        $cfg = $this->getConfig();
        $apiKey = $cfg['api_key'] ?? '';

        if (empty($apiKey)) {
            throw new \FOSSBilling\InformationException('WhatsApp API key is not configured.');
        }

        $to = preg_replace('/[^+\d]/', '', $to);

        $client = HttpClient::create();
        $response = $client->request('POST', self::API_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => [
                'to' => $to,
                'text' => $text,
            ],
            'timeout' => 15,
        ]);

        $statusCode = $response->getStatusCode();
        $result = $response->toArray(false);

        if ($statusCode >= 400 || empty($result['success'])) {
            $errMsg = $result['message'] ?? $result['error'] ?? 'Unknown WaSender error';
            $this->di['logger']->warning('[WhatsApp] Send failed to ' . $to . ': ' . $errMsg);

            throw new \FOSSBilling\InformationException('WhatsApp send failed: :msg', [':msg' => $errMsg]);
        }

        $this->di['logger']->info('[WhatsApp] Message sent to ' . $to . ' — msgId: ' . ($result['data']['msgId'] ?? 'n/a'));
        $this->logMessage($to, $text, $result);

        return $result;
    }

    public function sendTest(string $to): array
    {
        $company = $this->di['mod_service']('system')->getCompany();

        return $this->sendMessage($to, '✅ WhatsApp test from ' . ($company['name'] ?? 'FOSSBilling') . ' — your integration is working!');
    }

    // -------------------------------------------------------------------------
    // Message log (stored in extension_meta)
    // -------------------------------------------------------------------------

    private function logMessage(string $to, string $text, array $apiResponse): void
    {
        $cfg = $this->getConfig();
        if (empty($cfg['log_enabled'])) {
            return;
        }

        $db = $this->di['db'];
        $log = $db->dispense('extension_meta');
        $log->extension = 'mod_whatsapp';
        $log->meta_key = 'message_log';
        $log->meta_value = json_encode([
            'to' => $to,
            'text' => mb_substr($text, 0, 500),
            'msg_id' => $apiResponse['data']['msgId'] ?? null,
            'status' => 'sent',
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        $log->created_at = date('Y-m-d H:i:s');
        $log->updated_at = date('Y-m-d H:i:s');
        $db->store($log);
    }

    public function getMessageLog(array $data = []): array
    {
        $perPage = isset($data['per_page']) ? (int) $data['per_page'] : 30;
        $page = isset($data['page']) ? (int) $data['page'] : 1;

        $sql = "SELECT * FROM extension_meta WHERE extension = 'mod_whatsapp' AND meta_key = 'message_log' ORDER BY id DESC";
        $pager = $this->di['pager']->getPaginatedResultSet($sql, [], new PaginationOptions($page, $perPage));

        foreach ($pager['list'] as &$item) {
            $decoded = json_decode((string) $item['meta_value'], true);
            $item = array_merge($item, $decoded ?: []);
            unset($item['meta_value'], $item['meta_key'], $item['extension']);
        }

        return $pager;
    }

    public function deleteLogEntry(int $id): bool
    {
        $entry = $this->di['db']->load('extension_meta', $id);
        if ($entry && $entry->extension === 'mod_whatsapp' && $entry->meta_key === 'message_log') {
            $this->di['db']->trash($entry);

            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function getClientPhone(int $clientId): ?string
    {
        $client = $this->di['db']->load('Client', $clientId);
        if (!$client) {
            return null;
        }

        $phone = trim($client->phone ?? '');
        if (empty($phone)) {
            $phone = trim($client->phone_cc ?? '');
        }

        if (empty($phone)) {
            return null;
        }

        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    /**
     * Build a notification text from a template string.
     *
     * Supported placeholders: {{client_name}}, {{invoice_id}}, {{amount}},
     * {{order_id}}, {{ticket_id}}, {{company_name}}, {{due_date}}, etc.
     */
    public function buildMessage(string $template, array $vars = []): string
    {
        $company = $this->di['mod_service']('system')->getCompany();
        $vars['company_name'] ??= $company['name'] ?? 'FOSSBilling';

        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }

        $template = preg_replace('/\{\{[a-z_]+\}\}/', '', $template);

        return trim((string) $template);
    }

    // -------------------------------------------------------------------------
    // Event listeners — auto-discovered by Hook module
    // -------------------------------------------------------------------------

    public static function onAfterAdminInvoicePaymentReceived(\Box_Event $event): void
    {
        self::handleEvent($event->getDi(), 'invoice_paid', $event->getParameters());
    }

    public static function onAfterAdminInvoiceApprove(\Box_Event $event): void
    {
        self::handleEvent($event->getDi(), 'invoice_created', $event->getParameters());
    }

    public static function onAfterAdminOrderActivate(\Box_Event $event): void
    {
        self::handleEvent($event->getDi(), 'order_activated', $event->getParameters());
    }

    public static function onAfterAdminOrderSuspend(\Box_Event $event): void
    {
        self::handleEvent($event->getDi(), 'order_suspended', $event->getParameters());
    }

    public static function onAfterAdminOrderUnsuspend(\Box_Event $event): void
    {
        self::handleEvent($event->getDi(), 'order_unsuspended', $event->getParameters());
    }

    public static function onAfterAdminOrderCancel(\Box_Event $event): void
    {
        self::handleEvent($event->getDi(), 'order_cancelled', $event->getParameters());
    }

    public static function onAfterClientOpenTicket(\Box_Event $event): void
    {
        self::handleEvent($event->getDi(), 'ticket_opened', $event->getParameters());
    }

    public static function onAfterAdminReplyTicket(\Box_Event $event): void
    {
        self::handleEvent($event->getDi(), 'ticket_reply', $event->getParameters());
    }

    public static function onAfterAdminCloseTicket(\Box_Event $event): void
    {
        self::handleEvent($event->getDi(), 'ticket_closed', $event->getParameters());
    }

    public static function onAfterClientSignUp(\Box_Event $event): void
    {
        self::handleEvent($event->getDi(), 'client_signup', $event->getParameters());
    }

    public static function onAfterClientPasswordResetRequest(\Box_Event $event): void
    {
        self::handleEvent($event->getDi(), 'password_reset', $event->getParameters());
    }

    // -------------------------------------------------------------------------
    // Internal event dispatcher
    // -------------------------------------------------------------------------

    private static function handleEvent(\Pimple\Container $di, string $eventKey, array $params): void
    {
        try {
            /** @var Service $service */
            $service = $di['mod_service']('whatsapp');

            if (!$service->isEnabled()) {
                return;
            }

            $cfg = $service->getConfig();

            if (empty($cfg['notify_' . $eventKey])) {
                return;
            }

            $clientId = $params['client_id'] ?? null;

            if (!$clientId && !empty($params['id'])) {
                $clientId = self::resolveClientId($di, $eventKey, $params);
            }

            if (!$clientId) {
                return;
            }

            $phone = $service->getClientPhone((int) $clientId);
            if (!$phone) {
                return;
            }

            $template = $cfg['template_' . $eventKey] ?? self::defaultTemplate($eventKey);
            $vars = self::buildVars($di, $eventKey, $params, (int) $clientId);
            $message = $service->buildMessage($template, $vars);

            if (empty($message)) {
                return;
            }

            $service->sendMessage($phone, $message);
        } catch (\Exception $e) {
            $di['logger']->warning('[WhatsApp] Event ' . $eventKey . ' error: ' . $e->getMessage());
        }
    }

    private static function resolveClientId(\Pimple\Container $di, string $eventKey, array $params): ?int
    {
        $id = $params['id'] ?? null;
        if (!$id) {
            return null;
        }

        if (str_starts_with($eventKey, 'invoice_')) {
            $model = $di['db']->load('Invoice', $id);

            return $model?->client_id ? (int) $model->client_id : null;
        }

        if (str_starts_with($eventKey, 'order_')) {
            $model = $di['db']->load('ClientOrder', $id);

            return $model?->client_id ? (int) $model->client_id : null;
        }

        if (str_starts_with($eventKey, 'ticket_')) {
            $model = $di['db']->load('SupportTicket', $id);

            return $model?->client_id ? (int) $model->client_id : null;
        }

        if ($eventKey === 'client_signup' || $eventKey === 'password_reset') {
            return (int) $id;
        }

        return null;
    }

    private static function buildVars(\Pimple\Container $di, string $eventKey, array $params, int $clientId): array
    {
        $client = $di['db']->load('Client', $clientId);
        $vars = [
            'client_name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) ?: 'Customer',
            'client_email' => $client->email ?? '',
        ];

        $id = $params['id'] ?? null;

        if (str_starts_with($eventKey, 'invoice_') && $id) {
            $invoice = $di['db']->load('Invoice', $id);
            if ($invoice) {
                $invoiceArr = $di['mod_service']('invoice')->toApiArray($invoice, ['id' => $id]);
                $vars['invoice_id'] = $invoiceArr['id'] ?? $id;
                $vars['amount'] = ($invoiceArr['currency'] ?? '') . ' ' . ($invoiceArr['total'] ?? '0.00');
                $vars['due_date'] = $invoiceArr['due_at'] ?? '';
            }
        }

        if (str_starts_with($eventKey, 'order_') && $id) {
            $order = $di['db']->load('ClientOrder', $id);
            if ($order) {
                $vars['order_id'] = $id;
                $vars['order_title'] = $order->title ?? '';
            }
        }

        if (str_starts_with($eventKey, 'ticket_') && $id) {
            $ticket = $di['db']->load('SupportTicket', $id);
            if ($ticket) {
                $vars['ticket_id'] = $id;
                $vars['ticket_subject'] = $ticket->subject ?? '';
            }
        }

        return $vars;
    }

    private static function defaultTemplate(string $eventKey): string
    {
        return match ($eventKey) {
            'invoice_paid' => '✅ Hi {{client_name}}, your invoice #{{invoice_id}} ({{amount}}) has been paid. Thank you! — {{company_name}}',
            'invoice_created' => '📄 Hi {{client_name}}, a new invoice #{{invoice_id}} for {{amount}} has been generated. Due: {{due_date}}. — {{company_name}}',
            'order_activated' => '🚀 Hi {{client_name}}, your order "{{order_title}}" (#{{order_id}}) is now active! — {{company_name}}',
            'order_suspended' => '⚠️ Hi {{client_name}}, your service "{{order_title}}" (#{{order_id}}) has been suspended. Please check your account. — {{company_name}}',
            'order_unsuspended' => '✅ Hi {{client_name}}, your service "{{order_title}}" (#{{order_id}}) has been reactivated. — {{company_name}}',
            'order_cancelled' => '❌ Hi {{client_name}}, your order "{{order_title}}" (#{{order_id}}) has been cancelled. — {{company_name}}',
            'ticket_opened' => '🎫 Hi {{client_name}}, your support ticket "{{ticket_subject}}" (#{{ticket_id}}) has been received. We\'ll respond shortly. — {{company_name}}',
            'ticket_reply' => '💬 Hi {{client_name}}, there\'s a new reply to your ticket "{{ticket_subject}}" (#{{ticket_id}}). Please check your client area. — {{company_name}}',
            'ticket_closed' => '✔️ Hi {{client_name}}, your ticket "{{ticket_subject}}" (#{{ticket_id}}) has been closed. — {{company_name}}',
            'client_signup' => '👋 Welcome {{client_name}}! Your account at {{company_name}} has been created. — {{company_name}}',
            'password_reset' => '🔑 Hi {{client_name}}, a password reset was requested for your account. If this was not you, please contact support. — {{company_name}}',
            default => 'Notification from {{company_name}}',
        };
    }
}
