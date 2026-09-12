# Migration Center module — design

Date: 2026-09-12
Status: approved (design phase), not yet implemented

## Purpose

Let an existing MeroVPS client submit their **previous hosting
provider's** credentials to migration support staff, so staff can
manually migrate the client's site/data over. The module is built so a
later, separate project can add automated transfer without changing
the data model, client form, or staff queue shape.

## Scope (v1)

- Client-facing credential intake form + request list, reachable from
  the client dashboard and from a hosting service's detail page.
- Staff-facing admin queue: list, detail view (decrypt + view secret),
  status workflow, assignment, staff notes, manual secret purge.
- Encrypted-at-rest storage using the existing `$di['crypt']`
  (`Box_Crypt`) service — no new crypto.
- Permission-gated decryption (`manage_migrations`), separate from
  general module visibility (`view_migrations`).
- Email notifications on submission and on status change.
- Rate limiting on the client submission endpoint.
- A `MigrationAdapterInterface` + `method` column reserved for a
  future automated-transfer implementation; v1 ships only a
  `ManualAdapter` stub that is never actually invoked.

## Out of scope (v1)

- Any live connection to the client's previous host (no automated
  pull/verify/transfer).
- Coupling to the Support ticket module (this module owns its own
  status workflow independently).
- Automatic credential expiry/TTL (purge is a manual staff action).
- Per-service-type structured sub-forms (domain vs email vs DB) — the
  form is generic across panel types.

## Data model

New table, created via raw SQL in `Service::install()` (matching the
existing `Whmcsmigration` module's pattern — no Doctrine entity
required for a mod-owned table):

```sql
CREATE TABLE `mod_migrationcenter_request` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`         BIGINT UNSIGNED NOT NULL,
  `client_order_id`   BIGINT UNSIGNED NULL,
  `status`            VARCHAR(20) NOT NULL DEFAULT 'submitted',
  `method`            VARCHAR(20) NOT NULL DEFAULT 'manual',
  `panel_type`        VARCHAR(30) NOT NULL,
  `host`              VARCHAR(255) NOT NULL,
  `port`              SMALLINT UNSIGNED NULL,
  `username`          VARCHAR(255) NOT NULL,
  `secret_encrypted`  TEXT NOT NULL,
  `client_notes`      TEXT NULL,
  `staff_notes`       TEXT NULL,
  `assigned_staff_id` BIGINT UNSIGNED NULL,
  `created_at`        DATETIME NOT NULL,
  `updated_at`        DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

Notes:
- `status` values: `submitted`, `in_progress`, `completed`, `failed`,
  `cancelled`.
- `panel_type` values: `cpanel_whm`, `plesk`, `directadmin`,
  `ssh_custom`, `other`.
- `method` is always `'manual'` in v1; reserved for a future
  `'automated'` value once an adapter exists.
- `secret_encrypted` holds `$di['crypt']->encrypt(json_encode([
  'password' => ..., 'ssh_key' => ...]))` — bundling both fields into
  one encrypted blob means adding another secret field later doesn't
  require a schema migration.
- Plaintext secret values must never be written to any log
  (Monolog, exceptions surfaced to guests/clients) — mirrors the
  `Servicedomain` guest-exception-sanitization convention already in
  this codebase.

## Client-side flow

- Entry points: a "Migration Center" link in the client dashboard nav,
  and a "Request migration" button on a hosting service's detail page
  (pre-fills `client_order_id`).
- Form fields: panel type (select), host/IP, port (optional),
  username, password, SSH key (optional textarea), notes.
- `client/migrationcenter/request_create`: encrypts the secret blob
  immediately server-side; plaintext never persists; row created as
  `submitted`; triggers notifications; client is redirected to a
  confirmation page that shows status only, never the secret again.
- `client/migrationcenter/request_list` / `request_get`: returns the
  client's own requests with masked metadata (`host`, `username`,
  `panel_type`, `status`) — never the decrypted secret. The client API
  is write-only for the secret.
- `client/migrationcenter/request_cancel`: allowed only while status
  is `submitted`.

## Staff-side flow & permissions

- New admin nav entry "Migration Center" → list view: all requests,
  filterable by status, shows client, service, panel type, status,
  assigned staff. No secret in the list view.
- `Service::getModulePermissions()` declares:
  - `view_migrations` — see the list/metadata.
  - `manage_migrations` — decrypt/view secrets, change status, assign,
    edit staff notes, purge secret.
- Every `Api/Admin.php` method that touches `secret_encrypted` calls
  `$this->checkPermissions('migrationcenter', 'manage_migrations')`
  explicitly (mirrors the `Serviceserver`/`Sms`/`Whatsapp`
  `manage_settings` fix — module-level access alone must not imply
  secret access).
- Detail view (requires `manage_migrations`): decrypted host/
  username/password/SSH key/notes, assignment dropdown, status
  dropdown, staff-notes textarea, and a "Purge secret" button once
  status is `completed`/`failed`/`cancelled` (overwrites
  `secret_encrypted` with an empty encrypted value; irreversible,
  confirmed via the standard `fb_api_link` confirm-modal pattern).
- Status changes trigger client-facing notifications; staff notes stay
  internal.
- No coupling to the Support ticket module — this module owns its own
  workflow end to end.

## Notifications

Using the existing `Email`/`Notification` module conventions, no new
infrastructure:
- Client submits → email to staff with `manage_migrations`
  ("New migration request from {client}"); client gets a submission
  confirmation email (no secret echoed back).
- Staff changes status → client gets a status-change email,
  especially for `completed`/`failed`.

## Security hardening

- **Encryption**: `$di['crypt']->encrypt()`/`decrypt()` (`Box_Crypt`,
  AES-256-CBC), the same service already used for Extension config and
  Email template vars — no new crypto surface to audit.
- **Rate limiting**: two new `RateLimiter` policies —
  `migrationcenter_request_ip` and `migrationcenter_request_client` —
  on the submission endpoint, following the `Supportpin` precedent for
  a sensitive/abusable intake endpoint.
- **CSRF/forms**: standard `fb_api_form()`/`fb_api_link()` handling,
  nothing custom.
- **No plaintext in logs or errors**: decryption/encryption failures
  surface as a generic error, never the underlying exception message,
  to guests or clients.
- **No plaintext in list/summary payloads**: only the single admin
  detail-view endpoint (permission-gated) ever returns a decrypted
  secret; list/API-summary endpoints never include
  `secret_encrypted` or its decrypted form, encrypted or not.

## Future automation hook (v2 reservation, not implemented in v1)

```php
namespace Box\Mod\Migrationcenter\Adapter;

interface MigrationAdapterInterface
{
    public function connect(array $credentials): void;
    public function snapshot(): MigrationSnapshot;
    public function transfer(MigrationSnapshot $snapshot): void;
    public function verify(): bool;
}
```

- `MigrationSnapshot` is a v2 concern — a plain DTO describing what
  was found on the old host. v1 defines it as an empty marker class
  alongside the interface purely so the interface signature compiles;
  its shape is deliberately left to the v2 design pass.
- v1 ships only `ManualAdapter`, whose methods throw
  `NotImplementedException`; the admin UI never invokes the adapter —
  staff do everything by hand. `method` stays `'manual'` for every
  v1 request.
- A future, separately-designed v2 would add a concrete adapter (e.g.
  `CpanelAdapter`), let requests opt into `method = 'automated'`, and
  add a "Run transfer" staff action — without changing the table
  shape, the client form, or the staff queue.

## File layout

```
src/modules/Migrationcenter/
├── manifest.json
├── icon.svg
├── Service.php                     -- install(), getModulePermissions(), CRUD/status logic
├── Api/
│   ├── Client.php                  -- request_create, request_list, request_get, request_cancel
│   └── Admin.php                   -- request_list, request_get, request_update_status,
│                                       request_assign, request_purge_secret
├── Adapter/
│   ├── MigrationAdapterInterface.php
│   └── ManualAdapter.php
├── templates/
│   ├── client/mod_migrationcenter_index.html.twig
│   └── admin/mod_migrationcenter_index.html.twig
└── tests/Unit/...
```

- No `Controller/` beyond what the module system auto-wires for a
  simple Twig-page + API module (matching `Supportpin`'s shape).
- Client dashboard nav and hosting-service-detail-page integration use
  the existing widget/menu-injection pattern (no core template edits),
  per the `fossbilling-widget-system` convention already established
  in this codebase.

## Testing

- Unit tests for `Service.php` status transitions and permission
  checks (Pest, `src/modules/Migrationcenter/tests/Unit/`).
- Verify encryption round-trip via `$di['crypt']` in isolation.
- Verify a client without `manage_migrations` cannot retrieve a
  decrypted secret via any admin endpoint.
- Verify rate limiting rejects excessive `request_create` calls.
