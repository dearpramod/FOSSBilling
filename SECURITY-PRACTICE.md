# Architectural Decoupling Plan — FOSSBilling

> **Scope:** This plan covers long-term structural improvements for this project's customised FOSSBilling instance, oriented toward safe future upgrades, maintainability, and scalability of the custom modules (Whatsapp, SMS, Khalti, Sociallogin, Googletagmanager, etc.).

---

## Current State Analysis

### What FOSSBilling Is Built On

| Layer | Technology | Notes |
|---|---|---|
| DI Container | Pimple | All services wired in `di.php` |
| ORM | RedBeanPHP | `Box_Database`, beans, `R::` facade |
| Templating | Twig | `Box_TwigLoader`, `Box_TwigExtensions` |
| Routing | Custom `Box_App` | GET/POST registration in `Controller/Admin.php` |
| Auth | `Box_Authorization` | Session-based, role: guest/client/admin/system |
| Module System | `Box\Mod` + `FOSSBilling\Module` | Two parallel class hierarchies (legacy + new) |
| Event System | `Box_EventManager` | `fire()` / hooks |
| Cache | Symfony FilesystemAdapter | `$di['cache']` |

### Identified Coupling Problems (Current Codebase)

1. **Dual module class hierarchy** — `Box\Mod` (legacy) and `FOSSBilling\Module` (new) coexist. `Box\Mod::hasSettingsPage()` only checks `html_admin/`, while `FOSSBilling\Module::hasSettingsPage()` checks `templates/admin/`. Custom modules that use `templates/admin/` broke template resolution and `has_settings` detection until patched.

2. **`Box_App` monolithic routing** — Routes are registered imperatively inside each `Controller/Admin.php::register()`. No route manifest, no middleware, no named routes. Every module must know its own URL shape.

3. **Tight `di.php` wiring** — All services are hardcoded in a single `di.php`. Adding a new service or overriding an existing one for a custom module requires editing a core file.

4. **Template path convention drift** — Official structure mandates `html_admin/` but newer modules use `templates/admin/`. `TwigLoader::findTemplate()` and `Box\Mod::hasSettingsPage()` had to be patched to bridge both.

5. **`Box_` prefix legacy classes** — `Box_Crypt`, `Box_Log`, `Box_EventManager`, `Box_Authorization` etc. are in the `library/Box/` directory without namespaces, making them hard to mock, test, or replace.

6. **Module settings URL inconsistency** — Modules defined their own navigation URIs (`/admin/sociallogin`) instead of using the standard `/admin/extension/settings/{mod}`. The `Extension/Controller/Admin.php::get_settings()` handler only tried `_settings` templates, ignoring `_index` templates.

7. **No service contracts (interfaces)** — Modules depend on concrete service classes directly. No interfaces define what a service must expose, making swaps or mocks fragile.

---

## Decoupling Strategy

The plan is divided into **four phases**. Each phase is independent and can be executed across FOSSBilling upgrades without breaking the running system.

---

## Phase 1 — Normalize the Module Contract (Immediate / Low Risk)

**Goal:** Establish a single, predictable module structure that works with both legacy and modern FOSSBilling versions.

### 1.1 — Unify `hasSettingsPage()` across both module classes

Both `Box\Mod` and `FOSSBilling\Module` must check both template paths:

```php
// In BOTH Box\Mod and FOSSBilling\Module
public function hasSettingsPage(): bool
{
    return $this->filesystem->exists(Path::join($this->getModulePath(), 'html_admin', "mod_{$this->module}_settings.html.twig"))
        || $this->filesystem->exists(Path::join($this->getModulePath(), 'templates', 'admin', "mod_{$this->module}_settings.html.twig"));
}
```

**Status:** ✅ Applied to `Box\Mod` in this project.

### 1.2 — Standardise module navigation URIs

All custom modules **must** use `extension/settings/{mod}` as their navigation URI. Modules must **not** register their own top-level admin routes unless they have pages beyond settings.

```php
// Correct pattern for all settings-only modules
'uri' => $this->di['url']->adminLink('extension/settings/whatsapp'),
```

**Status:** ✅ Applied to Sociallogin and Googletagmanager.

### 1.3 — `Extension::get_settings()` template fallback

The Extension settings handler should always try `_settings` first, fall back to `_index`. This means modules can use either naming convention.

```php
try {
    return $app->render('mod_' . $mod . '_settings');
} catch (\Exception) {
    return $app->render('mod_' . $mod . '_index');
}
```

**Status:** ✅ Applied in this project.

### 1.4 — Template path fallback in TwigLoader

`TwigLoader::findTemplate()` must search both `html_{type}/` and `templates/{type}/`:

```php
$paths[] = Path::join($mods, ucfirst($name_split[1]), "html_{$this->options['type']}");
$paths[] = Path::join($mods, ucfirst($name_split[1]), 'templates', $this->options['type']);
```

**Status:** ✅ Applied in this project.

---

## Phase 2 — Isolate Custom Module Logic (Short-Term / Safe)

**Goal:** Ensure custom modules (Khalti, Whatsapp, SMS, Sociallogin, Googletagmanager) do not depend on internal FOSSBilling implementation details that change between upstream versions.

### 2.1 — Payment Adapter Isolation

The Khalti adapter (`library/Payment/Adapter/Khalti/Khalti.php`) currently:
- Reads session ID directly via `session_id()`
- Appends `restore_session` to return URLs manually
- Truncates customer fields inline

**Recommended decoupling:**

Extract a `KhaltiCustomerInfoBuilder` value object:
```php
final class KhaltiCustomerInfoBuilder
{
    public static function build(array $clientData): array { ... }
    public static function truncateName(string $name): string { ... }
    public static function sanitizePhone(string $phone): string { ... }
}
```

This isolates all field-limit and sanitisation logic into a testable, upgrade-safe class that doesn't touch FOSSBilling internals.

### 2.2 — Session Restore as a Dedicated Utility

The pattern of appending `restore_session=session_id()` to payment return URLs and forwarding it through `ipn.php` is fragile and scattered. Centralise it:

```php
// In a shared utility, e.g. FOSSBilling\SessionRestore
class SessionRestore
{
    public static function appendTo(string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?')
            . 'restore_session=' . urlencode(session_id());
    }

    public static function forward(array $params): array
    {
        if (!empty($_GET['restore_session'])) {
            $params['restore_session'] = $_GET['restore_session'];
        }
        return $params;
    }
}
```

Both `Khalti.php` and `ipn.php` then call the same utility instead of duplicating the logic.

### 2.3 — Notification Module Interface

Whatsapp and SMS modules both implement notification dispatch but share no contract. Define an interface:

```php
interface NotificationAdapterInterface
{
    public function send(string $to, string $message): bool;
    public function isConfigured(): bool;
}
```

Both `Whatsapp\Service` and `Sms\Service` implement it. The event hooks that send notifications depend on the interface, not the concrete class. Swapping providers (e.g. Twilio → custom) requires only a new implementation.

---

## Phase 3 — Service Contract Extraction (Medium-Term)

**Goal:** Replace direct service class dependencies with interfaces so modules can be swapped, tested, or extended without touching consumers.

### 3.1 — Define Core Service Interfaces

Create `src/library/FOSSBilling/Interfaces/` with contracts for the services most likely to be customised:

| Interface | Implemented By | Used In |
|---|---|---|
| `InvoiceServiceInterface` | `Invoice\Service` | Payment adapters, Order module |
| `ClientServiceInterface` | `Client\Service` | Profile, Auth, Email |
| `OrderServiceInterface` | `Order\Service` | Cart, Invoice, Dashboard |
| `NotificationAdapterInterface` | `Whatsapp\Service`, `Sms\Service` | Event hooks |
| `DomainRegistrarInterface` | Each registrar adapter | `Servicedomain\Service` |

### 3.2 — Bind Interfaces in DI Container

```php
// di.php — bind interface to implementation
$di[InvoiceServiceInterface::class] = fn() => $di['mod_service']('invoice');
```

Custom modules then type-hint against the interface:

```php
public function __construct(private readonly InvoiceServiceInterface $invoiceService) {}
```

This is the key change that makes future upstream refactoring of `Invoice\Service` non-breaking for custom modules.

### 3.3 — Event Hook Decoupling

Replace direct `$di['mod_service']('whatsapp')->send(...)` calls inside event hooks with a **notification bus**:

```php
// Register all active notification adapters at boot
$di['notification_bus'] = function () use ($di) {
    $bus = new NotificationBus();
    if ($di['mod']('whatsapp')->isInstalled()) {
        $bus->register($di['mod_service']('whatsapp'));
    }
    if ($di['mod']('sms')->isInstalled()) {
        $bus->register($di['mod_service']('sms'));
    }
    return $bus;
};
```

Event hooks then call `$di['notification_bus']->dispatch($event)` — unaware of which channels are active.

---

## Phase 4 — Upgrade Safety Layer (Long-Term)

**Goal:** Allow upstream FOSSBilling updates to be applied without breaking customisations.

### 4.1 — Separate Custom Code from Core Files

Maintain a strict inventory of which core files have been patched in this project:

| File | Why Patched | Upstream Risk |
|---|---|---|
| `src/modules/Cart/Service.php` | `getItemPrice()` — partner price override hook | Low — additive try/catch, safe to re-apply on upgrade |
| `src/library/Box/Mod.php` | `hasSettingsPage()` dual-path | Medium — method may be removed |
| `src/library/Box/TwigLoader.php` | `templates/admin/` fallback | Low — additive change |
| `src/library/Box/AppClient.php` | try-catch for 404 | Low |
| `src/modules/Extension/Controller/Admin.php` | `get_settings()` fallback | Medium |
| `src/modules/Extension/html_admin/mod_extension_index.html.twig` | JS redirect fix | Low |
| `src/ipn.php` | `restore_session` forwarding | High — upstream may refactor |

**Rule:** Any patch to a file not in `src/modules/{CustomModule}/` must be documented here and reviewed on every FOSSBilling version upgrade.

### 4.2 — Version-Gated Override Pattern

For patched core methods, prefer **extension over modification** where PHP allows it:

```php
// Instead of patching Box\Mod directly, extend it
class CustomMod extends \Box_Mod
{
    public function hasSettingsPage(): bool
    {
        return parent::hasSettingsPage()
            || $this->filesystem->exists(
                Path::join($this->_getModPath(), 'templates', 'admin', "mod_{$this->mod}_settings.html.twig")
            );
    }
}

// In di.php, bind the custom class
$di['mod'] = $di->protect(function ($name) use ($di) {
    $mod = new CustomMod($name);   // ← drop-in replacement
    $mod->setDi($di);
    return $mod;
});
```

This means upstream changes to `Box_Mod` only need a review of the overridden methods, not a full re-patch.

### 4.3 — Upgrade Checklist

Before applying a FOSSBilling upstream update:

1. Check if any file in the **patched file inventory** (§4.1) was changed upstream.
2. For each changed file, review whether the patch still applies cleanly.
3. Run `diff` against the upstream version of each patched file.
4. Verify custom modules still resolve templates via `/admin/extension/settings/{mod}`.
5. Test Khalti payment flow: initiate → cancel → return (session restore check).
6. Test SMS and Whatsapp settings pages load without "Unable to find template" errors.

---

## Summary

| Phase | Focus | Risk | Effort |
|---|---|---|---|
| **1** | Normalise module contract | None (already applied) | Done |
| **2** | Isolate custom module logic | Low | Days |
| **3** | Service interfaces + notification bus | Medium | Weeks |
| **4** | Upgrade safety layer + extension pattern | Low ongoing | Per upgrade |

The highest value next action is **Phase 2.2** (centralise `restore_session` utility) and **Phase 4.2** (extend `Box_Mod` rather than patching it), as these directly reduce upgrade friction for the two most fragile patches in the codebase.
