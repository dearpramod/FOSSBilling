---
name: run-meropanel
description: Run, screenshot, build, and smoke-test the meropanel FOSSBilling instance (merotheme). Use when asked to start the app, take a screenshot, verify a page, test the order flow, or build frontend assets.
---

Meropanel is a customised FOSSBilling billing panel running with the `merotheme` client theme. The server is a PHP 8.4 built-in server; the database is MariaDB. The driver (`driver.mjs`) hits all key pages with Playwright and runs API smoke tests — no manual browser needed.

## Prerequisites

```bash
# Playwright Chromium (one-time, ~94 MB)
# Install driver dependencies and download Chromium:
cd .claude/skills/run-meropanel && npm install && npx playwright install chromium

# PHP 8.3+, MySQL/MariaDB (already present on this machine)
php --version    # must be 8.3+
mysql -u merovps -pmerovps merovps -e "SELECT 1;" 2>/dev/null && echo OK
```

## Build (frontend assets)

Run from `FOSSBilling/` (the unit root):

```bash
# All themes + core
npm install
npm run build-merotheme     # merotheme only (fastest, ~1.3 s)
npm run build               # everything including admin_default + huraga
```

Build output goes to `src/themes/merotheme/assets/build/`.

## Start the server

```bash
cd src
php -S localhost:9000 router.php &
# Or with logging:
php -S localhost:9000 router.php 2>&1 | tee /tmp/php-server.log &
```

The server is already running at `localhost:9000` in the current session. Do not kill it — the user has it running persistently.

## Run (agent path — smoke test + screenshots)

```bash
cd FOSSBilling   # unit root
node .claude/skills/run-meropanel/driver.mjs
# Screenshots land in /tmp/meropanel-shots/
# Pass a custom dir: node driver.mjs --screenshots /tmp/my-shots
```

What the driver verifies:
- Guest API: `product/get_list`, `invoice/gateways`, `cart/get`
- Admin API (HTTP Basic): `product/get_list`, `client/get_list`
- Pages: `/`, `/order`, `/order/starter`, `/order/checkout`, `/client/login`
- merotheme is active (Alpine.js `x-data` attribute present)

All 10 checks must pass (`10 passed, 0 failed`).

## Admin API authentication

HTTP Basic auth: username = `admin`, password = API token from the `admin` table.

```bash
ADMIN_TOKEN="3c8btj3fNL8Vu7V5ieXGEiZ7pbk6LMqJ"   # info@merovps.com
curl -s "http://localhost:9000/api/admin/product/get_list" \
  --user "admin:$ADMIN_TOKEN" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['result']['total'])"
```

Guest API needs no auth. Client API uses PHP session cookies (login via `/api/guest/client/login`).

## Key DB credentials

```
host: 127.0.0.1:3306   db: merovps   user: merovps   pass: merovps
```

Test client: `pramodyadav826@gmail.com` (client_id=2).

## Gotchas

- **Checkout timeout (WHM)**: The production cPanel server (`s1324.sgp1.mysecurecloudhost.com:2087`) is unreachable from localhost. Fixed by `max_duration: 28` in `src/library/Server/Manager/Whm.php` — PHP throws a catchable `TransportException` at 28 s instead of a fatal error at 30 s. The JS checkout timeout was also raised to 120 s in `mod_order_checkout.html.twig`.

- **Client balance auto-pay**: Client 2 had 1192.99 NPR balance, which triggered WHM activation on every checkout. Cleared via a DB correction entry. If the checkout hangs again, check `client_balance` for client 2.

- **Alpine.js timing**: `Alpine.start()` is called in `<head>` (before `<body>`). Any function referenced in `x-data="fn()"` must be defined in an inline `<script>` BEFORE its element in `{% block content %}`, not in `{% block js %}` (which renders at end of page). This is the pattern used in `mod_order_checkout.html.twig` and `mod_servicehosting_order.html.twig`.

- **Payment adapter**: Esewa Transfer gateway uses `code: "Custom"` → adapter file is `Custom.php` (exists). `EsewaTransfer.php` does NOT exist — ignore any reference to it. Khalti/QRPayment/BankTransfer were disabled in a previous session (no adapter files).

- **Template resolution order**: `html_custom/` → `html/` → module `templates/client/`. Merotheme overrides live in `src/themes/merotheme/html/`. Huraga has no `mod_order_checkout.html.twig` and cannot serve the checkout page.

- **`npm run build` requires `check` to pass first**: The default `npm run build` runs `check-js-syntax` first. If it fails, use `npm run build-merotheme` directly to skip the check.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Maximum execution time of 30 seconds exceeded in CurlResponse.php` | WHM unreachable. Check `max_duration: 28` is in `Whm.php`. Verify client balance is 0. |
| `Alpine Expression Error: billingPage is not defined` | Function defined after `x-data` element. Move function definition to inline `<script>` at top of `{% block content %}`. |
| `Authentication Failed (code 201)` on admin API | Wrong HTTP Basic format. Must be `--user "admin:<token>"`, not `--user "<email>:<password>"`. |
| `Page not found` for `/order/checkout` | Template missing in active theme. Check `src/themes/merotheme/html/mod_order_checkout.html.twig` exists. |
| Build fails with `check-js-syntax` error | Run `npm run build-merotheme` to skip pre-check. Fix syntax in `.js` files under `assets/`. |
