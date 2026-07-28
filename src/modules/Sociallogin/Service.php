<?php

declare(strict_types=1);

/**
 * Social Login module for FOSSBilling.
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Sociallogin;

use FOSSBilling\InjectionAwareInterface;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    /** Token TTL in seconds (5 minutes). */
    public const TOKEN_TTL = 300;

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
            'manage_settings' => true,
        ];
    }

    /**
     * Returns the full OAuth callback URL for Google.
     */
    public function getCallbackUrl(): string
    {
        return rtrim(SYSTEM_URL, '/') . '/sociallogin/google/callback';
    }

    /**
     * Returns whether Google login is enabled and fully configured.
     */
    public function isGoogleEnabled(): bool
    {
        $config = $this->di['mod_config']('sociallogin');

        return !empty($config['google_enabled'])
            && !empty($config['google_client_id'])
            && !empty($config['google_client_secret']);
    }

    /**
     * Returns true if the given client has already authorised Google login.
     *
     * An account is considered Google-linked when:
     *   (a) it was originally created via social sign-up (auth_type = 'google'), OR
     *   (b) the user explicitly confirmed a Google account-link on a later visit
     *       (a 'google_linked' record exists in extension_meta for this client).
     */
    public function isGoogleLinked(\Model_Client $client): bool
    {
        if ($client->auth_type === 'google') {
            return true;
        }

        $row = $this->di['db']->findOne(
            'ExtensionMeta',
            "extension = 'mod_sociallogin' AND meta_key = 'google_linked' AND client_id = ?",
            [(int) $client->id]
        );

        return $row !== null;
    }

    /**
     * Permanently links Google login to an existing client account.
     *
     * Called after the user confirms the link on the account-link page.
     * Does nothing if the link record already exists (idempotent).
     */
    public function linkGoogle(\Model_Client $client): void
    {
        if ($this->isGoogleLinked($client)) {
            return;
        }

        $meta = $this->di['db']->dispense('ExtensionMeta');
        $meta->extension = 'mod_sociallogin';
        $meta->meta_key = 'google_linked';
        $meta->meta_value = '1';
        $meta->client_id = (int) $client->id;
        $meta->created_at = date('Y-m-d H:i:s');
        $meta->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($meta);
    }

    /**
     * Stores a short-lived pending-link token in extension_meta.
     *
     * Used when an email/password account is found during Google login and the
     * user hasn't yet confirmed the link.  The token is consumed once the user
     * clicks "Link & Sign In" on the confirmation page.
     *
     * Note: add a composite index on (extension, meta_key) to keep the LIKE
     * purge queries fast as the table grows.
     */
    public function storePendingLinkToken(string $token, int $clientId): void
    {
        $expiry = date('Y-m-d H:i:s', time() - self::TOKEN_TTL);
        $this->di['db']->exec(
            "DELETE FROM extension_meta WHERE extension = 'mod_sociallogin' AND meta_key LIKE 'pending_link_%' AND created_at < ?",
            [$expiry]
        );

        $meta = $this->di['db']->dispense('ExtensionMeta');
        $meta->extension = 'mod_sociallogin';
        $meta->meta_key = 'pending_link_' . $token;
        $meta->meta_value = (string) $clientId;
        $meta->created_at = date('Y-m-d H:i:s');
        $meta->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($meta);
    }

    /**
     * Validates and consumes a pending-link token.
     *
     * Returns the client id on success, null if invalid/expired.
     */
    public function consumePendingLinkToken(string $token): ?int
    {
        $row = $this->di['db']->findOne(
            'ExtensionMeta',
            "extension = 'mod_sociallogin' AND meta_key = ?",
            ['pending_link_' . $token]
        );

        if ($row === null) {
            return null;
        }

        $age = time() - (int) strtotime($row->created_at ?? '');
        if ($age > self::TOKEN_TTL) {
            $this->di['db']->trash($row);

            return null;
        }

        $clientId = (int) ($row->meta_value ?? 0);
        $this->di['db']->trash($row);

        return $clientId > 0 ? $clientId : null;
    }

    /**
     * Returns an existing client by email, or null if none found.
     */
    public function findClientByEmail(string $email): ?\Model_Client
    {
        $client = $this->di['db']->findOne('Client', 'email = ?', [$email]);

        return $client instanceof \Model_Client ? $client : null;
    }

    /**
     * Stores a short-lived pre-registration token in `extension_meta`.
     *
     * A pre-registration token carries the Google profile data for a user
     * whose email does not yet exist.  It is consumed once the user submits
     * the completion form.  TTL is shared with the login token (5 min).
     *
     * @param array{email:string,first_name:string,last_name:string} $profile
     */
    public function storePendingRegistrationToken(string $token, array $profile): void
    {
        $expiry = date('Y-m-d H:i:s', time() - self::TOKEN_TTL);
        $this->di['db']->exec(
            "DELETE FROM extension_meta WHERE extension = 'mod_sociallogin' AND meta_key LIKE 'pending_reg_%' AND created_at < ?",
            [$expiry]
        );

        $meta = $this->di['db']->dispense('ExtensionMeta');
        $meta->extension = 'mod_sociallogin';
        $meta->meta_key = 'pending_reg_' . $token;
        $meta->meta_value = json_encode($profile);
        $meta->created_at = date('Y-m-d H:i:s');
        $meta->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($meta);
    }

    /**
     * Validates and returns (but does NOT consume) a pre-registration token.
     *
     * Returns the stored profile array (email, first_name, last_name) or null
     * if the token is invalid or expired.  The token is consumed by
     * consumePendingRegistrationToken() after account creation.
     *
     * @return array{email:string,first_name:string,last_name:string}|null
     */
    public function peekPendingRegistrationToken(string $token): ?array
    {
        $row = $this->di['db']->findOne(
            'ExtensionMeta',
            "extension = 'mod_sociallogin' AND meta_key = ?",
            ['pending_reg_' . $token]
        );

        if ($row === null) {
            return null;
        }

        $age = time() - (int) strtotime($row->created_at ?? '');
        if ($age > self::TOKEN_TTL) {
            $this->di['db']->trash($row);

            return null;
        }

        $data = json_decode($row->meta_value ?? '', true);

        return is_array($data) ? $data : null;
    }

    /**
     * Consumes a pre-registration token (deletes it).  Call after the client
     * account has been successfully created.
     */
    public function consumePendingRegistrationToken(string $token): void
    {
        $row = $this->di['db']->findOne(
            'ExtensionMeta',
            "extension = 'mod_sociallogin' AND meta_key = ?",
            ['pending_reg_' . $token]
        );

        if ($row !== null) {
            $this->di['db']->trash($row);
        }
    }

    /**
     * Stores a short-lived one-time login token in `extension_meta`.
     *
     * The token is stored as the meta_key (prefixed) so it can be retrieved
     * with a direct single-row DB lookup — O(1) regardless of table size.
     * Old expired tokens for this module are pruned during the same call.
     */
    public function storePendingLoginToken(string $token, int $clientId): void
    {
        $expiry = date('Y-m-d H:i:s', time() - self::TOKEN_TTL);
        $this->di['db']->exec(
            "DELETE FROM extension_meta WHERE extension = 'mod_sociallogin' AND meta_key LIKE 'pending_login_%' AND created_at < ?",
            [$expiry]
        );

        $meta = $this->di['db']->dispense('ExtensionMeta');
        $meta->extension = 'mod_sociallogin';
        $meta->meta_key = 'pending_login_' . $token;
        $meta->meta_value = (string) $clientId;
        $meta->created_at = date('Y-m-d H:i:s');
        $meta->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($meta);
    }

    /**
     * Validates and consumes a one-time login token.
     *
     * Uses a direct DB lookup by meta_key — no PHP-side iteration over all
     * pending tokens.  Returns the associated client id on success, or null
     * if the token is invalid, expired, or already used.
     */
    public function consumePendingLoginToken(string $token): ?int
    {
        $row = $this->di['db']->findOne(
            'ExtensionMeta',
            "extension = 'mod_sociallogin' AND meta_key = ?",
            ['pending_login_' . $token]
        );

        if ($row === null) {
            return null;
        }

        $age = time() - (int) strtotime($row->created_at ?? '');
        if ($age > self::TOKEN_TTL) {
            $this->di['db']->trash($row);

            return null;
        }

        $clientId = (int) ($row->meta_value ?? 0);
        $this->di['db']->trash($row);

        if ($clientId <= 0) {
            return null;
        }

        return $clientId;
    }
}
