<?php

declare(strict_types=1);

/**
 * Chat Widget module for FOSSBilling / MeroPanel.
 *
 * Renders a floating support launcher on all client-facing pages via the theme
 * widget slot (WidgetProviderInterface) — no theme files are edited. Offers three
 * admin-configurable channels: WhatsApp, phone ("call our agent"), and Tawk.to
 * live chat. Config is stored through the core Extension config store (mod_chat).
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Chat;

use FOSSBilling\InjectionAwareInterface;
use FOSSBilling\Interfaces\WidgetProviderInterface;

class Service implements InjectionAwareInterface, WidgetProviderInterface
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
            'manage_settings' => [],
        ];
    }

    /**
     * Register the floating widget into the client theme's body-end slot,
     * which both meroserver client layouts already render.
     *
     * @return array<int, array{slot: string, template: string, priority: int}>
     */
    public function getWidgets(): array
    {
        return [
            [
                'slot' => 'client.theme.body.end',
                'template' => 'mod_chat_widget',
                'priority' => 100,
            ],
        ];
    }

    /**
     * Default configuration values, overridden by anything saved in the admin.
     *
     * @return array<string, string>
     */
    private function getDefaults(): array
    {
        return [
            'enabled' => '1',
            'online' => '1',
            'title' => 'Support Team',
            'subtitle' => 'Typically replies in minutes',
            'greeting' => 'Hi! How can we help? 👋',
            'whatsapp_number' => '',
            'whatsapp_label' => 'Send us a message',
            'phone_number' => '',
            'phone_label' => 'Call our agent',
            'livechat_label' => 'Chat with our team',
            'tawk_property_id' => '',
            'tawk_widget_id' => '',
            // Secret — used only server-side to compute the secure-mode HMAC.
            // Never returned to the browser (see getPublicConfig()).
            'tawk_api_key' => '',
        ];
    }

    /**
     * The currently logged-in client, or null — read straight from the session so
     * this is safe to call from the guest endpoint (unlike $di['loggedin_client'],
     * which redirects browsers when no client is logged in).
     */
    private function getLoggedInClient(): ?\Model_Client
    {
        try {
            $clientId = $this->di['session']->get('client_id');
            if (!$clientId) {
                return null;
            }

            $client = $this->di['db']->load('Client', (int) $clientId);
            if ($client instanceof \Model_Client && $client->status === \Model_Client::ACTIVE) {
                return $client;
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }

    /**
     * Public-safe widget configuration for the client template. Every value here
     * is already exposed in the rendered page, so there is nothing secret to leak.
     *
     * @return array<string, mixed>
     */
    public function getPublicConfig(): array
    {
        $saved = $this->di['mod_config']('chat');
        $cfg = array_merge($this->getDefaults(), is_array($saved) ? $saved : []);

        $enabled = (bool) ($cfg['enabled'] !== '0' && $cfg['enabled']);
        $online = (bool) ($cfg['online'] !== '0' && $cfg['online']);

        $whatsappDigits = preg_replace('/[^0-9]/', '', (string) $cfg['whatsapp_number']) ?? '';
        $phoneNumber = trim((string) $cfg['phone_number']);
        $tawkProperty = trim((string) $cfg['tawk_property_id']);
        $tawkWidget = trim((string) $cfg['tawk_widget_id']);

        $hasWhatsapp = $whatsappDigits !== '';
        $hasPhone = $phoneNumber !== '';
        $hasLivechat = $tawkProperty !== '' && $tawkWidget !== '';

        // Prefill the Tawk visitor with the logged-in client's identity so agents
        // see who they're talking to. If a Tawk API key is configured, compute the
        // secure-mode HMAC here — the key itself is never sent to the browser.
        $visitorName = '';
        $visitorEmail = '';
        $visitorHash = '';
        $apiKey = trim((string) $cfg['tawk_api_key']);
        if ($hasLivechat) {
            $client = $this->getLoggedInClient();
            if ($client instanceof \Model_Client) {
                $visitorName = trim(trim((string) $client->first_name) . ' ' . trim((string) $client->last_name));
                $visitorEmail = trim((string) $client->email);
                if ($apiKey !== '' && $visitorEmail !== '') {
                    $visitorHash = hash_hmac('sha256', $visitorEmail, $apiKey);
                }
            }
        }

        return [
            'enabled' => $enabled,
            'online' => $online,
            'title' => (string) $cfg['title'],
            'subtitle' => (string) $cfg['subtitle'],
            'greeting' => (string) $cfg['greeting'],

            'has_whatsapp' => $hasWhatsapp,
            'whatsapp_url' => $hasWhatsapp ? 'https://wa.me/' . $whatsappDigits : '',
            'whatsapp_label' => (string) $cfg['whatsapp_label'],

            'has_phone' => $hasPhone,
            'phone_number' => $phoneNumber,
            'phone_label' => (string) $cfg['phone_label'],

            'has_livechat' => $hasLivechat,
            'tawk_property_id' => $tawkProperty,
            'tawk_widget_id' => $tawkWidget,
            'livechat_label' => (string) $cfg['livechat_label'],

            // Visitor identity for the Tawk JS API (empty for guests). `visitor_hash`
            // is set only when a Tawk API key is configured (secure mode). The API key
            // itself is intentionally NOT included here.
            'visitor_name' => $visitorName,
            'visitor_email' => $visitorEmail,
            'visitor_hash' => $visitorHash,

            'should_render' => $enabled && ($hasWhatsapp || $hasPhone || $hasLivechat),
        ];
    }
}
