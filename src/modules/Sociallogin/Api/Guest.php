<?php

declare(strict_types=1);

/**
 * Social Login module for FOSSBilling — Guest API.
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Sociallogin\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Guest extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Returns the social login configuration visible to unauthenticated clients.
     * Used by the login/signup templates to conditionally show the Google sign-in button.
     * google_client_id is semi-public (embedded in every GSI page) — client_secret is never exposed.
     *
     * @return array{google_enabled: bool, google_client_id: string}
     */
    public function get_config(): array
    {
        $enabled = $this->getService()->isGoogleEnabled();
        $config = $this->di['mod_config']('sociallogin');

        return [
            'google_enabled' => $enabled,
            'google_client_id' => $enabled ? (string) ($config['google_client_id'] ?? '') : '',
        ];
    }

    /**
     * Handles a Google Identity Services credential (id_token JWT).
     *
     * Called via a same-site fetch() from the login page after the user clicks
     * the personalized GSI button.  Because the request originates from our own
     * domain the browser sends the PHPSESSID cookie, so for already-linked
     * accounts we can write client_id to the session directly — no two-step
     * token bridge is needed.
     *
     * For new emails or unlinked accounts the endpoint returns a redirect URL
     * pointing to the appropriate pre-existing flow (complete-registration or
     * link-account), reusing all the same security controls.
     *
     * @return array{action: string, url: string}
     *
     * @throws \FOSSBilling\InformationException on invalid credential or account error
     */
    #[RequiredParams(['credential' => 'Google credential is required'])]
    public function handle_credential(array $data): array
    {
        $credential = trim((string) ($data['credential'] ?? ''));
        $context = in_array($data['context'] ?? '', ['login', 'register'], true)
            ? $data['context']
            : 'login';

        if ($credential === '') {
            throw new \FOSSBilling\InformationException('Missing credential.');
        }

        // Throttle per IP before the outbound tokeninfo call — this endpoint is unauthenticated
        // and proxies a request to Google on every hit, so an unbounded caller could burn Google
        // quota / amplify traffic. A real sign-in is a single click, well under the cap.
        $this->di['rate_limiter']->consumeOrThrow('sociallogin_credential_ip', (string) $this->ip);

        $config = $this->di['mod_config']('sociallogin');

        if (empty($config['google_enabled']) || empty($config['google_client_id'])) {
            throw new \FOSSBilling\InformationException('Google sign-in is not enabled.');
        }

        // Validate the id_token using Google's tokeninfo endpoint.
        // This verifies the JWT signature, expiry, and issuer, then returns the claims.
        $http = \Symfony\Component\HttpClient\HttpClient::create();

        try {
            $tokenInfo = $http->request('GET', 'https://oauth2.googleapis.com/tokeninfo', [
                'query' => ['id_token' => $credential],
            ]);
            $payload = $tokenInfo->toArray(false);
        } catch (\Exception $e) {
            $this->di['logger']->setChannel('sociallogin')->info('GSI tokeninfo request failed: ' . $e->getMessage());

            throw new \FOSSBilling\InformationException('Authentication failed. Please try again.');
        }

        // If the token is invalid or expired, tokeninfo returns an error field.
        if (!empty($payload['error']) || !empty($payload['error_description'])) {
            throw new \FOSSBilling\InformationException('Google authentication failed. Please sign in again.', [], 401);
        }

        // Explicit expiry check — tokeninfo validates this server-side but we assert it locally as well.
        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            throw new \FOSSBilling\InformationException('Google authentication failed. Please sign in again.', [], 401);
        }

        // Verify the token was issued for our application specifically. Per Google's guidance the
        // `aud` claim MUST equal our client id — this is the primary anti-replay binding, so it is
        // checked strictly (no `azp` fallback, which would accept a token minted for another app).
        $clientId = (string) $config['google_client_id'];
        $aud = (string) ($payload['aud'] ?? '');
        if (!hash_equals($clientId, $aud)) {
            $this->di['logger']->setChannel('sociallogin')->info('GSI credential audience mismatch.');

            throw new \FOSSBilling\InformationException('Authentication failed.', [], 401);
        }

        // Verify issuer.
        if (!in_array($payload['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new \FOSSBilling\InformationException('Authentication failed.', [], 401);
        }

        // Require a Google-verified email.
        $emailVerified = $payload['email_verified'] ?? false;
        if ($emailVerified !== true && $emailVerified !== 'true') {
            throw new \FOSSBilling\InformationException('Google did not provide a verified email address.');
        }

        $email = strtolower(trim($payload['email'] ?? ''));
        if ($email === '') {
            throw new \FOSSBilling\InformationException('Authentication failed.');
        }

        $firstName = (string) ($payload['given_name'] ?? '');
        $lastName = (string) ($payload['family_name'] ?? '');
        if ($firstName === '') {
            $parts = explode(' ', $payload['name'] ?? 'User', 2);
            $firstName = $parts[0];
            $lastName = $parts[1] ?? '';
        }

        /** @var \Box\Mod\Sociallogin\Service $service */
        $service = $this->getService();
        $existingClient = $service->findClientByEmail($email);

        // Register context + email already in system → signal the signup page.
        if ($context === 'register' && $existingClient !== null) {
            return [
                'action' => 'redirect',
                'url' => rtrim(SYSTEM_URL, '/') . '/signup?oauth_error=account_exists',
            ];
        }

        // Unknown email → pre-registration form.
        if ($existingClient === null) {
            $regToken = bin2hex(random_bytes(32));
            $service->storePendingRegistrationToken($regToken, [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);

            return [
                'action' => 'redirect',
                'url' => rtrim(SYSTEM_URL, '/') . '/sociallogin/complete-registration?token=' . $regToken,
            ];
        }

        $client = $existingClient;

        if ($client->status !== \Model_Client::ACTIVE) {
            throw new \FOSSBilling\InformationException('Your account is suspended. Please contact support.');
        }

        // Email known but Google not yet linked → show link-confirmation page.
        if (!$service->isGoogleLinked($client)) {
            $linkToken = bin2hex(random_bytes(32));
            $service->storePendingLinkToken($linkToken, (int) $client->id);

            return [
                'action' => 'redirect',
                'url' => rtrim(SYSTEM_URL, '/') . '/sociallogin/link-account?token=' . $linkToken,
            ];
        }

        // Fully linked — this fetch() is same-site so we write the session directly.
        $eventParams = ['ip' => $this->ip, 'email' => $client->email];
        $this->di['events_manager']->fire(['event' => 'onBeforeClientLogin', 'params' => $eventParams]);

        // Regenerate the session on login (session-fixation hardening) and carry
        // the pre-login guest cart across. The cart is keyed by session_id, so
        // without regenerate + transfer the new session would show an empty cart —
        // wiping an in-progress order. Mirrors the email/password login
        // (Client\Api\Guest::login) and the OAuth complete_login(). The transfer
        // must run BEFORE onAfterClientLogin: the Cart login listener calls
        // getSessionCart(), which would otherwise persist an empty cart under the
        // new session and shadow the real one.
        $oldSession = $this->di['session']->getId();
        $this->di['session']->regenerateId();
        $this->di['session']->set('client_id', $client->id);
        $this->di['logger']->info('Client #%s logged in via Google Identity Services (GSI)', $client->id);

        $this->di['mod_service']('cart')->transferFromOtherSession($oldSession);

        $this->di['events_manager']->fire([
            'event' => 'onAfterClientLogin',
            'params' => ['id' => $client->id, 'ip' => $this->ip],
        ]);

        // Honor the order-flow return destination (e.g. /order/checkout) so the
        // user resumes checkout instead of being dropped on the dashboard.
        $returnTo = $this->sanitizeReturnTo((string) ($data['return_to'] ?? ''));
        $afterLoginUrl = $returnTo !== ''
            ? $this->di['url']->link(ltrim($returnTo, '/'))
            : $this->di['url']->link('dashboard');

        return [
            'action' => 'redirect',
            'url' => $afterLoginUrl,
        ];
    }

    /**
     * Validate that a return_to value is a local path (no scheme/host) to prevent
     * open redirect. Mirrors Sociallogin\Controller\Client::sanitizeReturnTo().
     */
    private function sanitizeReturnTo(string $raw): string
    {
        $path = trim($raw);

        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, '://') || str_starts_with($path, '//')) {
            return '';
        }

        if (!preg_match('#^[/a-zA-Z0-9\-_.~%?=&]+$#', $path)) {
            return '';
        }

        return $path;
    }

    /**
     * Confirms an account link and logs the user in.
     *
     * Called via a same-site fetch() from the link-confirmation page.
     * Validates the pending-link token, records the Google link permanently on
     * the client account, then opens the session — identical to complete_login.
     *
     * @throws \FOSSBilling\InformationException on invalid/expired token
     */
    #[RequiredParams(['token' => 'Link token is required'])]
    public function confirm_account_link(array $data): bool
    {
        $token = trim((string) ($data['token'] ?? ''));

        if ($token === '') {
            throw new \FOSSBilling\InformationException('Missing link token.');
        }

        /** @var \Box\Mod\Sociallogin\Service $service */
        $service = $this->getService();

        $clientId = $service->consumePendingLinkToken($token);

        if ($clientId === null) {
            throw new \FOSSBilling\InformationException('Invalid or expired link token. Please try signing in again.', [], 401);
        }

        $client = $this->di['db']->load('Client', $clientId);

        if (!$client instanceof \Model_Client || $client->status !== \Model_Client::ACTIVE) {
            throw new \FOSSBilling\InformationException('Account is unavailable.', [], 401);
        }

        // Permanently record the Google link so future logins skip this step.
        $service->linkGoogle($client);

        // Fire pre/post login events (activity log, hooks, etc.)
        $eventParams = ['ip' => $this->ip, 'email' => $client->email];
        $this->di['events_manager']->fire(['event' => 'onBeforeClientLogin', 'params' => $eventParams]);

        // Regenerate the session on login (session-fixation hardening) — mirrors the official
        // Client\Api\Guest::login and handle_credential(). The guest cart lives under the pre-OAuth
        // session (old_session_id from the signed state) and is re-keyed to the regenerated session
        // below, before onAfterClientLogin.
        $this->di['session']->regenerateId();
        $this->di['session']->set('client_id', $client->id);
        $this->di['logger']->info('Client #%s linked and logged in via Google OAuth', $client->id);

        // Transfer the guest cart BEFORE firing onAfterClientLogin — see complete_login().
        $oldSessionId = trim((string) ($data['old_session_id'] ?? ''));
        if ($oldSessionId !== '' && preg_match('/^[a-zA-Z0-9,\-]{10,128}$/', $oldSessionId)) {
            $this->di['mod_service']('cart')->transferFromOtherSession($oldSessionId);
        }

        $this->di['events_manager']->fire([
            'event' => 'onAfterClientLogin',
            'params' => ['id' => $client->id, 'ip' => $this->ip],
        ]);

        return true;
    }

    /**
     * Finalises a Google-assisted registration.
     *
     * Called via a same-site fetch() from the completion form page.  The
     * pre-registration token proves the email was Google-verified; the rest
     * of $data contains the fields the user filled in on the form.
     *
     * @param array $data Must include 'token'. All other fields mirror
     *                    the standard guest.client_create payload (first_name,
     *                    last_name, phone, country, currency, etc.).
     *
     * @return bool true on success
     *
     * @throws \FOSSBilling\InformationException on invalid token or account error
     */
    #[RequiredParams(['token' => 'Registration token is required'])]
    public function complete_registration(array $data): bool
    {
        $token = trim((string) ($data['token'] ?? ''));

        if ($token === '') {
            throw new \FOSSBilling\InformationException('Missing registration token.');
        }

        /** @var \Box\Mod\Sociallogin\Service $service */
        $service = $this->getService();

        $profile = $service->peekPendingRegistrationToken($token);

        if ($profile === null) {
            throw new \FOSSBilling\InformationException('Invalid or expired registration token.', [], 401);
        }

        $email = $profile['email'];
        $firstName = !empty($data['first_name']) ? (string) $data['first_name'] : $profile['first_name'];
        $lastName = !empty($data['last_name']) ? (string) $data['last_name'] : $profile['last_name'];

        // Ensure the email hasn't been registered in the meantime.
        if ($service->findClientByEmail($email) !== null) {
            $service->consumePendingRegistrationToken($token);

            throw new \FOSSBilling\InformationException('An account with this email already exists. Please sign in instead.', [], 409);
        }

        // Build the client data array — email and auth_type are fixed.
        $clientData = [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password' => bin2hex(random_bytes(20)), // random; only social login is used
            'auth_type' => 'google',
        ];

        // Pass through optional fields the user filled in.
        foreach (['phone_cc', 'phone', 'company', 'gender', 'birthday',
            'address_1', 'address_2', 'city', 'state', 'postcode',
            'country', 'currency', 'agree_tos'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $clientData[$field] = $data[$field];
            }
        }

        try {
            $clientService = $this->di['mod_service']('client');
            $newClient = $clientService->oauthCreateClient($clientData);
        } catch (\Exception $e) {
            throw new \FOSSBilling\InformationException('Could not create account: ' . $e->getMessage());
        }

        if (!$newClient instanceof \Model_Client) {
            throw new \FOSSBilling\InformationException('Account creation failed. Please contact support.');
        }

        $client = $newClient;

        // Mark email as verified — Google already verified it.
        $client->email_approved = 1;
        $this->di['db']->store($client);

        // Consume the registration token — it is no longer valid.
        $service->consumePendingRegistrationToken($token);

        if ($client->status !== \Model_Client::ACTIVE) {
            throw new \FOSSBilling\InformationException('Your account is not active. Please contact support.', [], 403);
        }

        // Fire standard login events and open the session.
        $eventParams = ['ip' => $this->ip, 'email' => $client->email];
        $this->di['events_manager']->fire(['event' => 'onBeforeClientLogin', 'params' => $eventParams]);

        // Regenerate the session on login (session-fixation hardening) — mirrors the official
        // Client\Api\Guest::login and handle_credential(). Cart re-keyed from the signed-state
        // old_session_id below, before onAfterClientLogin.
        $this->di['session']->regenerateId();
        $this->di['session']->set('client_id', $client->id);
        $this->di['logger']->info('Client #%s registered and logged in via Google OAuth', $client->id);

        // Transfer the guest cart BEFORE firing onAfterClientLogin — see complete_login().
        $oldSessionId = trim((string) ($data['old_session_id'] ?? ''));
        if ($oldSessionId !== '' && preg_match('/^[a-zA-Z0-9,\-]{10,128}$/', $oldSessionId)) {
            $this->di['mod_service']('cart')->transferFromOtherSession($oldSessionId);
        }

        $this->di['events_manager']->fire([
            'event' => 'onAfterClientLogin',
            'params' => ['id' => $client->id, 'ip' => $this->ip],
        ]);

        return true;
    }

    /**
     * Finalises a Google OAuth login using a one-time token generated by the
     * callback controller.
     *
     * This endpoint is called via a same-site fetch() request from the
     * transition HTML page that the callback renders.  Because the request is
     * same-site, the browser sends the existing PHPSESSID cookie, giving us a
     * properly fingerprinted session to write client_id into.
     *
     * @throws \FOSSBilling\InformationException on invalid / expired token
     */
    #[RequiredParams(['token' => 'Login token is required'])]
    public function complete_login(array $data): bool
    {
        $token = trim((string) ($data['token'] ?? ''));

        if ($token === '') {
            throw new \FOSSBilling\InformationException('Missing login token.');
        }

        /** @var \Box\Mod\Sociallogin\Service $service */
        $service = $this->getService();

        $clientId = $service->consumePendingLoginToken($token);

        if ($clientId === null) {
            throw new \FOSSBilling\InformationException('Invalid or expired login token.', [], 401);
        }

        $client = $this->di['db']->load('Client', $clientId);

        if (!$client instanceof \Model_Client || $client->status !== \Model_Client::ACTIVE) {
            throw new \FOSSBilling\InformationException('Account is unavailable.', [], 401);
        }

        // Fire pre/post login events (activity log, hooks, etc.)
        $eventParams = ['ip' => $this->ip, 'email' => $client->email];
        $this->di['events_manager']->fire(['event' => 'onBeforeClientLogin', 'params' => $eventParams]);

        // Regenerate the session on login (session-fixation hardening) — mirrors the official
        // Client\Api\Guest::login and handle_credential(). The cart is re-keyed from the
        // signed-state old_session_id below, before onAfterClientLogin.
        $this->di['session']->regenerateId();
        $this->di['session']->set('client_id', $client->id);
        $this->di['logger']->info('Client #%s logged in via Google OAuth', $client->id);

        // Transfer the guest cart (pre-login session) to the now-authenticated
        // session BEFORE firing onAfterClientLogin. The Cart module's login
        // listener calls getSessionCart(), which auto-creates and persists an
        // empty cart under the current session when none exists — if it ran
        // before the transfer, that empty cart would shadow the real guest cart
        // and the cart would appear wiped. The old session ID is passed from the
        // OAuth state through the inline JS body.
        $oldSessionId = trim((string) ($data['old_session_id'] ?? ''));
        if ($oldSessionId !== '' && preg_match('/^[a-zA-Z0-9,\-]{10,128}$/', $oldSessionId)) {
            $this->di['mod_service']('cart')->transferFromOtherSession($oldSessionId);
        }

        $this->di['events_manager']->fire([
            'event' => 'onAfterClientLogin',
            'params' => ['id' => $client->id, 'ip' => $this->ip],
        ]);

        return true;
    }
}
