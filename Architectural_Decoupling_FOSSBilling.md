# Architectural Decoupling — FOSSBilling Custom Modules

> **Purpose:** A permanent reference for building and maintaining customisations on top of FOSSBilling without coupling them to the upstream core — so that `git pull` (or a manual release upgrade) never silently breaks production.

---

## Table of Contents

1. [FOSSBilling Internal Architecture](#1-fossbilling-internal-architecture)
2. [The Update Problem](#2-the-update-problem)
3. [Safe vs Unsafe Zones](#3-safe-vs-unsafe-zones)
4. [Custom Module Blueprint](#4-custom-module-blueprint)
5. [Event Hook Patterns](#5-event-hook-patterns)
6. [Theme Customisation Without Core Edits](#6-theme-customisation-without-core-edits)
7. [Payment Adapter Isolation](#7-payment-adapter-isolation)
8. [Service Contracts — Depending on Interfaces, Not Classes](#8-service-contracts--depending-on-interfaces-not-classes)
9. [Patched Core Files — Inventory and Rules](#9-patched-core-files--inventory-and-rules)
10. [Upgrade Safety Procedure](#10-upgrade-safety-procedure)
11. [Anti-Patterns to Avoid](#11-anti-patterns-to-avoid)
12. [Decision Flowchart — Where Does My Code Go?](#12-decision-flowchart--where-does-my-code-go)

---

## 1. FOSSBilling Internal Architecture

Understanding the stack is prerequisite to decoupling from it.

| Layer | Technology | DI Key / Class |
|---|---|---|
| DI Container | Pimple | `$di` in `di.php` |
| ORM | RedBeanPHP | `Box_Database`, `R::` facade |
| Templating | Twig | `Box_TwigLoader`, `Box_TwigExtensions` |
| Routing | Custom `Box_App` | `Controller/Admin.php::register()` |
| Auth | `Box_Authorization` | Session-based; roles: guest / client / admin / system |
| Module System | `Box\Mod` + `FOSSBilling\Module` | Two coexisting hierarchies (legacy + modern) |
| Event System | `Box_EventManager` | `fire()` / hook discovery via cron |
| Cache | Symfony FilesystemAdapter | `$di['cache']` |

### The two module class hierarchies

This is the single biggest source of hidden coupling in this project.

| Class | Template Root | `hasSettingsPage()` Checks |
|---|---|---|
| `Box\Mod` (legacy) | `html_admin/` | Only `html_admin/` by default |
| `FOSSBilling\Module` (new) | `templates/admin/` | Only `templates/admin/` by default |

**Implication:** a module using `templates/admin/` (the newer convention) will have `has_settings = false` under the legacy class — meaning the settings link never appears in the admin panel. This project has already patched both classes to check both paths. See [§9](#9-patched-core-files--inventory-and-rules).

---

## 2. The Update Problem

FOSSBilling is **pre-1.0**. The core team has explicitly warned:

> "Major releases like 0.8.0 include breaking changes to themes, templates, APIs, and configuration."

When an upstream release ships:

1. The installer overwrites **all files** in the source tree except `/src/data/`.
2. **Your custom modules in `/src/modules/YourModule/` survive** — they are not shipped by upstream.
3. **Your patches to core files are wiped** — `Box/Mod.php`, `TwigLoader.php`, `Extension/Controller/Admin.php`, `ipn.php`, etc. all revert to the upstream version.
4. If an upstream refactor renames a method your module calls directly, your module breaks silently at runtime (no compile-time error in PHP for dynamic method dispatch).

### The two failure modes

| Failure Mode | Cause | Example |
|---|---|---|
| **Patch regression** | A patched core file is overwritten | `hasSettingsPage()` loses dual-path check |
| **API drift** | Upstream renames/removes a method | `Invoice\Service::generateForOrder()` → `createForOrder()` |

Both are solved by different techniques covered in the sections below.

---

## 3. Safe vs Unsafe Zones

```
src/
├── data/                  ✅ SAFE — never overwritten, runtime only
├── modules/
│   ├── Cart/              ⚠️  CORE — upstream owns this; patch tracked in §9
│   ├── Extension/         ⚠️  CORE — patched; tracked in §9
│   ├── Whatsapp/          ✅ CUSTOM — owned by this project
│   ├── Sms/               ✅ CUSTOM — owned by this project
│   ├── Sociallogin/       ✅ CUSTOM — owned by this project
│   ├── Khalti/            ✅ CUSTOM — owned by this project
│   └── Googletagmanager/  ✅ CUSTOM — owned by this project
├── library/
│   ├── Box/               ⚠️  LEGACY CORE — two files patched; tracked in §9
│   ├── FOSSBilling/       ⚠️  MODERN CORE — do not edit unless contributing upstream
│   ├── Payment/Adapter/   ✅ CUSTOM ADAPTERS GO HERE (Khalti lives here)
│   ├── Registrar/Adapter/ ✅ CUSTOM ADAPTERS GO HERE
│   └── Server/Adapter/    ✅ CUSTOM ADAPTERS GO HERE
├── themes/
│   ├── huraga/            ⚠️  CORE THEME — upstream owns; never edit directly
│   └── html_custom/       ✅ SAFE OVERRIDE — create files here to override theme templates
├── public/                ⚠️  CORE ASSETS — overwritten on upgrade
└── ipn.php                ⚠️  PATCHED — tracked in §9
```

### Rules

1. **Own what you write.** Never put custom logic into a file whose path is also in the upstream release.
2. **Treat every file outside `src/modules/{Custom}/` and `src/library/Payment|Registrar|Server/Adapter/{Custom}/` as borrowed.** You may patch it, but you must document it and re-apply it on every upgrade.
3. **`src/data/` is yours.** Config files, uploads, cache, and logs live there and are never touched by an upgrade.

---

## 4. Custom Module Blueprint

Every custom module must follow this structure to be fully self-contained and upgrade-safe.

```
src/modules/YourModule/
├── manifest.json          ← required: id, name, version, author
├── Service.php            ← business logic + event hook handlers (static methods)
├── Api/
│   ├── Admin.php          ← admin API endpoints
│   ├── Client.php         ← client API endpoints
│   └── Guest.php          ← unauthenticated endpoints (optional)
├── Controller/
│   └── Admin.php          ← route registration if module needs custom admin pages
└── templates/
    └── admin/
        └── mod_yourmodule_settings.html.twig
```

### manifest.json

```json
{
    "id": "yourmodule",
    "type": "mod",
    "name": "Your Module",
    "description": "What it does.",
    "author": "Your Name",
    "version": "1.0.0",
    "minimum_boxbilling_version": null,
    "minimum_fossbilling_version": "0.8.0"
}
```

### Service.php skeleton

```php
<?php

namespace Box\Mod\Yourmodule;

class Service implements \FOSSBilling\InjectionAwareInterface
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

    // ── Settings ────────────────────────────────────────────────────────────
    public function getConfig(): array
    {
        $ext = $this->di['db']->findOne('ExtensionMeta', 'extension = ?', ['mod_yourmodule']);
        return $ext ? json_decode($ext->meta_value, true) : [];
    }

    public function saveConfig(array $data): bool
    {
        // persist to ExtensionMeta — never write to a core table directly
        $ext = $this->di['db']->findOne('ExtensionMeta', 'extension = ?', ['mod_yourmodule'])
            ?? $this->di['db']->dispense('ExtensionMeta');
        $ext->extension  = 'mod_yourmodule';
        $ext->meta_key   = 'config';
        $ext->meta_value = json_encode($data);
        $this->di['db']->store($ext);
        return true;
    }

    // ── Event Hooks ─────────────────────────────────────────────────────────
    // (See §5 for hook patterns)
}
```

### Naming the settings template

The admin panel resolves settings via `/admin/extension/settings/{mod}`.
The template must be one of:
- `templates/admin/mod_{id}_settings.html.twig` ← preferred (modern)
- `html_admin/mod_{id}_settings.html.twig` ← legacy fallback

**Use `templates/admin/` for all new modules.** The dual-path patch in `Box\Mod::hasSettingsPage()` and `TwigLoader` makes both work on this project (see §9).

### Module navigation URI

Always link to the standard settings route — never register your own top-level admin URL for settings-only modules:

```php
// In Api/Admin.php — for nav registration
'uri' => $this->di['url']->adminLink('extension/settings/yourmodule'),
```

Register a custom `Controller/Admin.php` only when your module needs pages **beyond** settings (e.g. a dedicated list view, a report page).

---

## 5. Event Hook Patterns

Hooks are the primary way to react to core behaviour **without patching core code**.

### How discovery works

FOSSBilling scans every active module's `Service.php` for `public static` methods matching hook names when **cron runs**. After adding or renaming a hook, trigger cron manually before expecting the hook to fire.

### Hook method signature

```php
public static function onAfterAdminOrderCreate(\Box_Event $event): void
{
    $params = $event->getParameters(); // array of context data
    $di     = $event->getDi();
    $order  = $params['id'] ?? null;
    // ...
}
```

- Must be `public` and `static`
- Must accept `\Box_Event`
- Return type must be `void`

### Available hook categories (summary)

| Scope | Categories |
|---|---|
| Admin | Extensions, Clients, Orders (18 hooks), Invoices, Subscriptions, Transactions, Tickets, Staff, System, Auth |
| Client | Account (13 hooks), Cart/Orders, Tickets/Domains |
| Guest | Password reset |
| Product | License management |

### Prefer hooks over patches

| Need | Bad (patch core) | Good (use hook) |
|---|---|---|
| Send WhatsApp on new order | Edit `Order\Service::createOrder()` | `onAfterAdminOrderCreate` in `Whatsapp\Service` |
| Send SMS on invoice payment | Edit `Invoice\Service::markAsPaid()` | `onAfterAdminInvoicePaymentReceived` in `Sms\Service` |
| Custom price override | Edit `Cart\Service::getItemPrice()` | `onBeforeAdminOrderCreate` — inspect and modify params |

> **Exception:** The `Cart\Service::getItemPrice()` partner-price hook in this project cannot currently be expressed as an event hook because no `onBefore` hook exists for price calculation. This is the one justified patch in the Cart module — document it and watch for upstream changes in that method.

### Notification bus pattern (for Whatsapp + SMS)

Both modules implement notification dispatch. Rather than each hook calling both services directly, register a shared notification bus in `di.php`:

```php
// di.php
$di['notification_bus'] = function () use ($di) {
    $bus = new \FOSSBilling\NotificationBus();
    foreach (['whatsapp', 'sms'] as $channel) {
        try {
            $mod = $di['mod']($channel);
            if ($mod->isInstalled() && $mod->isActive()) {
                $bus->register($di['mod_service']($channel));
            }
        } catch (\Throwable) {
            // channel not installed — skip silently
        }
    }
    return $bus;
};
```

Hook handlers then dispatch through the bus:

```php
public static function onAfterAdminOrderCreate(\Box_Event $event): void
{
    $di  = $event->getDi();
    $bus = $di['notification_bus'];
    $bus->dispatch('order.created', $event->getParameters());
}
```

Adding a new notification channel (e.g. Telegram) requires only registering it in the bus — no changes to any existing hook handler.

---

## 6. Theme Customisation Without Core Edits

**Never edit files inside `src/themes/huraga/` or any upstream-shipped theme.**

### The `html_custom/` override layer

Create an `html_custom/` directory at:

```
src/themes/html_custom/
```

The TwigLoader checks `html_custom/` before the theme's own directory. Drop a file here with the same relative path as the theme file to override it:

```
src/themes/huraga/html_client/layout_default.html.twig   ← original
src/themes/html_custom/layout_default.html.twig           ← your override (wins)
```

This directory is not shipped by upstream and survives updates.

### Twig template extension

To extend (rather than fully replace) a template, use Twig inheritance:

```twig
{# src/themes/html_custom/layout_default.html.twig #}
{% extends "@theme/layout_default.html.twig" %}

{% block footer %}
    {{ parent() }}
    {# Google Tag Manager snippet — no core edit required #}
    {% if gtm_id is defined %}
        <!-- GTM body snippet -->
    {% endif %}
{% endblock %}
```

### Google Tag Manager / analytics

Inject tracking snippets via the Googletagmanager module's `Service.php` using a Twig global or a `onAfterClientInvoiceView` hook — not by editing the theme layout directly.

---

## 7. Payment Adapter Isolation

Payment adapters live in `src/library/Payment/Adapter/` and are not shipped by upstream for third-party gateways. They are safe to own. But the adapter itself must be internally decoupled.

### Value object for customer data (Khalti)

The Khalti adapter currently constructs customer fields and truncates them inline alongside session and URL logic. Extract this into a value object:

```php
// src/library/Payment/Adapter/Khalti/KhaltiCustomerInfo.php

final class KhaltiCustomerInfo
{
    private function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $phone,
    ) {}

    public static function fromClientData(array $client): self
    {
        return new self(
            name:  self::truncate($client['first_name'] . ' ' . $client['last_name'], 64),
            email: $client['email'] ?? '',
            phone: self::sanitizePhone($client['phone'] ?? ''),
        );
    }

    private static function truncate(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }

    private static function sanitizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        return strlen($digits) <= 15 ? $digits : '';
    }
}
```

`Khalti.php` consumes `KhaltiCustomerInfo::fromClientData($clientData)->name` etc. All field-limit and sanitisation rules are now testable without booting FOSSBilling.

### Session restore utility

The `restore_session` pattern (appending `session_id()` to return URLs and forwarding it through `ipn.php`) is duplicated across `Khalti.php` and `ipn.php`. Centralise it:

```php
// src/library/FOSSBilling/SessionRestore.php

namespace FOSSBilling;

final class SessionRestore
{
    public static function appendTo(string $url): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . 'restore_session=' . urlencode(session_id());
    }

    public static function forwardFromRequest(array $params): array
    {
        $token = $_GET['restore_session'] ?? $_POST['restore_session'] ?? null;
        if ($token !== null) {
            $params['restore_session'] = $token;
        }
        return $params;
    }
}
```

Both `Khalti.php` and `ipn.php` call the same class. The `ipn.php` patch then becomes a single-line call rather than inline logic — making it trivial to re-apply after an upstream update.

---

## 8. Service Contracts — Depending on Interfaces, Not Classes

### The problem

Custom modules call core services like this:

```php
$invoiceService = $this->di['mod_service']('invoice');
$invoiceService->generateForOrder($order); // ← what if this is renamed upstream?
```

When FOSSBilling renames `generateForOrder` to `createForOrder` in a minor release, every custom module that calls it breaks.

### The solution: interface layer

Create `src/library/FOSSBilling/Interfaces/` and define contracts:

```php
// src/library/FOSSBilling/Interfaces/InvoiceServiceInterface.php
namespace FOSSBilling\Interfaces;

interface InvoiceServiceInterface
{
    public function generateForOrder(array $order): int;
    public function markAsPaid(int $invoiceId, string $gatewayId): bool;
}
```

Bind it in `di.php`:

```php
$di[\FOSSBilling\Interfaces\InvoiceServiceInterface::class] =
    fn() => $di['mod_service']('invoice');
```

Custom modules type-hint the interface:

```php
public function __construct(
    private readonly \FOSSBilling\Interfaces\InvoiceServiceInterface $invoiceService
) {}
```

When upstream renames the method, only the interface definition needs updating — plus a one-line fix in any custom implementation. All consuming custom modules are untouched.

### Priority service interfaces for this project

| Interface | Core Implementation | Custom Consumers |
|---|---|---|
| `InvoiceServiceInterface` | `Invoice\Service` | Khalti adapter, event hooks |
| `ClientServiceInterface` | `Client\Service` | Sociallogin, Whatsapp |
| `OrderServiceInterface` | `Order\Service` | Whatsapp, SMS, notification bus |
| `NotificationAdapterInterface` | `Whatsapp\Service`, `Sms\Service` | Notification bus |

---

## 9. Patched Core Files — Inventory and Rules

These files belong to upstream FOSSBilling but have been modified in this project. Every one of them **must be reviewed and re-applied after every upstream update.**

| File | What Was Changed | Upstream Risk | Re-apply Complexity |
|---|---|---|---|
| `src/library/Box/Mod.php` | `hasSettingsPage()` checks both `html_admin/` and `templates/admin/` | Medium — method may move to `FOSSBilling\Module` | One method, ~5 lines |
| `src/library/Box/TwigLoader.php` | `findTemplate()` searches `templates/{type}/` as fallback | Low — additive path append | ~2 lines |
| `src/library/Box/AppClient.php` | try-catch for 404 on missing templates | Low | ~3 lines |
| `src/modules/Extension/Controller/Admin.php` | `get_settings()` falls back from `_settings` to `_index` template | Medium — handler logic may be refactored | ~5 lines |
| `src/modules/Extension/html_admin/mod_extension_index.html.twig` | JS redirect fix for settings navigation | Low | Template diff |
| `src/modules/Cart/Service.php` | `getItemPrice()` — partner price override hook | Low — additive try/catch | ~8 lines |
| `src/ipn.php` | `restore_session` parameter forwarding | High — upstream may restructure IPN flow | Utility call (after §7 refactor) |

### Rules for patched files

1. **Never patch silently.** Every patch must appear in this table.
2. **Keep patches minimal.** The smaller the diff, the easier it is to re-apply.
3. **Prefer extension over modification.** If PHP inheritance allows it, subclass the core class and override the method (see §4.2 in SECURITY-PRACTICE.md for the `Box\Mod` extension pattern).
4. **Store patch diffs.** Keep a `patches/` directory at the project root with one `.patch` file per entry in this table. Run `git diff` against the upstream baseline after each upgrade to confirm all patches are in place.

### Patch diff workflow

```bash
# After downloading a new FOSSBilling release but before copying files:
# 1. Diff your working tree against the release archive for each patched file
diff -u upstream/src/library/Box/Mod.php src/library/Box/Mod.php > patches/Box_Mod.patch

# 2. After copying the new release files, re-apply:
patch -p0 < patches/Box_Mod.patch
```

---

## 10. Upgrade Safety Procedure

Run this procedure before applying every FOSSBilling upstream update.

### Pre-upgrade checklist

- [ ] Back up the database (full dump)
- [ ] Back up the entire `src/` directory
- [ ] Record the current FOSSBilling version from `version.php` or the admin panel
- [ ] Read the upstream changelog for the target version — flag any changes to the patched files in §9

### Diff patched files against the new release

```bash
for f in \
  "src/library/Box/Mod.php" \
  "src/library/Box/TwigLoader.php" \
  "src/library/Box/AppClient.php" \
  "src/modules/Extension/Controller/Admin.php" \
  "src/modules/Cart/Service.php" \
  "src/ipn.php"; do
    echo "=== $f ==="
    diff -u "upstream_release/$f" "$f" || true
done
```

If a diff is empty, the upstream version of that file is identical to the patched version — no re-application needed. If the upstream changed that file, review manually and merge.

### Post-upgrade verification

- [ ] Settings page loads for: Whatsapp, SMS, Sociallogin, Khalti, Googletagmanager
- [ ] `/admin/extension/settings/whatsapp` resolves without "template not found"
- [ ] Khalti payment initiation → redirect works
- [ ] Khalti IPN callback (`ipn.php`) correctly forwards `restore_session`
- [ ] Khalti cancel/return URL restores the session and redirects correctly
- [ ] New order triggers Whatsapp hook (check logs)
- [ ] New order triggers SMS hook (check logs)
- [ ] Social login button appears on client login page
- [ ] Google Tag Manager head/body snippets are present on client-facing pages
- [ ] Cron runs without PHP errors (check `/src/data/logs/`)

---

## 11. Anti-Patterns to Avoid

| Anti-Pattern | Risk | Correct Approach |
|---|---|---|
| Editing a core module's `Service.php` to add behaviour | Overwritten on upgrade | Use an event hook in your custom module's `Service.php` |
| Calling `$di['mod_service']('invoice')->someMethod()` directly | Breaks if method is renamed | Type-hint an interface (§8) |
| Putting custom logic in `src/public/` | Overwritten on upgrade | Put assets in `src/modules/YourMod/` and serve via module route |
| Editing `src/themes/huraga/` templates | Overwritten on upgrade | Use `src/themes/html_custom/` overrides (§6) |
| Registering a custom top-level admin route for settings | Conflicts with Extension::get_settings | Use `/admin/extension/settings/{mod}` |
| Duplicating session/URL logic across adapters | Diverges over time; hard to fix consistently | Centralise in `FOSSBilling\SessionRestore` (§7) |
| Using `Box_` legacy classes in new code | These are being phased out | Use `FOSSBilling\*` classes where available |
| Storing module config in a custom database table | Schema change needed on every reinstall | Use `ExtensionMeta` table with `extension = 'mod_yourmodule'` |
| Hard-coding admin URLs as strings | Breaks if routing changes | Always use `$di['url']->adminLink('...')` |

---

## 12. Decision Flowchart — Where Does My Code Go?

```
Need to add new behaviour?
│
├─ Is it reacting to something that already happens in core?
│   └─ YES → Event Hook in your module's Service.php (§5)
│
├─ Is it a completely new feature with its own UI?
│   └─ YES → New Custom Module (§4)
│
├─ Is it a payment integration?
│   └─ YES → New Payment Adapter in src/library/Payment/Adapter/YourGateway/ (§7)
│
├─ Is it a UI change to the client/admin theme?
│   └─ YES → Template override in src/themes/html_custom/ (§6)
│
├─ Is it something that CANNOT be done without patching core?
│   ├─ Can PHP inheritance express it?
│   │   └─ YES → Subclass the core class and rebind in di.php (§9 rule 3)
│   └─ NO → Patch, document in §9 table, add to patches/ directory
│
└─ Is it shared utility logic used by multiple modules?
    └─ YES → Add to src/library/FOSSBilling/ with a proper namespace
```

---

## Appendix — Module Checklist (New Module)

Before considering a new module complete:

- [ ] `manifest.json` present with correct `id`, `version`, `minimum_fossbilling_version`
- [ ] `Service.php` implements `InjectionAwareInterface`
- [ ] Settings stored in `ExtensionMeta` table, not a custom table
- [ ] Settings template at `templates/admin/mod_{id}_settings.html.twig`
- [ ] Navigation URI uses `$di['url']->adminLink('extension/settings/{id}')`
- [ ] Event hooks are `public static` methods accepting `\Box_Event`
- [ ] No direct calls to `Box_` legacy classes in new code
- [ ] Module does not edit any file outside its own directory
- [ ] Any cross-module notification goes through the notification bus (§5)
- [ ] Cron triggered at least once to register hooks after development
