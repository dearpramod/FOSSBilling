# WORKLOG — FOSSBilling Custom Install

Tracks completed tasks, patch restorations, and architectural work. Add a new entry after completing any task. Most recent first.

---

## 2026-07-23 — Invoice + Balance Page Performance Audit

### Files Changed
- `src/modules/Invoice/Service.php` — 2 fixes: `getSearchQuery()` + `toApiSummaryArray()`
- `src/modules/Invoice/Api/Client.php` — `get_list()` fixed; `get_stats()` added
- `src/themes/merotheme/html/mod_invoice_index.html.twig` — replace 3 stat invoice_get_list calls
- `src/themes/merotheme/html/mod_client_balance.html.twig` — replace 2 × 200-item invoice_get_list calls

### Performance Fixes

| Area | Before | After | Saving |
|---|---|---|---|
| `Invoice/Service::getSearchQuery(summary=true)` | 2 correlated subqueries per row (N×2 extra SELECTs) | Direct JOIN aggregation using already-JOINed `pi` alias | Eliminates per-row subqueries |
| `Invoice/Api/Client::get_list()` | N+1: pager + N×Model_load + N×invoice_item SELECT | `summary=true` + `toApiSummaryArray()` (row already in pager) | −2N queries per page (N=10 default → −20 queries) |
| Invoice index stat cards | 3 `invoice_get_list` calls (1×200-item unpaid + 1×1-item paid + 1×1-item all) + Twig sum loop | `client.invoice_get_stats` → 2 SQL queries total | −3 API calls → −200+ queries |
| Balance stat cards | 2 `invoice_get_list` calls (`per_page:200` unpaid + `per_page:200` paid) + Twig sum loops | `client.invoice_get_stats` (shared with invoice index) | −802 queries → 0 (stats already fetched) |
| **Total `/invoice` page** | 428+ queries | ~5 queries | ~−423 queries |
| **Total `/client/balance` page** | 820+ queries | ~5 queries | ~−815 queries |

**`getSearchQuery` fix detail:** The `summary=true` branch was using correlated subqueries `(SELECT ... FROM invoice_item WHERE invoice_id = p.id)` — one per invoice per page. The query already has `LEFT JOIN invoice_item pi` with `GROUP BY p.id`, so the aggregations can use `SUM(pi.price * pi.quantity)` directly. One pass, no nested selects.

**`get_list()` fix detail:** Old code: for each row returned by pager, load `Model_Invoice` by id (RedBeanPHP round-trip) + call `toApiArray()` (fires another `SELECT FROM invoice_item`). New code: pager already returns all `p.*` columns + computed `list_subtotal` and `list_taxable_subtotal`; `toApiSummaryArray()` computes total from those without touching the DB.

**`get_stats()` detail:** Single derived-table query computes per-invoice totals (handling taxrate correctly), then outer SELECT aggregates into `total`, `unpaid_count`, `paid_count`, `outstanding_total`, `paid_total`. Second query fetches last paid invoice with its total. 2 queries replace up to 802. Also more accurate than the old approach (old cap at 200 items missed invoices beyond that).

**`toApiSummaryArray()` fix:** Added `'hash' => $row['hash'] ?? null` — required for invoice URL links in both the invoice list and balance transaction table.

### Template Changes Summary

| Template | Old variables | New variables |
|---|---|---|
| `mod_invoice_index.html.twig` | `unpaid_all`, `paid_recent`, `all_count`, `outstanding_total`, `outstanding_currency` | `invoice_stats` (one call) |
| `mod_client_balance.html.twig` | `unpaid`, `paid`, `unpaid_total`, `paid_total` | `invoice_stats` (one call) |

### Verified
- `/invoice` page: 25 invoices total, NPR 160,315.28 outstanding (20 unpaid), Last Payment NPR 11.30 May 8, 2026. Pagination: 10/page, 3 pages. Invoice hash URLs intact. No Twig errors.
- `/client/balance` page: Balance NPR 0.00, 20 Unpaid (NPR 160,315.28), 5 Paid (NPR 5,961.62). Transaction table with hash links, "Showing 1 to 10 of 25" pagination.

### Security Audit Results

| Area | Finding | Status |
|---|---|---|
| SQL injection | All queries use named placeholders (`:client_id`) | ✅ Safe |
| `client_id` scoping | `get_stats()` and `get_list()` always scope to `$this->getIdentity()->id` | ✅ Safe |
| Correlated totals | `outstanding_total` now computed server-side in SQL, not Twig loop | ✅ Improved |
| Data accuracy | Old paid/unpaid totals were capped at 200 invoices; new approach sums all | ✅ More accurate |

---

## 2026-07-23 — Full Client Portal Audit (All Pages)

### Files Changed
- `src/themes/merotheme/html/layout_default.html.twig` — 3 fixes
- `src/themes/merotheme/html/mod_order_list.html.twig` — replace 200-item all_orders fetch
- `src/themes/merotheme/html/mod_order_manage.html.twig` — eliminate redundant profile_get
- `src/themes/merotheme/html/mod_support_tickets.html.twig` — reuse fetched total for tab counts
- `src/themes/merotheme/html/mod_client_profile.html.twig` — 3 fixes: per_page, 2× strict_variables
- `src/themes/merotheme/html/mod_invoice_invoice.html.twig` — remove duplicate detection block

### Performance Fixes

| Area | Before | After | Saving |
|---|---|---|---|
| `layout_default.html.twig` GTM check | `extension_is_on('googletagmanager')` called twice (head + body) | Cached as `_gtm_on` before `<head>` | −1 `SELECT FROM extension` per page |
| `layout_default.html.twig` supportpin check | `extension_is_on('supportpin')` called twice (sidebar + mobile nav) | Cached as `_supportpin_on` at layout var block | −1 `SELECT FROM extension` per page |
| My Services (`/order/service`) tab counts | `order_get_list({per_page:200})` + Twig loop over 200 orders (N×`toApiArray` + N×`order_service` queries) | 3× `order_get_list({per_page:1})` — COUNT only | −200 orders + N service queries → 6 queries |
| Order manage (`/order/service/manage/:id`) | `client.profile_get` (separate DB hit) | `profile` (already fetched by layout) | −1 query per page |
| Support tickets tab counts | 2 unconditional `support_ticket_get_list({per_page:1})` calls | Reuse `tickets.total` when tab matches (saves 1 on active tab) | −1 query on default tab |
| Profile page active order count | `order_get_list({status:'active', per_page:100})` (loads 100 orders) | `order_get_list({per_page:1})` — pager total only | −99 wasted rows + N service queries |

### Bug Fixes
- `layout_default.html.twig`: Duplicate GTM `extension_is_on` call also caused GTM snippet to potentially fire twice if the result differed between calls (impossible in practice but logically wrong).
- `mod_invoice_invoice.html.twig` line 10–14: `is_deposit_invoice` detection loop was written twice. The first iteration's result was immediately overwritten by the reset `{% set is_deposit_invoice = false %}` that started the second block. Removed the dead first block.
- `mod_client_profile.html.twig`: Two `custom_fields['custom_N']` accesses without `is defined` guard crashed in Twig strict mode when no custom fields are configured (empty mapping). Fixed both loops.

### Verified
- `/order/service`: Active (4), Cancelled tab, All (18) counts all correct. Full order list loads.
- `/client/profile`: "04 Active" services count correct. Balance shown. No strict_variables errors.
- `/order/service/manage/107`: Hosting order manage page loads, contact forms render correctly using layout's `profile` var.
- `/support`: Ticket counts (Total 10, Awaiting 10, Closed 2) correct.
- Full driver: 11/11 checks pass.

---

## 2026-07-23 — Dashboard Performance + Security Audit

### Files Changed
- `src/modules/Index/Service.php` — 3 query optimisations
- DB: 3 new composite indexes added

### Performance Fixes

| Method | Before | After | Saving |
|---|---|---|---|
| `getRecentInvoices()` | 11 queries (1 SELECT id + 5×load() + 5×SELECT invoice_item) | 1 JOIN query | −10 queries |
| `getOrdersData()` | 2 queries (GROUP BY status + separate expiring count) | 1 conditional aggregation query | −1 query |
| `getProfile()` | `toApiArray($client, true)` → fetched balance (duplicate) | `toApiArray($client, false)` | −1 query |
| **Total `getDashboardData()`** | ~25 queries | ~14 queries | ~−11 queries |

**`getRecentInvoices` detail:** Invoice table has NO `total` column — total is always computed from `invoice_item` rows. The original code did `SELECT id` → `load(Invoice, id)` × N → `toApiArray()` × N (each fires `SELECT FROM invoice_item`). Replaced with a single `LEFT JOIN invoice_item` + `SUM(price × quantity)` + CASE-WHEN for taxable amount. Tax calculation mirrors `Invoice\Service::toApiSummaryArray()`.

**`getOrdersData` detail:** Merged status COUNT + expiring COUNT into one query using `SUM(CASE WHEN ... THEN 1 ELSE 0 END)` conditional aggregation. The `getParamValue` call for `invoice_issue_days_before_expire` still fires once (unavoidable — value is configurable).

**`getProfile` detail:** `Client\Service::toApiArray($client, true)` also fetches `getClientBalance()` internally. Since `getDashboardData()` already calls `ServiceBalance::getClientBalance()` as a dedicated key, the profile was computing balance twice. Changing to `deep=false` removes the duplicate.

### DB Indexes Added

| Table | Index | Columns | Query served |
|---|---|---|---|
| `invoice` | `idx_invoice_client_approved_created` | `(client_id, approved, created_at)` | `getRecentInvoices` + `getInvoicesData` ORDER BY |
| `activity_client_email` | `idx_ace_client_created` | `(client_id, created_at)` | `getRecentEmails` ORDER BY |
| `client_order` | `idx_order_client_master_updated` | `(client_id, group_master, updated_at)` | `getRecentOrders` ORDER BY |

### Security Audit Results

| Area | Finding | Status |
|---|---|---|
| SQL injection | All queries use named/positional placeholders | ✅ Safe |
| `client_id` scoping | All queries filter by `$client->id` (session object, not user input) | ✅ Safe |
| Ticket data | `getBatchForApi` strips `access_hash`, `client_id`, `priority`, `rel_id`, `rel_type` for non-admin | ✅ Safe |
| Invoice hash in URLs | By design — hash is the access token; properly random | ✅ Safe |
| XSS | Twig auto-escape active; `\|striptags` removes tags, auto-escape encodes remainder | ✅ Safe |
| Email content | `getRecentEmails` returns only `id, subject, created_at` — no body exposed | ✅ Safe |
| Balance query | Uses typed `\Model_Client` from session, never from user input | ✅ Safe |

### Architecture Notes (Not Fixed — Tracked)

1. **`layout_default.html.twig` fires `client.profile_get` on every page** — on the dashboard this is redundant because `index_get_dashboard` already returns profile. Fixing requires either removing the layout call (breaks nav name display on non-dashboard pages) or injecting a shared profile global.
2. **`guest.extension_is_on()` called 6+ times in layout** — each is a `SELECT` on the `extension` table. A batch call (e.g. load all active extensions once into a Set) would reduce to 1 query. Not a correctness issue; tracked for future layout refactor.

### Verified
- Dashboard renders correctly post-fix: HTTP 200, all stat cards present, invoice hash links correct, no Twig errors.
- Page response time: ~1.2 s on localhost (includes layout's 12+ API calls and Twig compile).

---

## 2026-07-23 — 0.8.5 Upgrade: Patch Restoration + Bug Fixes

### Trigger
FOSSBilling upgraded to 0.8.5. Same pattern as 0.8.4: upstream overwrote 4 patched core files without warning.

### Patches Restored

| File | What Was Lost | Fix Applied |
|---|---|---|
| `src/library/FOSSBilling/Twig/Extension/LegacyExtension.php` | All 5 legacy Twig filters (`money`, `money_convert`, `money_without_currency`, `markdown`, `gravatar`) wiped | Re-added all 5 methods with `#[AsTwigFilter]` attributes; added `use FOSSBillingMarkdown` import |
| `src/library/Server/Manager/Whm.php` | `max_duration: 28` removed; catch narrowed back to `HttpExceptionInterface` only | Restored `'max_duration' => 28` in `withOptions`; widened catch to `HttpExceptionInterface\|TransportExceptionInterface` |
| `src/library/Box/AppClient.php` | Partnership route binding removed (PR #4011) | Restored partnership `registerClientRoutes()` call in `init()` after redirect-module check |
| `src/modules/Cart/Service.php` | Partnership price override in `cartProductToApiArray()` removed (PR #3974) | Restored `getPartnerPriceForCartItem()` call wrapped in `isExtensionActive('mod','partnership')` guard |

### New Bugs Fixed (surfaced during verification)

**`src/modules/Product/Service.php` — `deep` parameter not honoured**
- `getPaginatedProductCategories()` had hardcoded `true` for deep; changed to `(bool) ($data['deep'] ?? true)`.
- `toProductCategoryApiArray()`: added `!$deep` fast-path (resolves `type` from first product, returns empty `products` array, skips all per-product pricing calls).
- Affected: 3 merotheme templates pass `deep: 0` for nav/sidebar listings on every page render.

**`src/modules/Order/Controller/Client.php` — Missing explicit routes**
- `/order/checkout`, `/order/domain-registration`, `/order/domain-transfer` all fell through to `/:slug` catch-all → `product_get(['slug' => 'checkout'])` → "Product not found" error.
- Added 3 explicit `$app->get(...)` registrations before the `/:slug` catch-all route.
- Added handler methods: `get_checkout()`, `get_domain_registration()`, `get_domain_transfer()`.

**`src/modules/Order/Controller/Client.php` — Category slug lookup broken**
- `get_configure_product_by_slug()` had no fallback: if product lookup failed, it threw immediately.
- Added try/catch: product lookup first; on miss, iterate all categories and match slugified title.
- Slugification bug: `preg_replace` was running before `strtolower` — "VPS " (DB title) collapsed to empty string. Fixed: `strtolower()` first, then `preg_replace('/[^a-z0-9]+/', '-', ...)`, then `trim('-')`.
- Added `category_slug` variable to category render call (template expected it).

**`src/modules/Index/Service.php` — Dashboard missing keys**
- `getDashboardData()` was missing `balance`, `recent_invoices`, `recent_emails`.
- Added `balance` via `mod_service('Client', 'Balance')->getClientBalance($client)`.
- Added `getRecentInvoices()` private method: raw SQL on `invoice` table → `invoiceService->toApiArray()` for each row.
- Added `getRecentEmails()` private method: raw SQL on `activity_client_email` table (id, subject, created_at).

**`src/modules/News/Entity/Post.php` — Missing `tags` key**
- `toApiArray()` returned 15 keys; template `mod_news_post.html.twig` line 18 checked `post.tags` causing `Key 'tags' does not exist` under `strict_variables`.
- Post entity has no tags column or relationship.
- Fix: added `'tags' => []` to `toApiArray()` return — empty array is falsy in Twig, so `{% if post.tags %}` skips cleanly.

### Post-session Memory File Created
- `memory/development-precheck.md` — official FOSSBilling docs checklist: module conventions, security hardening, theme rules, payment adapter rules, upgrade pre-flight.

---

## 2026-07-19 — Twig Strict Variables Parity

**File:** `src/config.php` (untracked)

**Problem:** Local config lacked `twig.strict_variables => true`, so local Twig silently returned null for missing keys. Production (strict) was throwing errors that could not be reproduced locally. Fixed template errors kept being re-reported.

**Fix:** Added `'strict_variables' => true` to `twig` config block in local `config.php`, matching `config-sample.php` default.

**Rule going forward:** Always clear compiled Twig cache after config or template changes:
```bash
find FOSSBilling/src/data/cache -name "*.php" -delete
```

---

## 2026-07-16 — Email Template Link Filter Migration

**Files:** 20 custom-module email templates under `src/modules/*/templates/email/`

**Problem:** `StrictVariablesTest` email area was failing because `|link` and `|alink` filters were removed from the email Twig sandbox in 0.8.x. Templates still used them.

**Fix:** Converted all `|link` and `|alink` usages to `|url` across 20 templates.

**Result:** Email strict_variables test now passes. Commit: `5e40440e1`.

---

## 2026-07-15 — Security Audit & Docroot Hardening

**Audit Report:** `FOSSBilling/AUDIT_REPORT_2026-07-15.md`

**Actions taken:**

| Item | Action |
|---|---|
| `install.bak/` in docroot | Moved to `FOSSBilling/docroot-quarantine/` |
| `config.old.php` in docroot | Moved to `FOSSBilling/docroot-quarantine/` |
| `src/router.php` — `/data/` exposed | Added 403 block for `/data/` path prefix |
| `src/themes/merotheme/html/mod_page_signup.html.twig` | Added `antispam_honeypot()` bait field |

**Production nginx mirror required:**
```nginx
location ^~ /data/ { return 403; }
```

**Outstanding decisions (not yet applied — user must decide):**
- `disable_auto_cron: true` in config → cron has been dead since 2026-05-08. External cron needed.
- `debug: true` + `force_https: false` — acceptable for dev, must change before production.
- DB password (`merovps`) is weak — acceptable for dev localhost, must be changed before production.

---

## Architectural Work — SECURITY-PRACTICE.md

Reference: `FOSSBilling/SECURITY-PRACTICE.md` (Architectural Decoupling Plan)

### Phase 1 — Normalise Module Contract ✅ ALL APPLIED

| Item | Status | Details |
|---|---|---|
| 1.1 `hasSettingsPage()` dual-path | ✅ Done | `Box\Mod` now checks both `html_admin/` and `templates/admin/` paths |
| 1.2 Standardise module nav URIs | ✅ Done | Sociallogin and Googletagmanager now use `extension/settings/{mod}` |
| 1.3 `Extension::get_settings()` fallback | ✅ Done | Tries `_settings` template first, falls back to `_index` |
| 1.4 TwigLoader dual-path fallback | ✅ Done | `findTemplate()` searches both `html_{type}/` and `templates/{type}/` |

### Phase 2 — Isolate Custom Module Logic ⏳ PENDING

| Item | Status | Priority |
|---|---|---|
| 2.1 `KhaltiCustomerInfoBuilder` value object | ⏳ Pending | Medium — isolates field-limit/sanitisation from FOSSBilling internals |
| 2.2 `SessionRestore` utility class | ⏳ Pending | **High** — `restore_session` logic duplicated in `Khalti.php` and `ipn.php`; most fragile patch |
| 2.3 `NotificationAdapterInterface` | ⏳ Pending | Medium — Whatsapp and SMS share no contract |

### Phase 3 — Service Contracts ⏳ PENDING

| Item | Status | Notes |
|---|---|---|
| Core service interfaces (`InvoiceServiceInterface`, `ClientServiceInterface`, etc.) | ⏳ Pending | Weeks of work; unblocks safe swapping of services |
| DI bindings for interfaces | ⏳ Pending | Depends on Phase 3 interfaces |
| Notification bus (`$di['notification_bus']`) | ⏳ Pending | Depends on `NotificationAdapterInterface` (Phase 2.3) |

### Phase 4 — Upgrade Safety Layer ⏳ ONGOING

| Item | Status | Notes |
|---|---|---|
| Patched file inventory | ✅ In `SECURITY-PRACTICE.md` §4.1 + `CLAUDE.md` §"Local Core Patches" | Keep updated after every patch |
| Version-gated override: extend `Box_Mod` instead of patching | ⏳ Pending | **High value** — next fragile rewrite of `Box_Mod` would be non-breaking |
| Upgrade checklist | ✅ In `AUDIT_REPORT_2026-07-15.md` + `CLAUDE.md` | Run after every upgrade |

**Next highest-value actions (from SECURITY-PRACTICE.md §Summary):**
1. **Phase 2.2** — Centralise `restore_session` into `FOSSBilling\SessionRestore` utility (reduces upgrade friction on `ipn.php`)
2. **Phase 4.2** — Extend `Box_Mod` rather than patching it (removes the most fragile core patch from the upgrade risk list)

---

## Known Pre-existing Issues (Not Bugs — Tracked for Awareness)

| Issue | Notes |
|---|---|
| `StrictVariablesTest` "all templates" — 4 failures | False-positives in `VariableCollectorVisitor` (misses vars in hash args / extends blocks): `formbuilder_build.product`, `servicehosting order.product_details`, `kb_article.post`, `partial_pagination.list`. Predates 0.8.5. |
| reCAPTCHA v3 on domain search | Token sent with `servicedomain/check` but nothing verifies it server-side — decorative only. |
| Cron dead since 2026-05-08 | `disable_auto_cron: true` in config; no external cron configured. Renewal emails, subscription renewals, etc. are not running. |
| WHM unreachable from localhost | `s1324.sgp1.mysecurecloudhost.com:2087` not reachable from dev; `max_duration: 28` patch prevents 30 s fatal. |
