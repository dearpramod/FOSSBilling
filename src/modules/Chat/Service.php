<?php

declare(strict_types=1);

/**
 * Chat Widget module for FOSSBilling / MeroPanel.
 *
 * Renders a floating support launcher on all client-facing pages via the theme
 * widget slot (WidgetProviderInterface) — no theme files are edited. Offers three
 * WhatsApp, phone, Tawk.to live chat, and a local support-ticket fallback.
 * Only public channel details are exposed to the browser.
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
     * Tawk identifiers are public embed identifiers, not API secrets.
     *
     * @return array<string, string>
     */
    private function getDefaults(): array
    {
        return [
            'whatsapp_number' => '',
            'phone_number' => '',
            'tawk_property_id' => '',
            'tawk_widget_id' => '',
        ];
    }

    /**
     * Return only the public values needed to open the configured channels. No
     * presentation preferences, session, or client data cross this boundary.
     *
     * @return array<string, mixed>
     */
    public function getPublicConfig(): array
    {
        $saved = $this->di['mod_config']('chat');
        $cfg = array_merge($this->getDefaults(), is_array($saved) ? $saved : []);

        $whatsappDigits = preg_replace('/[^0-9]/', '', (string) $cfg['whatsapp_number']) ?? '';
        $validWhatsapp = preg_match('/^[1-9][0-9]{6,14}$/D', $whatsappDigits) === 1;

        $phoneDial = preg_replace('/[^0-9+*#]/', '', (string) $cfg['phone_number']) ?? '';
        $validPhone = preg_match('/^\+?[0-9][0-9*#]{5,19}$/D', $phoneDial) === 1;

        $tawkProperty = trim((string) $cfg['tawk_property_id']);
        $tawkWidget = trim((string) $cfg['tawk_widget_id']);

        // These values form part of a third-party script URL, so accept only the
        // identifier characters used by Tawk. Invalid values disable live chat.
        $validProperty = preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $tawkProperty) === 1;
        $validWidget = preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $tawkWidget) === 1;
        return [
            'tawk_property_id' => $validProperty ? $tawkProperty : '',
            'tawk_widget_id' => $validWidget ? $tawkWidget : '',
            'phone_number' => $validPhone ? $phoneDial : '',
            'whatsapp_url' => $validWhatsapp ? 'https://wa.me/' . $whatsappDigits : '',
        ];
    }
}
