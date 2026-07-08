<?php

declare(strict_types=1);

/**
 * Social Login module for FOSSBilling — Client Controller.
 * Handles the Google OAuth2 start and callback routes.
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Sociallogin\Controller;

use FOSSBilling\InjectionAwareInterface;
use Symfony\Component\HttpClient\HttpClient;

class Client implements InjectionAwareInterface
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

    public function register(\Box_App &$app): void
    {
        $app->get('/sociallogin', 'sociallogin_index', [], static::class);
        $app->get('/sociallogin/google', 'google_start', [], static::class);
        $app->get('/sociallogin/google/callback', 'google_callback', [], static::class);
        $app->get('/sociallogin/complete-registration', 'complete_registration_page', [], static::class);
        $app->get('/sociallogin/link-account', 'link_account_page', [], static::class);
    }

    public function sociallogin_index(\Box_App $app): never
    {
        $config = $this->di['mod_config']('sociallogin');

        if (!empty($config['google_enabled'])) {
            $app->redirect('/sociallogin/google');
        }

        $app->redirect('/login');
    }

    // -------------------------------------------------------------------------
    // Account-link confirmation page.
    //
    // Shown when a user signs in via Google but the email already belongs to an
    // email/password account that has not yet authorised Google login.  The
    // pending-link token proves the Google OAuth completed successfully for this
    // email.  The user clicks one button to confirm; the Guest API then records
    // the link and opens the session.
    // -------------------------------------------------------------------------
    public function link_account_page(\Box_App $app): string
    {
        $token = (string) ($this->di['request']->query->get('token') ?? '');

        if ($token === '') {
            return $this->renderError('Invalid or missing link token.');
        }

        $row = $this->di['db']->findOne(
            'ExtensionMeta',
            "extension = 'mod_sociallogin' AND meta_key = ?",
            ['pending_link_' . $token]
        );

        if ($row === null) {
            return $this->renderError('Your link session has expired. Please try signing in again.');
        }

        $age = time() - (int) strtotime($row->created_at ?? '');
        if ($age > \Box\Mod\Sociallogin\Service::TOKEN_TTL) {
            $this->di['db']->trash($row);

            return $this->renderError('Your link session has expired. Please try signing in again.');
        }

        $client = $this->di['db']->load('Client', (int) $row->meta_value);

        if (!$client instanceof \Model_Client) {
            return $this->renderError('Account not found. Please contact support.');
        }

        // Mask the email for display: j***@example.com
        [$localPart, $domain] = array_pad(explode('@', $client->email, 2), 2, '');
        $maskedEmail = substr($localPart, 0, 1) . str_repeat('*', max(1, strlen($localPart) - 1)) . '@' . $domain;

        return $app->render('mod_sociallogin_link_account', [
            'token' => $token,
            'masked_email' => $maskedEmail,
            'return_to' => $this->sanitizeReturnTo((string) ($this->di['request']->query->get('return_to') ?? '')),
            'old_sid' => $this->sanitizeSessionId((string) ($this->di['request']->query->get('old_sid') ?? '')),
        ]);
    }

    // -------------------------------------------------------------------------
    // Pre-registration completion page.
    //
    // Renders the signup form pre-filled with Google profile data.  The token
    // proves the email was verified by Google in the OAuth flow.  The user
    // fills in any remaining required fields and submits; the Guest API
    // endpoint `sociallogin_complete_registration` creates the account and
    // sets the session, then JS redirects to /dashboard.
    // -------------------------------------------------------------------------
    public function complete_registration_page(\Box_App $app): string
    {
        $token = (string) ($this->di['request']->query->get('token') ?? '');

        if ($token === '') {
            return $this->renderError('Invalid or missing registration token.');
        }

        /** @var \Box\Mod\Sociallogin\Service $service */
        $service = $this->di['mod_service']('sociallogin');
        $profile = $service->peekPendingRegistrationToken($token);

        if ($profile === null) {
            return $this->renderError('Your sign-up session has expired. Please try again.');
        }

        return $app->render('mod_sociallogin_complete_registration', [
            'token' => $token,
            'email' => $profile['email'],
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name'],
            'return_to' => $this->sanitizeReturnTo((string) ($this->di['request']->query->get('return_to') ?? '')),
            'old_sid' => $this->sanitizeSessionId((string) ($this->di['request']->query->get('old_sid') ?? '')),
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 1 – Redirect browser to Google's OAuth2 endpoint.
    //
    // CSRF protection uses two layers:
    //   (a) A SameSite=Lax HttpOnly cookie (`oauth_state`) carries the nonce
    //       and survives the Google→callback top-level GET redirect while being
    //       invisible to cross-site frames.  The callback verifies the cookie
    //       matches the nonce in `state` before doing anything else.
    //   (b) The `state` HMAC covers ALL payload fields (nonce, context,
    //       return_to, old_sid) so none of them can be tampered with
    //       independently while keeping the signature valid.
    //
    // SameSite=Strict would block the cookie on the cross-site callback
    // redirect; SameSite=Lax allows it on top-level GET navigations.
    // -------------------------------------------------------------------------
    public function google_start(\Box_App $app): never
    {
        $config = $this->di['mod_config']('sociallogin');

        if (empty($config['google_enabled']) || empty($config['google_client_id']) || empty($config['google_client_secret'])) {
            $app->redirect('/login');
        }

        $rawContext = (string) ($this->di['request']->query->get('context') ?? 'login');
        $context = in_array($rawContext, ['login', 'register'], true) ? $rawContext : 'login';

        $rawReturnTo = (string) ($this->di['request']->query->get('return_to') ?? '');
        $returnTo = $this->sanitizeReturnTo($rawReturnTo);

        $nonce = bin2hex(random_bytes(16));
        $secret = \FOSSBilling\Config::getProperty('info.salt', 'fossbilling-social');

        // Capture and sanitize the pre-login session ID so the cart can be
        // transferred after OAuth completes.  Sanitized here so the value fed
        // into the HMAC matches exactly what the callback will sanitize to.
        $oldSid = $this->sanitizeSessionId(session_id());

        // HMAC covers every state field — no field can be swapped individually.
        $sig = hash_hmac('sha256', implode('|', [$nonce, $context, $returnTo, $oldSid]), $secret);

        // Bind this OAuth round-trip to the initiating browser via a short-lived
        // SameSite=Lax cookie.  The callback will reject any state whose nonce
        // does not match this cookie.
        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('oauth_state', $nonce, [
            'expires' => time() + 600,
            'path' => '/sociallogin/google/callback',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $isSecure,
        ]);

        $payload = json_encode(['nonce' => $nonce, 'sig' => $sig, 'context' => $context, 'return_to' => $returnTo, 'old_sid' => $oldSid]);
        $googleState = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        /** @var \Box\Mod\Sociallogin\Service $service */
        $service = $this->di['mod_service']('sociallogin');

        $params = http_build_query([
            'client_id' => $config['google_client_id'],
            'redirect_uri' => $service->getCallbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $googleState,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);

        $app->redirectUrl('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    }

    // -------------------------------------------------------------------------
    // Step 2 – Google redirects back here.
    //
    // WHY we render HTML instead of redirecting:
    //   Google's redirect is cross-site, so the browser does NOT send the
    //   PHPSESSID cookie (SameSite=Strict). Starting a new session here and
    //   saving client_id works for the write, but FOSSBilling's Fingerprint
    //   class stores browser fingerprint data from this cross-site request.
    //   On the very next same-site request (e.g. /dashboard), the fingerprint
    //   can differ causing the session to be destroyed, logging the user out.
    //
    // THE FIX – one-time token + same-site API call:
    //   1. Verify the HMAC state (no session required).
    //   2. Exchange the code, fetch Google user profile.
    //   3. Find or resolve the client account.
    //   4. Store a short-lived one-time token in `extension_meta`.
    //   5. Render a minimal HTML page.  JavaScript calls the same-site
    //      `guest.sociallogin_complete_login` API endpoint with the cookie
    //      present, writes the session correctly, then redirects.
    // -------------------------------------------------------------------------
    public function google_callback(\Box_App $app): string
    {
        $config = $this->di['mod_config']('sociallogin');
        /** @var \Box\Mod\Sociallogin\Service $service */
        $service = $this->di['mod_service']('sociallogin');

        $rawState = (string) ($this->di['request']->query->get('state') ?? '');
        $code = (string) ($this->di['request']->query->get('code') ?? '');

        // --- Verify HMAC-signed state (base64url) ---
        $stateData = json_decode(base64_decode(strtr($rawState, '-_', '+/')), true);
        $nonce = $stateData['nonce'] ?? '';
        $sig = $stateData['sig'] ?? '';
        $context = in_array($stateData['context'] ?? '', ['login', 'register'], true)
            ? $stateData['context']
            : 'login';
        $returnTo = $this->sanitizeReturnTo($stateData['return_to'] ?? '');
        $oldSid = $this->sanitizeSessionId($stateData['old_sid'] ?? '');
        $secret = \FOSSBilling\Config::getProperty('info.salt', 'fossbilling-social');

        // Layer 1: cookie binding — nonce must match the value we set before
        // redirecting to Google, proving this response belongs to this browser.
        $cookieNonce = (string) ($_COOKIE['oauth_state'] ?? '');
        if ($cookieNonce === '' || !hash_equals($nonce, $cookieNonce)) {
            return $this->renderError('Authentication failed (state mismatch). Please try again.');
        }

        // Layer 2: HMAC over all fields — proves none were tampered in transit.
        if ($nonce === '' || $sig === '' || !hash_equals(hash_hmac('sha256', implode('|', [$nonce, $context, $returnTo, $oldSid]), $secret), $sig)) {
            return $this->renderError('Authentication failed (state mismatch). Please try again.');
        }

        // Consume the cookie — one use only.
        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('oauth_state', '', ['expires' => time() - 3600, 'path' => '/sociallogin/google/callback', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $isSecure]);

        if ($code === '') {
            return $this->renderError('Google sign-in was cancelled or failed.');
        }

        // --- Exchange code for token ---
        try {
            $http = HttpClient::create();

            $tokenResponse = $http->request('POST', 'https://oauth2.googleapis.com/token', [
                'body' => [
                    'code' => $code,
                    'client_id' => $config['google_client_id'],
                    'client_secret' => $config['google_client_secret'],
                    'redirect_uri' => $service->getCallbackUrl(),
                    'grant_type' => 'authorization_code',
                ],
            ]);

            $tokenData = $tokenResponse->toArray(false);

            if (empty($tokenData['access_token'])) {
                $this->di['logger']->setChannel('sociallogin')->info('Google token exchange failed: ' . json_encode($tokenData));

                return $this->renderError('Google authentication failed. Please try again.');
            }

            $userResponse = $http->request('GET', 'https://www.googleapis.com/oauth2/v3/userinfo', [
                'headers' => ['Authorization' => 'Bearer ' . $tokenData['access_token']],
            ]);

            $userInfo = $userResponse->toArray(false);
        } catch (\Exception $e) {
            $this->di['logger']->setChannel('sociallogin')->info('Google API error: ' . $e->getMessage());

            return $this->renderError('Could not connect to Google. Please try again.');
        }

        // --- Validate user info ---
        $email = strtolower(trim($userInfo['email'] ?? ''));

        if ($email === '' || empty($userInfo['email_verified'])) {
            return $this->renderError('Google did not provide a verified email address.');
        }

        $firstName = $userInfo['given_name'] ?? '';
        $lastName = $userInfo['family_name'] ?? '';
        if ($firstName === '') {
            $parts = explode(' ', $userInfo['name'] ?? 'User', 2);
            $firstName = $parts[0];
            $lastName = $parts[1] ?? '';
        }

        $existingClient = $service->findClientByEmail($email);

        if ($context === 'register' && $existingClient !== null) {
            $signupUrl = json_encode(rtrim(SYSTEM_URL, '/') . '/signup?oauth_error=account_exists', JSON_UNESCAPED_SLASHES);

            return <<<HTML
                <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head>
                <body><script>window.location.replace({$signupUrl});</script></body></html>
                HTML;
        }

        if ($existingClient === null) {
            $regToken = bin2hex(random_bytes(32));
            $service->storePendingRegistrationToken($regToken, [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);

            $regUrlPath = '/sociallogin/complete-registration?token=' . $regToken
                . ($returnTo !== '' ? '&return_to=' . rawurlencode($returnTo) : '')
                . ($oldSid !== '' ? '&old_sid=' . rawurlencode($oldSid) : '');
            $regUrl = json_encode(rtrim(SYSTEM_URL, '/') . $regUrlPath, JSON_UNESCAPED_SLASHES);

            return <<<HTML
                <!DOCTYPE html>
                <html lang="en">
                <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Almost there…</title>
                <style>
                  body { margin: 0; display: flex; align-items: center; justify-content: center;
                         min-height: 100vh; background: #0f1117; font-family: system-ui, sans-serif; color: #e2e8f0; }
                  .box { text-align: center; }
                  .spinner { width: 40px; height: 40px; border: 3px solid #4f46e5; border-top-color: transparent;
                             border-radius: 50%; animation: spin .8s linear infinite; margin: 0 auto 1rem; }
                  @keyframes spin { to { transform: rotate(360deg); } }
                  p { color: #94a3b8; font-size: .9rem; }
                </style>
                </head>
                <body>
                <div class="box">
                  <div class="spinner"></div>
                  <p>Just a moment…</p>
                </div>
                <script>window.location.replace({$regUrl});</script>
                </body>
                </html>
                HTML;
        }

        // --- Login flow: email is known — check Google link status ---
        $client = $existingClient;

        if ($client->status !== \Model_Client::ACTIVE) {
            return $this->renderError('Your account is suspended. Please contact support.');
        }

        if (!$service->isGoogleLinked($client)) {
            $linkToken = bin2hex(random_bytes(32));
            $service->storePendingLinkToken($linkToken, (int) $client->id);

            $linkUrlPath = '/sociallogin/link-account?token=' . $linkToken
                . ($returnTo !== '' ? '&return_to=' . rawurlencode($returnTo) : '')
                . ($oldSid !== '' ? '&old_sid=' . rawurlencode($oldSid) : '');
            $linkUrl = json_encode(rtrim(SYSTEM_URL, '/') . $linkUrlPath, JSON_UNESCAPED_SLASHES);

            return <<<HTML
                <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head>
                <body><script>window.location.replace({$linkUrl});</script></body></html>
                HTML;
        }

        // --- Store a one-time login token (valid 5 minutes) ---
        $token = bin2hex(random_bytes(32));
        $service->storePendingLoginToken($token, (int) $client->id);

        // --- Render a minimal HTML page with a same-site JS API call ---
        $afterLoginUrl = $returnTo !== ''
            ? $this->di['url']->link(ltrim($returnTo, '/'))
            : $this->di['url']->link('dashboard');
        $dashboardUrl = json_encode($afterLoginUrl, JSON_UNESCAPED_SLASHES);
        $loginErrUrl = json_encode($this->di['url']->link('login') . '?error=auth_failed', JSON_UNESCAPED_SLASHES);
        $apiUrl = json_encode(rtrim(SYSTEM_URL, '/') . '/api/guest/sociallogin/complete_login', JSON_UNESCAPED_SLASHES);
        $tokenSafe = $token; // bin2hex — hex-only, safe in any string context
        $oldSidParam = $oldSid !== '' ? '&old_session_id=' . rawurlencode($oldSid) : '';

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Signing in…</title>
            <style>
              body { margin: 0; display: flex; align-items: center; justify-content: center;
                     min-height: 100vh; background: #0f1117; font-family: system-ui, sans-serif; color: #e2e8f0; }
              .box { text-align: center; }
              .spinner { width: 40px; height: 40px; border: 3px solid #4f46e5; border-top-color: transparent;
                         border-radius: 50%; animation: spin .8s linear infinite; margin: 0 auto 1rem; }
              @keyframes spin { to { transform: rotate(360deg); } }
              p { color: #94a3b8; font-size: .9rem; }
            </style>
            </head>
            <body>
            <div class="box">
              <div class="spinner"></div>
              <p>Signing you in…</p>
            </div>
            <script>
            (function () {
              fetch({$apiUrl}, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'token={$tokenSafe}{$oldSidParam}'
              })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                if (data && data.result) {
                  window.location.replace({$dashboardUrl});
                } else {
                  window.location.replace({$loginErrUrl});
                }
              })
              .catch(function () {
                window.location.replace({$loginErrUrl});
              });
            }());
            </script>
            </body>
            </html>
            HTML;
    }

    /**
     * Validate that a session ID contains only safe characters (PHP session IDs are hex/alphanumeric).
     */
    private function sanitizeSessionId(string $raw): string
    {
        $sid = trim($raw);

        return preg_match('/^[a-zA-Z0-9,\-]{10,128}$/', $sid) ? $sid : '';
    }

    /**
     * Validate that a return_to value is a local path (no scheme/host) to prevent open redirect.
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

    private function renderError(string $message): string
    {
        $loginUrl = htmlspecialchars($this->di['url']->link('login'), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Sign-in error</title>
            <style>
              body { margin: 0; display: flex; align-items: center; justify-content: center;
                     min-height: 100vh; background: #0f1117; font-family: system-ui, sans-serif; color: #e2e8f0; }
              .box { text-align: center; max-width: 380px; padding: 2rem; }
              h1 { font-size: 1.25rem; margin-bottom: .5rem; }
              p { color: #94a3b8; font-size: .9rem; margin-bottom: 1.5rem; }
              a { color: #6366f1; text-decoration: none; }
            </style>
            </head>
            <body>
            <div class="box">
              <h1>Sign-in failed</h1>
              <p>{$message}</p>
              <a href="{$loginUrl}">← Back to sign in</a>
            </div>
            </body>
            </html>
            HTML;
    }
}
