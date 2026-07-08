# Legacy Module & Theme Migration Guide (v0.7.2 → v0.8.3)

This document is the mandatory checklist when importing any module or theme from the legacy `merovps/` v0.7.2 codebase into the current `FOSSBilling/` codebase (0.8.3+). Work through every section before considering a module "ported".

---

## 1. Removed Modules — Do Not Import

These modules were removed or merged upstream and must NOT be ported as-is:

| Legacy Module | Disposition |
|---|---|
| `Paidsupport` | Removed in 0.8.0. No replacement — drop it. |
| `Servicemembership` | Removed in 0.8.0 (patch 47). Data migrated to `Servicecustom` type. |
| `Spamchecker` | Replaced by `Antispam` module (reCAPTCHA v3, Turnstile, hCaptcha). Any custom spam hooks must be moved there. |
| `Queue` | Removed (patch 37). Drop it. |

Modules that exist in legacy but not in current upstream (custom additions — evaluate case by case):

`Bulkpricingupdater`, `Googletagmanager`, `Partnership`, `Serviceserver`, `Sms`, `Sociallogin`, `Supportpin`, `Whatsapp`, `Wysiwyg`

`Wysiwyg` specifically: CKEditor is now bundled in core (`frontend/core/`). Drop the module; use `{{ wysiwyg('.selector') }}` in templates instead.

---

## 2. PHP Compatibility (8.3+ Required)

Any module or theme from 0.7.2 must be validated against PHP 8.3 strict mode.

**Check for:**
- `Deprecated: ${var}` string interpolation → use `"{$var}"` or concatenation
- Implicit nullable types: `function foo(string $x = null)` → `function foo(?string $x = null)`
- `match` / `enum` / `readonly` used as identifiers (reserved in 8.1+)
- `null` returns from non-nullable declared functions
- `array_key_first()`, `str_contains()`, etc. are safe (8.0+)
- `ramsey/uuid` calls → replace with `symfony/uid` (see §7)

Run after porting:
```bash
cd FOSSBilling && composer phpstan
```

---

## 3. Twig Filter and Tag Renames

These were renamed in the 0.7→0.8 transition. The old names no longer exist.

| Old (0.7.2) | New (0.8.3) | Applied by patch |
|---|---|---|
| `\|bb_date` | `\|format_date` | patch 29 |
| `\|bb_datetime` | `\|format_datetime` | patch 29 |
| `{% filter markdown %}…{% endfilter %}` | `{% apply markdown_to_html %}…{% endapply %}` | patch 25 |

**Search command:**
```bash
grep -rn "bb_date\|bb_datetime\|filter markdown" src/themes/merotheme/html/ src/modules/*/templates/
```

Email templates specifically: also check that action codes do NOT have `.html` suffix (patch 28 stripped them). If the legacy module registers email templates with `action: 'mod_foo_event.html'`, change to `action: 'mod_foo_event'`.

---

## 4. Class Namespace and API Changes

### 4.1 `Box_` classes removed (patch 32)

All `Box_*` classes are gone. Replace with:

| Legacy | Current |
|---|---|
| `Box_Paginator` | `\FOSSBilling\Pagination` (different API — see §4.2) |
| `Box_Twig*` | `FOSSBilling\Twig\*` |
| `Box_EventDispatcher` | Symfony EventDispatcher via DI |
| `Box_App` | `FOSSBilling\App` |

**Search command:**
```bash
grep -rn "Box_" src/modules/YourModule/ --include="*.php"
```

### 4.2 Pagination API

Legacy modules call `getPaginatedResultSet()` on a RedBeanPHP finder. The current API exposes `paginateDoctrineQuery()` on `$di['pager']`.

**Legacy pattern (0.7.2):**
```php
$result = $this->di['db']->find('Product', 'WHERE active = 1');
return $this->di['pager']->getPaginatedResultSet($result, $data, function($item) {
    return $this->toApiArray($item);
});
```

**Current pattern (0.8.3):**
```php
// For Doctrine queries:
$qb = $this->di['em']->createQueryBuilder();
// ...build query...
return $this->di['pager']->paginateDoctrineQuery($qb, $data);

// For RedBeanPHP (still acceptable, but prefer Doctrine for new code):
$sql = 'SELECT * FROM product WHERE active = 1';
[$sql, $count, $rows] = $this->di['pager']->getPaginatedSql($sql, [], $data);
```

Check `src/library/FOSSBilling/Pagination.php` for the current interface.

### 4.3 `Box\Mod` → `FOSSBilling\Module` namespace

If the legacy module extends `Box\Mod\Base` or references `Box\Mod` namespace directly, update to `FOSSBilling\Module`.

---

## 5. ORM: RedBeanPHP vs Doctrine

RedBeanPHP (`$di['db']`) is still present and still used in many core modules. It is acceptable for ported modules as a stopgap, but:

- **New code** must use Doctrine (`$di['em']`).
- Entities go in `src/modules/YourModule/Entity/`, repositories in `src/modules/YourModule/Repository/`.
- `$di['db']->find()` / `R::store()` patterns still work — but run PHPStan to catch type errors, as `$di['db']` returns untyped bean objects.

**Key rule:** Do not introduce **new** `R::` static calls. Use `$this->di['db']` (injected) or `$this->di['em']`.

---

## 6. Removed/Reorganized Core Modules

### 6.1 KB module → Support module (patch 36)

The old `Kb` module's tables were renamed into the `Support` module. If the legacy module queries `kb_article`, `kb_category`, or similar tables directly, update to `support_kb_*`. The `Support` module API now handles all KB endpoints.

### 6.2 Public support tickets unified (0.8.3)

Guest public tickets now share the same endpoint, templates, and event structure as client tickets. If the legacy module references old public ticket endpoints or `guest.support_ticket_*` API routes that differ from the current structure, update them. Check the current `Support/Api/Guest.php` for the live API.

### 6.3 Spamchecker → Antispam

Replace all `$di['mod_service']('spamchecker')` references with `$di['mod_service']('antispam')`. The hook events and method names differ — check `src/modules/Antispam/Service.php` for the current interface.

---

## 7. UUID Library Replacement

Legacy code uses `ramsey/uuid`. Current codebase uses `symfony/uid`.

| Legacy | Current |
|---|---|
| `use Ramsey\Uuid\Uuid;` | `use Symfony\Component\Uid\Uuid;` |
| `Uuid::uuid4()->toString()` | `Uuid::v4()->toRfc4122()` |

**Search command:**
```bash
grep -rn "Ramsey\\\\Uuid\|ramsey/uuid" src/modules/YourModule/ --include="*.php"
```

---

## 8. Frontend Build System (Webpack → esbuild)

Legacy themes had a Webpack Encore build. Current themes use esbuild with shared helpers.

**For any legacy theme being ported:**

1. Delete `webpack.config.js`, `encore.config.js`, any `assets/encore/` directory.
2. Create `esbuild.mjs` using the shared helpers pattern from an existing theme:
   ```js
   import { buildCssFile, buildJsFile, getThemeBuildPaths } from '../../../frontend/tools/esbuild-helpers.mjs';
   ```
3. Register the theme as an npm workspace in the root `package.json` under `"workspaces"`.
4. Add a minimal `package.json` in the theme directory (see `merotheme/package.json` as template).
5. Run `npm install` from `FOSSBilling/` to link the workspace.

**For admin themes**: Icon sprite generation is now shared via `frontend/tools/icon-sprite.mjs`. PNG/font-based icons must be replaced with SVG. Remove any inline base64 icon CSS.

**For client themes**: Alpine.js replaces jQuery for interactive components. The `fossbilling.js` bundle is loaded from `public_asset_url`, not the theme.

---

## 9. Email Template Changes

Two structural changes affect legacy email templates:

### 9.1 Template action codes (patch 28)

Legacy: `action: 'mod_client_signup.html'`  
Current: `action: 'mod_client_signup'` (no `.html`)

Update all `registerMailTemplate()` / manifest email action registrations.

### 9.2 File-backed templates (patch 52)

Email templates now have `is_custom` and `is_overridden` columns. Templates are synced from files. If the legacy module shipped DB-inserted email templates only (no file), it must also provide the template file under `templates/email/` in the module directory, named `mod_yourmodule_eventname.html.twig`.

The email Twig environment is sandboxed — check `EmailPolicy.php` for allowed tags/filters before using Twig features inside email templates.

---

## 10. Guest API Security Tightening (0.8.1–0.8.2)

Changes that affect legacy modules exposing guest endpoints:

- **`system/version` guest endpoint removed** — do not depend on it.
- **Guest cron endpoint requires a security hash** — if the legacy module calls `/api/guest/cron`, update to use the hash.
- **Rate limiting added** to guest invoice, PDF, and payment APIs — legacy modules that proxy these must handle HTTP 429.
- **reCAPTCHA v3** is now available via `Antispam` module (replaces Spamchecker's recaptcha). If the legacy module has inline recaptcha integration, remove it and dispatch through Antispam hooks.

---

## 11. Module Manifest: Removed `manifest` Column (patch 41)

The `extensions` table no longer has a `manifest` column. If the legacy module reads or writes `extension_meta` with a `manifest` key, replace with the proper `manifest.json` file in the module root. The manifest is loaded from disk, not DB.

---

## 12. Company Branding Paths (patch 49)

Legacy code referencing company logo/favicon via old asset paths must use the current path returned by `guest.system_company.logo_url` and `guest.system_company.favicon_url`. Do not hardcode `/public/data/assets/` paths.

---

## 13. Theme-Level Checklist (for merotheme integration)

When adapting legacy theme templates (`merovps/` theme) to `merotheme/`:

- [ ] Replace `bb_date` / `bb_datetime` with `format_date` / `format_datetime`
- [ ] Replace `{% filter markdown %}` with `{% apply markdown_to_html %}`
- [ ] Remove any jQuery — use Alpine.js or vanilla JS
- [ ] Use `fb_api_link` for API-triggered actions instead of custom event listeners
- [ ] Replace font icons / PNG icons with `@tabler/icons` SVG or custom SVG from `custom-icons/`
- [ ] Use `|asset_url` for theme assets, `|public_asset_url` for core assets
- [ ] Alpine component functions must be defined in inline `<script>` inside `{% block content %}` **before** the `x-data` element — not in `{% block js %}`
- [ ] Dark/light mode: use `data-theme="dark|light"` selector pattern; `text-white` needs counter-rules in light mode (see memory `merotheme-lightmode-css-system`)
- [ ] Color values: use CSS vars (`--color-primary`, `--color-secondary`) not hardcoded hex
- [ ] Twig strict vars: every variable must be declared or use `|default`. Run `composer test -- --filter StrictVariables` to find violations.

---

## 14. Quick Scan Commands

Run these against any module directory before starting a port:

```bash
MODULE=YourModuleName
SRC=src/modules/$MODULE

# Legacy Twig filters
grep -rn "bb_date\|bb_datetime\|filter markdown" $SRC/

# Removed Box_ classes
grep -rn "Box_" $SRC/ --include="*.php"

# Old paginator
grep -rn "getPaginatedResultSet\|Box_Paginator" $SRC/ --include="*.php"

# ramsey/uuid
grep -rn "Ramsey\\\\Uuid" $SRC/ --include="*.php"

# Removed modules referenced
grep -rn "Paidsupport\|Servicemembership\|Spamchecker\|Queue\b" $SRC/ --include="*.php"

# Email action codes with .html suffix
grep -rn "action.*\.html" $SRC/ --include="*.php" --include="*.json"

# Guest API version endpoint
grep -rn "system/version\|system_version" $SRC/ --include="*.php" --include="*.twig"
```

After porting:
```bash
composer cs:fix        # PSR-12 formatting
composer phpstan       # static analysis (must pass or add to baseline with justification)
composer test          # unit tests
npm run build-merotheme  # rebuild CSS/JS if templates changed
find src/data/cache -name "*.php" -delete  # clear Twig cache
```
