# FOSSBilling Custom Theme & Module — Security Audit Checklist (AI Agent Edition)

> **Purpose:** Machine-actionable security audit instructions for an AI coding agent auditing custom themes and modules built on FOSSBilling, including external integrations (Domain Registrar APIs, cPanel/WHM).
> **Stack context:** PHP 8.x, FOSSBilling core, Twig templating, RedBeanPHP ORM, custom modules under `src/modules/`, custom themes under `themes/`.
> **Output format expected from agent:** For each finding, report: `[SEVERITY] [FILE:LINE] [RULE-ID] Description + Suggested Fix + Code Snippet`.
> **Severity levels:** `CRITICAL` | `HIGH` | `MEDIUM` | `LOW` | `INFO`

---

## SECTION 1 — Module API Endpoint Security (FOSSBilling-specific)

FOSSBilling exposes module methods through three API surfaces: **Guest** (`Api/Guest.php` — unauthenticated), **Client** (`Api/Client.php` — logged-in customer), **Admin** (`Api/Admin.php` — staff). Misplacing a method in the wrong class is the #1 vulnerability class in FOSSBilling modules.

### RULE-101: Guest API exposure audit `[CRITICAL]`
- [ ] Enumerate every public method in each module's `Api/Guest.php`
- [ ] Flag any Guest method that reads/writes billing data, provisioning data, server credentials, or other users' records
- [ ] Flag any Guest method that triggers external API calls (registrar, cPanel) — potential abuse/DoS/cost amplification vector
- [ ] Verify no method in `Api/Guest.php` performs privileged actions "temporarily for testing"
- **Grep hints:** `class Guest extends`, `->di\['db'\]`, `curl_`, `Http`

### RULE-102: Client API object ownership (IDOR) `[CRITICAL]`
- [ ] Every Client API method that accepts an `id`, `order_id`, `domain_id`, `service_id`, etc. MUST verify the record belongs to the authenticated client
- [ ] Pattern to require: load record, then compare `$model->client_id === $this->getIdentity()->id` (or use core service methods that already scope by identity)
- [ ] Flag any `findOne`/`load` by raw ID from `$data` without an ownership condition in the query or a post-load check
- **Grep hints:** `\$data\['id'\]`, `getExistingModelById`, `findOne\(`, missing `client_id`

### RULE-103: Admin API privilege checks `[HIGH]`
- [ ] Verify admin methods that perform destructive/sensitive ops respect FOSSBilling staff permissions (`Staff` service ACL) where role separation matters
- [ ] Flag admin methods callable with side effects but no permission gating beyond "is admin"
- [ ] Check module `getModulePermissions()` is implemented if the module defines granular permissions

### RULE-104: Input validation on all API methods `[HIGH]`
- [ ] Every API method must validate required params via `$this->di['validator']->checkRequiredParamsForArray()` or equivalent before use
- [ ] Type-check/whitelist enum-like params (e.g., TLD, plan name, action verbs) — never pass raw user strings into provisioning calls
- [ ] Flag direct use of `$data[...]` in DB queries, shell commands, HTTP calls, or file paths without validation
- [ ] Check integer params are cast (`(int)`) before use in queries or loops

### RULE-105: CSRF on custom controllers `[HIGH]`
- [ ] Custom `Controller/` routes that mutate state via GET must be flagged (state changes must be POST)
- [ ] Verify FOSSBilling's CSRF token mechanism is used on custom forms (API calls via the JS wrapper include CSRF automatically; hand-rolled AJAX/fetch calls may not)
- [ ] Flag any hand-written `fetch()`/`$.post()` in theme JS hitting `/api/admin/` or `/api/client/` without the CSRF token

---

## SECTION 2 — Twig Theme Security

### RULE-201: XSS via `|raw` filter `[CRITICAL]`
- [ ] Grep all theme templates for `|raw` — each usage must be justified; flag any where the variable can contain user-controlled data (client names, domain names, ticket messages, custom field values, invoice notes)
- [ ] Flag `autoescape false` blocks
- [ ] Check admin-area templates too — stored XSS from client input rendered in admin panel = admin account takeover
- **Grep hints:** `\|raw`, `autoescape false`, `{% raw %}` misuse

### RULE-202: Server-Side Template Injection (SSTI) `[CRITICAL]`
- [ ] Flag any PHP code that renders a Twig template string built from user input (`createTemplate`, `render` on dynamic strings, email template previews fed raw user content)
- [ ] Verify custom email templates editable by non-super-admin roles cannot inject Twig calling dangerous functions
- [ ] Check the Twig sandbox/policy is not disabled or widened in custom code

### RULE-203: Template output contexts `[HIGH]`
- [ ] Variables inside `<script>` blocks must use `|json_encode` (or `|e('js')`), not default HTML escaping
- [ ] Variables inside `href`/`src` attributes: check for `javascript:` injection; validate/whitelist URLs
- [ ] Inline event handlers (`onclick="...{{ var }}..."`) — flag all occurrences

### RULE-204: Theme asset & settings handling `[MEDIUM]`
- [ ] Theme settings (`settings_data.json` / theme config) rendered into templates: confirm escaping applies
- [ ] No secrets (API keys, tracking tokens beyond public ones) committed inside theme files
- [ ] Check custom asset upload features (logo upload, etc.) validate file type by content, not extension alone

---

## SECTION 3 — External API Integrations (Domain Registrar)

### RULE-301: Credential storage `[CRITICAL]`
- [ ] Registrar API keys must NOT be hardcoded in module PHP, committed config files, or theme files
- [ ] Verify credentials are stored via FOSSBilling's registrar adapter config (DB-stored config) and never echoed back in full to the browser (mask in admin UI responses)
- [ ] Grep for hardcoded secrets: `api_key\s*=`, `password\s*=`, `Bearer `, long base64/hex literals
- [ ] Check `config.php`, `.env`, and any `*.sample`/backup files in the repo for real credentials

### RULE-302: TLS verification `[CRITICAL]`
- [ ] Flag any HTTP client with `CURLOPT_SSL_VERIFYPEER => false`, `verify => false` (Guzzle/Symfony HttpClient), or `CURLOPT_SSL_VERIFYHOST => 0`
- [ ] All registrar/cPanel endpoints must be HTTPS — flag `http://` URLs in integration code

### RULE-303: SSRF & URL construction `[HIGH]`
- [ ] Flag any outbound request URL built from user-supplied input (hostname, callback URL, "server IP" fields) without validation/whitelisting
- [ ] Domain names passed to registrar APIs: validate against strict domain regex + IDN handling before use
- [ ] Block internal ranges if any user-controllable URL fetch exists (169.254.169.254, 127.0.0.0/8, 10/8, 172.16/12, 192.168/16)

### RULE-304: Response handling & injection `[HIGH]`
- [ ] Registrar API responses (WHOIS data, contact info, error messages) rendered in templates must be escaped — treat third-party API responses as untrusted
- [ ] XML responses parsed with `libxml` must disable external entities (XXE): flag `LIBXML_NOENT`, verify `libxml_disable_entity_loader` behavior on the PHP version in use
- [ ] JSON parsing: check for blind `json_decode` then property access without existence/type checks (error-path info leaks)

### RULE-305: Error & logging hygiene `[MEDIUM]`
- [ ] API errors shown to clients must be generic; full registrar error (which may include credentials/request dumps) goes to logs only
- [ ] Verify logs do not contain API keys, EPP/auth codes, or full request bodies with secrets — flag any `logger->info($request)` style dumps
- [ ] Domain transfer EPP/auth codes: never logged, shown only to the owning client, transmitted over HTTPS only

### RULE-306: Webhooks/callbacks from registrar `[HIGH]`
- [ ] If the module receives callbacks: verify signature/HMAC or shared-secret validation exists
- [ ] Flag callback endpoints that mutate order/domain state based solely on an ID in the payload

---

## SECTION 4 — cPanel / WHM Integration

### RULE-401: Token scope & storage `[CRITICAL]`
- [ ] WHM API tokens: verify least-privilege ACLs on the token (module should not require a full-root token if it only creates/suspends accounts) — document required ACL list
- [ ] Tokens stored in server manager config (DB) — flag any token in code, theme, or JS delivered to the browser
- [ ] Confirm the admin UI masks the token on display and does not return it via any Client/Guest API

### RULE-402: Account provisioning parameter injection `[CRITICAL]`
- [ ] Username generation: validate/sanitize derived usernames (length, charset `[a-z0-9]`, no reserved names: `root`, `admin`, `test`)
- [ ] Passwords for created accounts: generated with CSPRNG (`random_bytes`/`bin2hex`), sufficient length (16+), never emailed in plaintext if avoidable, never logged
- [ ] All values passed into WHM API 1 / cPanel UAPI query strings must be `urlencode`d/`http_build_query`'d — flag manual string concatenation into API URLs
- [ ] Flag ANY use of `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, backticks in module code — cPanel work must go through the HTTP API, never SSH/shell from the billing box

### RULE-403: Cross-account operations `[CRITICAL]`
- [ ] Suspend/unsuspend/terminate/change-password calls: verify the cPanel username is resolved from the authenticated client's own service record, never accepted from request input
- [ ] SSO/auto-login (`create_user_session` / session tokens): verify the session URL is generated for the record owner only, delivered over HTTPS, short-lived, and never cached/logged

### RULE-404: Server manager transport `[HIGH]`
- [ ] WHM calls on port 2087 (TLS) only — flag 2086/plain HTTP
- [ ] Certificate verification enabled even for self-signed setups (pin or install proper cert; if verification is disabled for "self-signed convenience," report as HIGH with remediation)

---

## SECTION 5 — Database Layer (RedBeanPHP)

### RULE-501: SQL injection `[CRITICAL]`
- [ ] All `$di['db']->getAll/getRow/getCell/exec/find/findOne` calls with SQL fragments must use parameter bindings (`:param` / `?`) — flag any string interpolation or concatenation of variables into SQL
- [ ] `ORDER BY` / `LIMIT` / column names from user input: must be whitelisted against a fixed array (bindings don't work for identifiers)
- [ ] Search/filter features in custom admin grids: audit the query builder path end-to-end
- **Grep hints:** `getAll\(".*\$`, `->exec\(.*\.\s*\$`, `"%\$`, `LIKE '%.*\$`

### RULE-502: Mass assignment `[MEDIUM]`
- [ ] Flag loops like `foreach ($data as $k => $v) { $model->$k = $v; }` — attacker can overwrite `client_id`, `status`, `price` fields
- [ ] Bean import (`$model->import($data)`) must use an explicit whitelist of allowed fields

---

## SECTION 6 — Files, Uploads, Paths

### RULE-601: Path traversal `[CRITICAL]`
- [ ] Any file path built with user input (template name, attachment name, invoice PDF ID, theme preview) — flag missing `basename()`/realpath containment check
- [ ] Download endpoints: verify the resolved path stays inside the intended directory (`str_starts_with(realpath($p), $baseDir)`)

### RULE-602: Uploads `[HIGH]`
- [ ] Content-based MIME validation (finfo), not extension/`$_FILES['type']`
- [ ] Uploaded files stored outside webroot or with non-executable names; flag anything that allows `.php`, `.phtml`, `.phar`, double extensions, or SVG (stored XSS) without sanitization
- [ ] Randomized stored filenames; original name only used as display metadata (escaped)

---

## SECTION 7 — Sessions, Auth & Platform Hygiene

### RULE-701: Session & cookie flags `[MEDIUM]`
- [ ] `Secure`, `HttpOnly`, `SameSite` on session cookies (check config + any custom `setcookie` calls)
- [ ] Custom "remember me" or token features: tokens hashed at rest, CSPRNG-generated, expiring

### RULE-702: Sensitive data exposure `[HIGH]`
- [ ] `Debug`/`Telescope-style` tooling, `display_errors`, stack traces disabled in production config
- [ ] Flag `var_dump`, `print_r`, `dd(` left in module/theme code
- [ ] Check `robots.txt`/webserver rules don't expose `/data/`, `/config.php`, `.git/`, backup files (`*.sql`, `*.zip`, `*.bak`) in the deployment

### RULE-703: Rate limiting & abuse `[MEDIUM]`
- [ ] Domain availability check (Guest-accessible) must be rate-limited/captcha'd — it proxies paid registrar API calls
- [ ] Login, password reset, and any Guest API method that sends email or hits external APIs: verify throttling exists

### RULE-704: Dependency & core hygiene `[MEDIUM]`
- [ ] `composer audit` clean; no abandoned packages in module `composer.json`
- [ ] Custom modules do not modify FOSSBilling core files (breaks update path → stuck on vulnerable versions); use hooks/events instead
- [ ] FOSSBilling version is current stable; document version at audit time

---

## SECTION 8 — Hooks & Event Handlers

### RULE-801: Event handler trust `[MEDIUM]`
- [ ] `onBefore*/onAfter*` hooks receiving event params: treat params as untrusted if the originating event can be triggered from Client/Guest surface
- [ ] Hooks that call external APIs: verify failures are handled (no fatal that blocks core flow, no infinite retry loops hammering registrar)

---

## AGENT EXECUTION ORDER

1. **Inventory:** List all custom modules (`src/modules/*` not in core), custom themes, and every `Api/Guest.php`, `Api/Client.php`, `Api/Admin.php`, `Controller/*.php`.
2. **Pass 1 (CRITICAL rules):** 101, 102, 201, 202, 301, 302, 401, 402, 403, 501, 601.
3. **Pass 2 (HIGH):** 103, 104, 105, 203, 303, 304, 306, 404, 602, 702.
4. **Pass 3 (MEDIUM/LOW):** remaining rules.
5. **Report:** Group findings by severity, include file:line, rule ID, minimal repro description, and a concrete patch suggestion. End with a summary table: `Rule ID | Count | Worst Severity | Status`.

## FALSE-POSITIVE GUIDANCE FOR AGENT

- `|raw` on developer-controlled static strings (icon SVGs defined in code) = INFO, not CRITICAL.
- Admin-only methods without per-field validation are HIGH only if params reach SQL/shell/HTTP unsanitized; otherwise MEDIUM.
- Do not flag core FOSSBilling files — audit scope is custom code only, but DO flag custom code that overrides/copies core files.