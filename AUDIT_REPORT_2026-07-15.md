# FOSSBilling 0.8.4 Security & Performance Audit — 2026-07-15

> **STATUS UPDATE (same day):** Items #1–#7, #9 (honeypot on signup; Antispam turned
> out to be a core module, always active) and #10 (cron: backlog run with nulled mail
> transport, 118 stale queued emails discarded, crontab installed every 5 min) are
> **APPLIED and verified** — smoke suite 11/11. `install.bak/` + `config.old.php`
> moved to `FOSSBilling/docroot-quarantine/`. Remaining open: #8/#11 production
> config hardening (debug, force_https, DB password) — intentionally left for the
> production deployment, as applying them breaks local development.

Audited against the official 0.7 → 0.8 migration guide
(https://docs.fossbilling.org/maintenance/updating/0-7-to-0-8/) plus the local-patch
inventory in CLAUDE.md. Verified by live probing `localhost:9000`, the database, and
source inspection.

## What passed ✅

| Check | Status |
|---|---|
| PHP ≥ 8.3, `db.driver = pdo_mysql`, `rate_limiter` block, `trusted_proxies`, `session_regeneration_grace_period` | Migrated correctly |
| `guest/system/version` removed | ✅ Returns 740 error — no version disclosure |
| `guest/cron/run` without hash | ✅ Returns 403 |
| `hide_version_public` / `hide_company_public` | ✅ Both enabled |
| `invoice_hash_lifetime_days` | ✅ 90 (default) |
| Uploads at `data/uploads` (no legacy `/uploads`) | ✅ Migrated |
| Redis cache | ✅ Running, PONG |
| Rate limiting on `domain_lookup_ip`, `cart_promo_apply_ip` | ✅ Core 0.8.4 enforces it |
| Connectreseller.php custom registrar adapter | ✅ Survived upgrade (untracked file) |

---

## CRITICAL — Local core patches wiped by the 0.8.4 upgrade

The upgrade overwrote four of the six patched core files listed in CLAUDE.md.

### 1. `Cart/Api/Guest.php` — `validate_promo` endpoint GONE (functional breakage)
`curl /api/guest/cart/validate_promo` → error 740 "does not exist".
Merotheme calls it from **4 templates** (`partial_order_product_component`,
`mod_order_product`, `mod_order_domain_plans`, `mod_order_domain_transfer`).
**Coupon preview/validation is currently broken on every order page.**
→ Re-add `validate_promo()` to `src/modules/Cart/Api/Guest.php` (mirror `apply_promo`'s
rate-limiter call: `consumeOrThrow('cart_promo_apply_ip', ...)`).

### 2. `Cart/Service.php` — partnership price override GONE (revenue bug)
`cartProductToApiArray()` no longer calls
`mod_service('partnership')->getPartnerPriceForCartItem()`.
**Partner clients are being quoted full retail price in the cart.**
→ Re-apply the override in `cartProductToApiArray()`.

### 3. `Server/Manager/Whm.php` — `max_duration: 28` GONE (availability)
No `max_duration` in the file. With the production WHM host unreachable from this box,
checkout-triggered provisioning will again hit the 30 s PHP fatal instead of a
catchable `TransportException`.
→ Re-apply `max_duration: 28` in the HTTP client options.

### 4. `Servicedomain/Api/Guest.php` — registrar exception sanitization GONE (info leak)
`check()` / `can_be_transferred()` no longer wrap the registrar call in try/catch.
Raw `Registrar_Exception` / transport errors (adapter names, API messages, with
`debug=true` even stack traces) bubble to unauthenticated guests via the API error
response.
→ Re-wrap `isDomainAvailable()` / `canBeTransferred()` calls, rethrowing a generic
`InformationException`.

### 5. `Product/Service.php` — `deep` param GONE (performance)
`getPaginatedProductCategories()` hard-codes `toProductCategoryApiArray($category, true, …)`.
The `deep=false` fast path is lost, so `/order` again performs per-product pricing
API calls for every product in every category on each page view (N+1 pattern; this was
the original reason for the patch).
→ Restore `$deep = (bool)($data['deep'] ?? true)` and pass it through.

*(6th patch — Cart domain-duplicate handling — was rewritten during the upgrade to
compare actual domain names via `extractDomainFromConfig()`; this is functionally
equivalent-or-better. No action, but re-test multi-item domain carts.)*

---

## HIGH — Web exposure / deployment hygiene

### 6. `/data/` is NOT blocked by the web server
Verified live: `GET /data/log/php_error.log` → **HTTP 200** (324 KB of stack traces,
absolute paths, internals). The migration guide's #1 web-server change is blocking
`/data/`. `router.php` serves any existing file.
→ Local: add to `router.php` before the static-file branch:
```php
if (preg_match('#^/data/#', $uri)) { http_response_code(403); exit; }
```
→ Production nginx: `location ^~ /data/ { return 403; }` (replaces old `/uploads` rule).

### 7. Leftover installer and old config are web-reachable
- `GET /install.bak/install.php` → executes (500). An installer on a live host is a
  takeover primitive.
- `GET /config.old.php` → 200, executed. Contains the old salt + DB credentials; on any
  misconfigured server (PHP handler off, backup tooling renaming to `.php.bak`) it
  leaks plaintext secrets.
→ Delete `src/install.bak/` and `src/config.old.php` (or move outside docroot).

### 8. Debug/error disclosure enabled
`debug_and_monitoring.debug = true`, `log_stacktrace = true`, `report_errors = true`.
Fine for local dev, but API error payloads currently include exception detail — this
amplifies issue #4. **Must be `false` before production.**

---

## MEDIUM

### 9. No Antispam module (0.8 replacement for Spamchecker)
`extension` table has no `antispam` row. The 0.8 guide replaces Spamchecker with the
Antispam module (honeypot on signup enabled by default, Turnstile/hCaptcha optional).
Currently signup and guest ticket forms have **no spam challenge** (only rate limiting).
→ Install/activate Antispam in admin → Extensions; add `antispam_honeypot()` to
merotheme's signup/ticket forms.

### 10. Cron has not run since 2026-05-08 (68 days)
`last_cron_exec = 2026-05-08`, `disable_auto_cron = true`, and no cron hash exists yet
in settings (generated on first configuration). Invoice generation, overdue handling,
renewals and email queues are all dead.
→ Configure a real cron: `php src/cron.php` every 5 min (CLI, no hash needed), or the
URL form with the hash from **System → Cron**.

### 11. Production config checklist (before go-live)
- `security.force_https = false` → **true**
- `security.perform_session_fingerprinting = false` → consider **true**
- DB credentials `merovps/merovps` → strong unique password
- `url` currently `localhost:9000/` → production host
- `salt` is committed in config.php — rotate if repo/backup ever leaves the machine

---

## LOW / informational

- `data/log/php_error.log` at 324 KB and `routing/` at 264 KB — add logrotate in prod.
- Restored legacy Twig filters (`money*`, `markdown`, `gravatar`) in `LegacyExtension.php`
  are re-added local patches: `markdown` uses core `FOSSBillingMarkdown` (HTML-escaped by
  league/commonmark defaults — OK); `gravatar` reintroduces a third-party request for
  client emails (privacy: upstream moved to local DiceBear avatars). Keep only if
  merotheme still uses `|gravatar`; otherwise switch to `avatar()`.
- These restored filters + the slug/redirect additions in `Order/Controller/Client.php`,
  `Box/AppClient.php`, and `Partnership/Controller/Client.php` are new entries for the
  CLAUDE.md local-patch table — they will also be wiped by the next upgrade.

## Recommended fix order
1. Re-apply the 5 wiped core patches (#1–#5) — two are live breakages (coupons, partner pricing).
2. Delete `install.bak/` + `config.old.php`, block `/data/` in router.php (#6, #7).
3. Configure cron (#10) and install Antispam (#9).
4. Production config hardening (#8, #11) before deployment.
