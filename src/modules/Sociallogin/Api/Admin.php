<?php

declare(strict_types=1);

/**
 * Social Login module for FOSSBilling — Admin API.
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Sociallogin\Api;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Returns the current social login configuration for the admin settings page.
     *
     * The google_client_secret is masked in the response — the setting page
     * uses a password input and admins should re-enter the secret when updating.
     * This prevents the secret from being exposed in API responses or JS network
     * inspector captures.
     *
     * @return array{google_enabled: bool, google_client_id: string, google_client_secret_set: bool}
     */
    public function get_config(): array
    {
        $config = $this->di['mod_config']('sociallogin');

        return [
            'google_enabled' => (bool) ($config['google_enabled'] ?? false),
            'google_client_id' => (string) ($config['google_client_id'] ?? ''),
            'google_client_secret_set' => !empty($config['google_client_secret']),
        ];
    }

    /**
     * Saves the social login configuration.
     *
     * If google_client_secret is blank, the existing stored value is preserved.
     * This allows admins to update the Client ID or toggle the enable flag
     * without having to re-enter the secret every time.
     *
     * @param array{google_enabled?: string, google_client_id?: string, google_client_secret?: string} $data
     */
    public function save_config(array $data): bool
    {
        $existing = $this->di['mod_config']('sociallogin');

        $newSecret = trim((string) ($data['google_client_secret'] ?? ''));

        $config = [
            'google_enabled' => !empty($data['google_enabled']),
            'google_client_id' => trim((string) ($data['google_client_id'] ?? '')),
            'google_client_secret' => $newSecret !== '' ? $newSecret : (string) ($existing['google_client_secret'] ?? ''),
        ];

        $extensionService = $this->di['mod_service']('extension');
        $extensionService->setConfig(['ext' => 'mod_sociallogin'] + $config);

        return true;
    }
}
