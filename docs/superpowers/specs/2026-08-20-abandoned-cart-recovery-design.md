# Abandoned Cart Recovery — Design

**Date:** 2026-08-20
**Status:** Approved for planning
**Type:** New extension module (`Abandonedcart`) + one documented core patch in `Cart/Service.php`

## Goal

Detect shopping carts that were left without checking out and win them back by
emailing the owning client a reminder that includes a single-use discount
coupon. A recovery link in the email restores the abandoned cart into the
client's current session with the coupon applied, so they can complete
checkout in one click.

Scope for v1: automated recovery **emails with a coupon incentive**. No admin
UI (reporting) in this version.

## Context: how carts work today

- **Table `cart`** (`src/install.bk/sql/structure.sql:183`): `id`, `session_id`,
  `currency_id`, `promo_id`, `created_at`, `updated_at`. Keyed by **session**, with
  **no `client_id`** — carts are anonymous.
- **`cart_product`**: `cart_id`, `product_id`, `config` (JSON: period, domain, options).
- **Lifecycle** (`src/modules/Cart/Service.php`):
  - `getSessionCart()` (`:92`) creates the cart row on first `add_item`, stamping
    `created_at`/`updated_at`.
  - Every mutation (`addProduct`, `removeProduct`, `changeCartCurrency`,
    `applyPromo`) bumps `updated_at`.
  - `checkoutCart()` → `rm($cart)` (`:409`) **hard-deletes** the cart + its products
    on successful checkout.
  - `onAfterClientLogin()` (`:49`) currently only syncs currency; it does **not**
    link the cart to the client.
- **Garbage collection exists**: `Cart\Api\Admin::batch_expire` (`:60`) deletes carts
  with `created_at` older than 7 days, and it is cron-wired as `cart_batch_expire`
  (`src/modules/Cron/Service.php:89`). **Implication:** the recovery window must sit
  inside this 7-day span (reminders are measured in hours, so this is fine).
- **Promos** are managed by the Product module:
  - `Product\Service::createPromo($code, $type, $value, $products, $periods, $clientGroups, $data)`
    (`src/modules/Product/Service.php:1163`) creates a Doctrine `Promo` and returns its id.
  - `promo` table: `code`, `type` (`absolute|percentage|trial`), `value`, `maxuses`,
    `used`, `once_per_client`, `active`, `products`, `periods`, `client_groups`,
    `start_at`, `end_at`.
  - `Cart\Service::applyPromo(\Model_Cart $cart, Promo $promo)` (`:381`) sets
    `cart->promo_id`. Note: cart is a RedBean `Model_Cart`; promo is a Doctrine `Promo`.
- **Email**: a module ships a `.html.twig` under `templates/email/` with a `code`,
  sent via `$di['mod_service']('email')->sendTemplate(['code'=>..., 'to_client'=>id, ...vars])`
  (pattern: `src/modules/Client/Service.php:845`).
- **Cron + event wiring**: modules hook `onAfterAdminCronRun`; the module must be
  **activated and batchConnect-registered** (same requirement documented for
  `Fonepaygateway` and the cart login listener in CLAUDE.md).

## The core gap

Carts are anonymous (session only), so today we can count abandonment but cannot
contact anyone. Recovery requires linking a cart to a client. That is the single
cross-cutting change; everything else lives in the new module.

## Components

### 1. Ownership stamping — single core patch in `Cart/Service.php`

One touch-point stamps the owning client onto the cart:

- **`getSessionCart()`** — on create/fetch, if `session.get('client_id')` is set
  and the cart's `client_id` is not already set, stamp `cart->client_id` and store.
  This is the single choke-point every cart read/mutation passes through —
  `add_item`, and `onAfterClientLogin` (which itself calls `getSessionCart()`), so
  the login case is covered without a second edit.

Guest carts (no logged-in client) remain unrecoverable by nature — we have no
contact info — and are skipped by the cron.

This edit is documented in CLAUDE.md's "Local Core Patches" table as a
re-auditable-after-upgrade patch.

### 2. Schema — columns on `cart`, added by the module's `install()`

Add nullable columns to the core `cart` table via `$db->exec('ALTER TABLE cart ...')`
in `Abandonedcart\Service::install()`:

| Column | Type | Purpose |
|---|---|---|
| `client_id` | `BIGINT NULL` | owning client (from stamping); indexed |
| `reminders_sent` | `INT NOT NULL DEFAULT 0` | reminder stage counter |
| `last_reminder_at` | `DATETIME NULL` | last reminder timestamp (drip interval) |
| `recover_token` | `VARCHAR(64) NULL` | unguessable recovery-link token; indexed |
| `recover_promo_id` | `BIGINT NULL` | the generated single-use coupon's promo id |

`uninstall()` drops these columns.

**Rationale for columns-on-`cart` over a side table:** both `rm()` (checkout) and
`cart_batch_expire` (7-day GC) hard-delete the `cart` row, so these columns are
cleaned up for free. A side table would orphan rows on both paths and require a
separate cleanup pass. Trade-off accepted: this is a schema addition to a core
table, tracked as an upgrade-audit item.

### 3. Cron listener — `Abandonedcart\Service::onAfterAdminCronRun`

Reads module settings (config, admin-editable):

| Setting | Default | Meaning |
|---|---|---|
| `enabled` | `false` | master switch |
| `delay_hours` | `1` | inactivity before the **first** reminder |
| `reminder_count` | `1` | max reminders per cart |
| `interval_hours` | `24` | gap between subsequent reminders |
| `coupon_type` | `percentage` | `percentage` or `absolute` |
| `coupon_value` | `10` | discount value |
| `coupon_ttl_hours` | `48` | coupon validity from generation |

Each run:

1. Select candidate carts: `client_id IS NOT NULL`, at least one `cart_product`,
   `reminders_sent < reminder_count`, still inside the 7-day GC window, and stale
   past the stage threshold — `updated_at < now - delay_hours` for stage 0, or
   `last_reminder_at < now - interval_hours` for later stages.
2. On the **first** reminder (stage 0), generate a unique single-use coupon via
   `Product\Service::createPromo()`:
   - code e.g. `COMEBACK-XXXXXX` (random suffix),
   - `type`/`value` from settings,
   - `once_per_client = 1`, `maxuses = 1`, `active = 1`,
   - `end_at = now + coupon_ttl_hours`,
   - `products` = the cart's product ids, `periods` = the cart items' periods,
     `client_groups` = the client's group (scope the discount to what's actually
     in the cart so it can't be reused elsewhere).
   - Store `recover_promo_id` and a random `recover_token` on the cart row.
3. Send the reminder email (see §5) with items, total, coupon code + value, and the
   recover URL.
4. Increment `reminders_sent`; set `last_reminder_at = now`.

The coupon is **not** applied to the cart at send time — only when the client
clicks the recovery link (§4) — so a cart that never returns does not silently
carry a discount, and abandoned coupons simply expire.

### 4. Recovery link — `Abandonedcart\Controller\Client`

Route: `GET /cart/recover?token=…`

- Look up the cart by `recover_token`. If not found or the coupon's `end_at` has
  passed → redirect to `/cart` with a gentle "link expired" flash.
- Otherwise: adopt the cart into the current session (`cart->session_id =
  session.getId()`; mirrors the existing `transferFromOtherSession` mechanic),
  apply the stored `recover_promo_id` via `Cart\Service::applyPromo()`, and redirect
  to `/cart`.
- The unguessable token is the authorization — no login required to view the cart;
  checkout still requires login exactly as today.

### 5. Email template

`src/modules/Abandonedcart/templates/email/mod_abandonedcart_reminder.html.twig`
— cart items, total, coupon code + value + expiry, and a prominent CTA button to
the recover URL. Rendered in the sandboxed email Twig environment (uses
`markdown_to_html` / allowed filters only).

### 6. Module registration

- `manifest.json`, `Service.php` (`install()` / `uninstall()` / event listeners),
  `Controller/Client.php`, `templates/email/…`.
- After a bare DB import, run `POST /api/admin/hook/batch_connect {"mod":"abandonedcart"}`
  (or reactivate) so the `onAfterAdminCronRun` listener row is created — same
  operational note as `fonepaygateway` and the cart login listener.

## Data flow

```
client adds item while logged in ──► cart.client_id stamped (core patch)
                                        │
cron (onAfterAdminCronRun) ────────────┤ selects stale, owned, unconverted carts
                                        ▼
                            createPromo() ─► store recover_promo_id + recover_token
                                        ▼
                            sendTemplate(reminder) ─► client email w/ recover URL
                                        ▼
client clicks /cart/recover?token= ─► adopt cart into session + applyPromo ─► /cart
                                        ▼
                            normal checkout ─► rm(cart) deletes row + our columns
```

## Error handling

- Cron is best-effort per cart: wrap each cart's processing in try/catch, log
  failures via `$di['logger']`, and continue (one bad cart must not abort the run) —
  mirrors the `Fonepaygateway::reconcilePending()` pattern.
- Coupon generation collisions (`createPromo` throws on duplicate code) → regenerate
  the random suffix and retry a bounded number of times.
- Expired / unknown recover tokens → safe redirect to `/cart`, never a raw error.
- If `email` module send fails, do **not** increment `reminders_sent` (so the next
  run retries), but do keep the generated coupon/token.

## Testing

- **Pest unit tests** (`src/modules/Abandonedcart/tests/Unit/`):
  - cart selection gating: staleness threshold, stage/interval logic,
    `reminders_sent < reminder_count`, `client_id IS NULL` excluded, empty carts
    excluded, carts outside the 7-day window excluded.
  - coupon generation: correct scoping (products/periods/client group), `maxuses=1`,
    `end_at` = now + ttl; collision-retry path.
  - recover-token adoption: token → session reassignment + promo applied; expired /
    unknown token handled safely.
- **Smoke**: `node .claude/skills/run-meropanel/driver.mjs` (all 11 checks pass).

## CLAUDE.md updates (part of implementation)

Add to the "Local Core Patches" table:
- `src/modules/Cart/Service.php` — `getSessionCart()` stamps `cart.client_id`
  for logged-in clients (covers `add_item` and the login path) for
  abandoned-cart recovery.
- Note the `cart` table gains `client_id` / `reminders_sent` / `last_reminder_at` /
  `recover_token` / `recover_promo_id` (added by the `Abandonedcart` module's
  `install()`), and the `abandonedcart` module must be activated +
  batchConnect-registered for the cron/login listeners to fire.

## Out of scope (v1)

- Admin reporting UI / abandoned-cart list.
- Recovering guest (anonymous) carts.
- Clawing back a coupon once a reminder cart is abandoned again (coupons simply
  expire via `end_at`).
- SMS or other channels.
