# Building a Client-Side Widget Module (FOSSBilling / MeroPanel)

A practical guide to building a self-contained module that injects UI into every
client-facing page **without editing any theme or core file** — modelled on the
shipped **Chat Widget** module (`src/modules/Chat`).

Use this for floating launchers, banners, cookie notices, live-chat embeds,
promo bars, announcement toasts — anything that renders into a theme "slot".

---

## 1. How the widget system works

Four pieces cooperate:

```
 your module Service                Widgets module                 client theme
 (WidgetProviderInterface)          (registry + cache)             (layout_*.html.twig)
 ─────────────────────────          ──────────────────             ──────────────────────
 getWidgets(): [                    buildRegistry() scans           {{ render_widgets(
   { slot, template, priority }  →  every active module,      →       'client.theme.body.end'
 ]                                  groups by slot, sorts            ) }}
                                    by priority, caches
```

1. **Your module** implements `FOSSBilling\Interfaces\WidgetProviderInterface`
   and returns a list of `{ slot, template, priority }` from `getWidgets()`.
2. The **Widgets module** (`Box\Mod\Widgets\Service`) scans all core + active
   modules once, builds a `slot → [widgets]` registry, and caches it
   (`CACHE_KEY = 'widgets_registry'`). The cache is invalidated automatically on
   any module activate/deactivate.
3. The theme calls the `render_widgets('<slot>')` Twig function at each slot
   location. It looks up the slot, renders each widget template in priority order
   (ascending; lower = earlier), and concatenates the HTML.
4. If a widget template throws, the error is swallowed per-widget
   (`widgets/mod_widgets_error.html.twig`) so one broken widget can't take down
   the page.

**Key source files** (read these first):

| File | Role |
|---|---|
| `src/library/FOSSBilling/Interfaces/WidgetProviderInterface.php` | The one-method contract |
| `src/modules/Widgets/Service.php` | Registry build, cache, slot lookup, activate/deactivate cache invalidation |
| `src/library/FOSSBilling/Twig/Extension/FOSSBillingExtension.php` → `renderWidgets()` | The `render_widgets()` Twig function |
| `src/modules/Chat/` | Reference implementation (copy this) |

---

## 2. Available theme slots (meroserver)

`render_widgets()` only outputs where the **theme** places a slot call. meroserver's
client layouts (`layout_default.html.twig`, `layout_public.html.twig`) expose:

| Slot | Where |
|---|---|
| `client.theme.body.start` | Immediately after `<body>` |
| `client.theme.body.end` | Just before `</body>` — **use this for floating widgets** |
| `client.theme.header.start` / `.end` | Around the header (authenticated layout) |
| `client.theme.content.before` / `.after` | Around the main content (authenticated layout) |
| `client.theme.footer.start` / `.end` | Around the footer (authenticated layout) |

Slot naming convention: `{area}.{namespace}.{location}[.{position}]`
(`area` = `admin`|`client`; `namespace` = `theme` for layout slots or a module
name for module-owned slots). `body.start`/`body.end` exist in **both** meroserver
client layouts, so a widget there shows on public *and* authenticated pages.

> To add a new slot, add `{{ render_widgets('client.theme.my.slot') }}` to the
> theme layout. Only `body.start`/`body.end` are guaranteed on every page today.

---

## 3. The Chat module, file by file (reference)

```
src/modules/Chat/
├── manifest.json                                   # module metadata
├── icon.svg                                         # extensions-list icon
├── Service.php                                       # implements WidgetProviderInterface + getPublicConfig()
├── Api/Guest.php                                      # /api/guest/chat/config  (public read)
├── Controller/Admin.php                                # settings page route + Extensions nav entry
└── templates/
    ├── admin/mod_chat_settings.html.twig                # admin config form (extension/config_save)
    └── client/widgets/mod_chat_widget.html.twig          # the rendered widget (self-contained CSS+JS)
```

### 3.1 `manifest.json`
```json
{
    "id": "chat",
    "type": "mod",
    "name": "Chat Widget",
    "description": "Floating support launcher …",
    "author": "MeroPanel",
    "license": "Apache-2.0",
    "version": "1.0.0",
    "minimum_fossbilling_version": "0.8.5",
    "icon_url": "icon.svg"
}
```

### 3.2 `Service.php` — register the widget + expose only public config
```php
namespace Box\Mod\Chat;

use FOSSBilling\InjectionAwareInterface;
use FOSSBilling\Interfaces\WidgetProviderInterface;

class Service implements InjectionAwareInterface, WidgetProviderInterface
{
    protected ?\Pimple\Container $di = null;
    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function getModulePermissions(): array
    {
        return ['manage_settings' => []];
    }

    // ── The widget registration ──────────────────────────────────────────
    public function getWidgets(): array
    {
        return [[
            'slot'     => 'client.theme.body.end', // where it renders
            'template' => 'mod_chat_widget',        // resolves to templates/client/widgets/mod_chat_widget.html.twig
            'priority' => 100,                       // lower renders first; default 10
        ]];
    }

    // ── Only public, validated values ever reach the browser ─────────────
    public function getPublicConfig(): array
    {
        $saved = $this->di['mod_config']('chat');            // reads stored config blob
        $cfg   = array_merge($this->getDefaults(), is_array($saved) ? $saved : []);
        // …validate/sanitize each field; return ONLY what the widget needs…
        return [ /* whatsapp_url, phone_number, tawk_property_id, … */ ];
    }
}
```

**Template name resolution:** `getWidgets()` returns `'template' => 'mod_chat_widget'`;
`render_widgets()` prepends `widgets/` and appends `.html.twig`, so the Twig client
loader resolves `widgets/mod_chat_widget.html.twig` from the module's
`templates/client/` directory. Because the theme resolution order is
`html_custom/` → `html/` → module `templates/client/`, a theme can override the
widget by dropping its own `widgets/mod_chat_widget.html.twig`.

### 3.3 `Api/Guest.php` — the public config endpoint
The widget renders with an **empty Twig context** and **no `guest` global**, so it
cannot read config at render time. Instead it fetches from the module's own guest
endpoint at runtime. The guest API is always available (public + authenticated pages).
```php
namespace Box\Mod\Chat\Api;

class Guest extends \FOSSBilling\Api\AbstractApi
{
    public function config(): array           // → GET /api/guest/chat/config
    {
        return $this->getService()->getPublicConfig();
    }
}
```

### 3.4 `Controller/Admin.php` — settings page + nav entry
```php
public function fetchNavigation(): array
{
    return ['subpages' => [[
        'location' => 'extensions',
        'label'    => __trans('Chat widget'),
        'index'    => 2100,
        'uri'      => $this->di['url']->adminLink('chat'),
    ]]];
}
public function register(\Box_App &$app): void { $app->get('/chat', 'get_index', [], static::class); }
public function get_index(\Box_App $app): string
{
    $this->di['is_admin_logged'];
    return $app->render('mod_chat_settings');
}
```

### 3.5 `templates/admin/mod_chat_settings.html.twig` — config form
Settings persist through the **core** `extension/config_save` endpoint — you write
no save code:
```twig
{% set p = admin.extension_config_get({"ext":"mod_chat"}) %}
<form method="post" action="{{ 'extension/config_save'|api_url }}"
      {{ fb_api_form({message: 'Chat widget settings updated'|trans}) }}>
    <input type="hidden" name="ext" value="mod_chat"/>
    <input type="tel"  name="whatsapp_number"  value="{{ p.whatsapp_number ?? '' }}"/>
    <input type="text" name="tawk_property_id" value="{{ p.tawk_property_id ?? '' }}"/>
    …
</form>
```

### 3.6 `templates/client/widgets/mod_chat_widget.html.twig` — the widget itself
```twig
{% verbatim %}          {# ← REQUIRED: see gotcha #2 #}
<style> /* self-contained, prefixed CSS using --color-primary etc. */ </style>
<script>
(function () {
    function build(cfg) { /* create DOM from cfg, wire events */ }
    function init() {
        fetch('/api/guest/chat/config', { headers: { 'Accept': 'application/json' },
                                          credentials: 'omit', cache: 'no-store' })
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(d => build(d && d.result ? d.result : null)) // API wraps payload in {result: …}
            .catch(() => {/* silently skip if unreachable */});
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>
{% endverbatim %}
```

---

## 4. Build your own — step by step

1. **Scaffold** `src/modules/<YourMod>/` mirroring the tree above. Module name is
   PascalCase; `manifest.json` `id` is lowercase.
2. **Service**: `implements InjectionAwareInterface, WidgetProviderInterface`;
   return your slot/template/priority from `getWidgets()`; add a
   `getPublicConfig()` that returns **only** browser-safe, validated values.
3. **Guest API** `Api/Guest.php`: a `config()` (or similar) read-only method that
   returns `getService()->getPublicConfig()`.
4. **Widget template** at `templates/client/widgets/<template>.html.twig`, wrapped
   in `{% verbatim %}…{% endverbatim %}`, that `fetch()`es your guest endpoint and
   builds itself client-side. Namespace **all** CSS ids/classes (e.g. `#mymod-root`)
   and use a high `z-index`.
5. **(Optional) Admin settings**: `Controller/Admin.php` (route + `fetchNavigation`)
   and `templates/admin/<mod>_settings.html.twig` posting to
   `extension/config_save` with `<input type="hidden" name="ext" value="mod_<id>">`.
6. **Install & activate** the module in Admin → Extensions. Activation invalidates
   the widget cache automatically, so the widget appears on the next page load.

---

## 5. Gotchas (these will bite you)

1. **No `guest` global, empty context in widgets.** `render_widgets()` renders with
   an empty context and the client Twig environment has no `guest` API global.
   Do **not** try `{{ guest.mymod_config() }}` in the widget — it won't exist.
   Fetch config over HTTP from your guest endpoint instead.

2. **Twig lexes `{#` inside your CSS/JS.** A CSS rule like `a{#id{…}}` or a JS
   template literal contains `{#`, which Twig reads as a comment-open and throws.
   Wrap the entire widget body in `{% verbatim %} … {% endverbatim %}`.

3. **`config_save` replaces, it does not merge.** The core `extension/config_save`
   overwrites the module's whole config blob with exactly the fields the form
   submits. Every field you want to keep must be present in the form on every save
   (the Chat form even submits a blank hidden `tawk_api_key` to *clear* a legacy
   secret). Missing fields are dropped.

4. **Widget cache.** The registry is cached under `widgets_registry` and rebuilt
   only on module activate/deactivate (via `Widgets::onAfterAdminActivate/
   DeactivateExtension`). If you change `getWidgets()` on an already-active module
   and the widget doesn't move, rebuild the cache: re-activate the module, use the
   Admin → Widgets "rebuild cache" action, or clear the app cache. After a bare DB
   import the activate/deactivate listeners may not be registered — run
   `POST /api/admin/hook/batch_connect {"mod":"widgets"}` (same pattern as the Cart
   login listener).

5. **Slot must exist in the theme.** `render_widgets('x')` outputs nothing if the
   theme never calls that slot. Stick to `client.theme.body.end` (present on every
   meroserver client page) unless you also add the slot to the layout.

6. **Priority is ascending & must be a positive int.** Lower renders first; invalid
   or `<= 0` values fall back to the default `10`.

7. **Security — public boundary.** Everything `getPublicConfig()` returns is visible
   to any visitor. Never return secrets/API keys, session, or client data. Validate
   each value (the Chat module regex-checks phone/WhatsApp digits and restricts Tawk
   IDs to `[A-Za-z0-9_-]{1,128}` because they become part of a third-party script URL).

---

## 6. Checklist

- [ ] `manifest.json` with lowercase `id`, `type: "mod"`
- [ ] `Service implements WidgetProviderInterface` + `getWidgets()` + `getPublicConfig()`
- [ ] `Api/Guest.php` read-only config endpoint
- [ ] Widget template under `templates/client/widgets/`, wrapped in `{% verbatim %}`
- [ ] All CSS/JS identifiers namespaced; high `z-index`; uses `--color-primary` etc.
- [ ] Fetches config from `/api/guest/<id>/config`, reads `d.result`
- [ ] (Optional) admin settings via `extension/config_save` (submit **all** fields)
- [ ] Activate module → confirm widget renders; if not, rebuild widget cache
- [ ] `composer cs:fix && composer phpstan` clean
```
