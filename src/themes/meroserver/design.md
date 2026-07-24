# MeroVPS UI Reproduction Guide

This document is the visual source of truth for an AI coding agent creating or
editing MeroVPS marketing pages. It describes the patterns already implemented
in the active WordPress theme. Reproduce these patterns; do not invent a second
design language.

## 1. Source priority

When this document and the code differ, inspect the implementation in this
order:

1. `wp-content/themes/merovps/style.css` — live theme behavior and page-specific
   overrides.
2. `tailwind.config.js` — shared Tailwind tokens and utility names.
3. `wp-content/themes/merovps/header.php` and `footer.php` — canonical site
   chrome.
4. `wp-content/themes/merovps/page-openclaw.php` — canonical AI-product page.
5. `src/styles/_base.css`, `_components.css`, and `_animations.css` — reusable
   utility behavior.
6. `src/components/navbar.html` and `footer.html` — static component references.

Do not use WordPress core theme styles as design references. Scope custom page
styles below `.merovps-marketing-page` and, where appropriate, a page class such
as `.page-openclaw`.

## 2. Visual character

MeroVPS is a bright, precise cloud-infrastructure brand:

- Warm near-white canvas rather than stark gray.
- Blue is the primary action and technical signal.
- Dark navy is reserved for high-contrast feature bands and consoles.
- Soft lavender surfaces organize content without heavy separators.
- Large, tightly tracked headings contrast with calm, readable body copy.
- Cards feel engineered: fine borders, moderate radii, restrained shadows.
- AI is represented as a connected system—agent core, tools, routes, status,
  logs, and workflows—not as a decorative humanoid robot.

The overall tone is capable, trustworthy, local, and technically modern. Avoid
neon cyberpunk visuals, excessive glassmorphism, random gradients, or generic
purple “AI” styling.

## 3. Design tokens

### Colors

Use semantic Tailwind names when available.

| Role | Token or value | Usage |
| --- | --- | --- |
| Primary | `primary`, `#5271ff` | Main CTA, links, active icons, focus |
| Primary hover | `#4363f1` | Filled button hover |
| Deep blue | `#224dda` / `#2447a7` | Darker brand copy and gradients |
| Dark navy | `dark-navy`, `#050b1c` | Dark technical sections |
| Page background | `background`, `#fbf8ff` | Default canvas |
| White surface | `surface-container-lowest`, `#ffffff` | Cards, menus |
| Low surface | `surface-container-low`, `#f3f2fe` | Alternate sections |
| Mid surface | `surface-container`, `#eeedf9` | Hover and grouped controls |
| Main text | `on-surface`, `#1a1b23` | Headings and important copy |
| Muted text | `on-surface-variant`, `#444655` | Body and metadata |
| Subtle text | `#656879` / `#747686` | Captions and supporting copy |
| Border | `outline-variant`, `#c4c5d7` | Dividers and component borders |
| Success | `#159967` / `#18aa70` | Online, healthy, verified |
| Success surface | `#e8f8f1` | Success icon background |
| Warm accent | `#bd540b` on `#fff3e9` | AI/product eyebrow only |
| Error | `error`, `#ba1a1a` | Destructive or failed state |

Opacity is important. Default card borders are typically
`rgba(82,113,255,.15)` or `#e2e4ec`; header/footer borders use
`border-outline-variant/30`. Do not use full-strength gray borders everywhere.

### Typography

The live WordPress theme takes precedence over the older Tailwind font aliases:

```css
--font-brand-sans: "Inter", "Helvetica Neue", Helvetica, Arial, system-ui, sans-serif;
--font-brand-display: "Inter Tight", "Helvetica Neue", Helvetica, Arial, system-ui, sans-serif;
```

- Headings: `Inter Tight`, weight 700, balanced wrapping.
- UI, labels, buttons, and body: `Inter`.
- Code, logs, IDs, and command output: `JetBrains Mono` or a monospace fallback.
- H1: `clamp(3.2rem, 5.3vw, 5.35rem)`, line-height `1`, tracking `-.04em`.
- Section H2: `clamp(2.3rem, 3.8vw, 3.55rem)`, line-height `1.07`,
  tracking `-.04em`.
- H3: line-height `1.16`, tracking `-.025em`.
- Lead copy: `1rem–1.125rem`, line-height `1.65–1.7`.
- Body: `14–16px`, line-height `1.6–1.65`.
- Eyebrow: `11–12px`, weight `700–800`, tracking `.08–.11em`, uppercase.
- Metadata: `10–12px`, weight `600–700`.

Keep prose measures near `42rem`. Do not stretch paragraphs across an entire
wide section.

### Spacing and layout

- Site maximum width: `1536px`.
- Standard outer gutter: `16px` mobile, `24px` tablet, `40px` desktop.
- Standard section padding: `clamp(80px, 8vw, 112px)`.
- Compact section padding: `clamp(64px, 6vw, 88px)`.
- Grid gaps: `20–32px`; large split layouts: `48–112px`.
- Base spacing rhythm: multiples of `4px`; common values are 8, 12, 16, 24,
  32, 48, 64, 80, and 112.

Canonical container:

```html
<section class="py-24">
  <div class="max-w-7xl mx-auto px-margin-desktop">
    <!-- section content -->
  </div>
</section>
```

For theme CSS, use the shared variables:

```css
.feature-section {
  padding-block: var(--space-section-lg);
}

.feature-section__inner {
  width: min(
    calc(100% - (var(--responsive-page-gutter, 48px) * 2)),
    var(--site-container-width)
  );
  margin-inline: auto;
}
```

### Shape and elevation

- Small controls: `8–12px` radius.
- Buttons: `12px` for product pages or fully rounded for global CTAs. Be
  consistent within a section.
- Standard cards: `16–24px` radius.
- Large visual panels: `24–32px` radius.
- Pills/status: `999px`.
- Card shadow: `0 14px 35px rgba(31,42,85,.05)`.
- Elevated hover: `0 22px 48px rgba(31,42,85,.09)`.
- Hero visual: `0 35px 90px rgba(31,42,85,.14)`.

Shadows should be blue-black and low-opacity, never hard neutral-black boxes.

## 4. Page anatomy

A new AI or automation product page should normally follow this order:

1. Shared sticky header.
2. Product hero: eyebrow, outcome-led H1, concise lead, three benefits, price
   or proof, two actions, trust note, technical visual.
3. Trust or proof strip.
4. “How it works” in three clear steps.
5. Feature grid explaining infrastructure and operational value.
6. Use-case grid explaining what the agent can do.
7. Pricing cards and comparison table where relevant.
8. Security, support, or knowledge-base band.
9. FAQ.
10. High-contrast final CTA.
11. Shared footer.

Alternate white and low-lavender sections. Use one dark or strong-blue section
as a visual anchor; do not make every section a gradient.

## 5. Canonical components

### Header and footer

Use the theme’s `get_header()` and `get_footer()` in WordPress templates. Never
rebuild the navigation inside a page template.

Header behavior:

- Sticky, `80px` tall, `z-index: 50`.
- White at roughly 80–95% opacity with `backdrop-blur-xl`.
- Fine bottom border and a subtle shadow after scrolling.
- Desktop navigation from `lg` upward; hamburger below `lg`.
- Login is secondary; Account is the primary filled action.

Footer behavior:

- White surface with a fine top border.
- Optional pre-footer action band.
- Responsive link grid, payment methods, legal links, social links, and brand
  watermark.

### Section heading

```html
<header class="text-center mb-16 reveal">
  <span class="oc-badge">Agent capabilities</span>
  <h2 class="mt-4">A clear outcome-led section title</h2>
  <p class="max-w-xl mx-auto">
    One short explanation of the user benefit and the technical reason to
    believe it.
  </p>
</header>
```

Use sentence case for headings. Avoid vague labels such as “Revolutionize your
future.”

### Eyebrow badge

Default:

```css
display: inline-flex;
align-items: center;
gap: .45rem;
padding: .5rem .8rem;
border: 1px solid rgba(82,113,255,.2);
border-radius: 999px;
background: #edf1ff;
color: #405fe7;
font-size: .7rem;
font-weight: 750;
letter-spacing: .1em;
text-transform: uppercase;
```

The warm variant uses `#fff3e9` and `#bd540b` and is appropriate for the hero
product label. Use at most one badge per section.

### Buttons

Primary product action:

```css
min-height: 52px;
padding: .85rem 1.45rem;
border: 1.5px solid #5271ff;
border-radius: 12px;
background: #5271ff;
color: #fff;
font-size: 13–14px;
font-weight: 750;
box-shadow: 0 13px 28px rgba(82,113,255,.24);
```

Hover: move up `2px`, use `#4363f1`, and slightly increase the shadow. Active:
return toward the baseline or scale to `.97`. Secondary buttons use a white or
transparent surface, primary border, and blue text.

Every action needs a visible `:focus-visible` ring. Minimum interactive height
is `44px`; preferred CTA height is `50–54px`.

### Feature card

```html
<article class="oc-feature-card reveal">
  <span class="material-symbols-outlined text-primary">shield_lock</span>
  <h3>Private by design</h3>
  <p>Your agent runs in an isolated environment with explicit access controls.</p>
</article>
```

- White background, `1px #e2e4ec` border, `21–24px` radius.
- `24–32px` padding.
- Icon sits in a `44–48px` pale-blue rounded square.
- Hover moves up no more than `3px`.
- All cards in one row should have equal visual weight and aligned content.

Use-case cards are more compact: roughly `192px` minimum height and `24–26px`
padding, with a `44px` icon tile beside the H3.

### Agent ecosystem visual

The OpenClaw hero is the reference for depicting an AI agent:

- Large light panel with a subtle technical grid.
- Central agent card.
- Four to six satellite tool cards.
- Fine connector routes behind all cards.
- A small status pill showing “Agent online” and a meaningful value.
- Use real capability labels such as Tools, Workflows, Data, APIs, Telegram,
  Email, or Calendar.

Layer order must be: background grid → routes → core and tools → status/cursor.
Keep labels readable without relying on animation. Decorative routes and cursors
must be `aria-hidden`; the containing visual should have a useful accessible
label.

On mobile, simplify the composition. Hide nonessential satellites, reduce the
panel height, and preserve the core, two or three connections, and status.
Never shrink the desktop diagram until its text becomes unreadable.

### Agent chat or activity console

Use the established support-console language for conversational or execution
previews:

- Outer panel: translucent near-white, `20–26px` radius, fine white/blue border.
- Header: compact environment label plus green live status.
- User message: white bubble, dark text.
- Agent message: primary-blue bubble, white text.
- Bubble radii are directional (`5px 17px 17px 17px` and mirrored).
- Footer: pale blue summary/action area.
- Logs, duration, tool names, and IDs use tabular numbers or monospace.

Do not render a fake text input unless it represents a real interaction. For a
static marketing preview, an activity summary is clearer and more honest.

### Status and health

Online/healthy:

```html
<span class="agent-status">
  <i aria-hidden="true"></i>
  Agent online
  <strong>24/7</strong>
</span>
```

Use a green dot with a low-opacity outer ring. Always include status text; never
communicate state with color alone. Reserve amber for attention and red for an
actual failure.

### Pricing cards and comparison tables

- Three-column pricing grids collapse to one column on small screens.
- Featured plans use a primary border and one “Most popular” label.
- Keep resource values scannable and prices visually dominant.
- Tables may scroll horizontally; the first column should remain readable and
  can be sticky.
- Use check icons with accessible text or visually hidden labels.

### FAQ

Reuse the existing `.mero-faq-*` accordion:

- One bordered white group, `24px` outer radius.
- Numbered questions.
- Only the expanded item receives the primary left border and light gradient.
- Button controls expose `aria-expanded`.
- Keyboard focus must remain visible.

## 6. AI-specific interface patterns

When creating an agent dashboard, agent detail screen, or embedded product
mockup, compose these primitives:

- **Agent identity:** icon/avatar, agent name, one-line purpose, environment.
- **Run state:** online, running, waiting, needs approval, completed, or failed.
- **Current task:** plain-language task title before technical execution detail.
- **Tool activity:** tool icon, action label, target, timestamp, and result.
- **Approval:** clearly separated callout with consequence and primary/secondary
  actions.
- **Output:** readable answer first; expandable logs or payloads second.
- **Metrics:** uptime, run duration, token/compute usage, and last execution only
  when meaningful.

Suggested desktop shell:

```text
┌──────────────┬──────────────────────────────────────┬───────────────────┐
│ Agent list   │ Conversation / current task          │ Activity & status │
│ 240–280px    │ flexible, readable content measure   │ 280–340px         │
└──────────────┴──────────────────────────────────────┴───────────────────┘
```

At tablet width, turn the activity rail into a drawer. On mobile, show a single
content column with agent selection and activity behind labeled buttons. The
conversation must remain the primary surface.

Use `surface-container-low` for secondary rails, white for the active work
surface, `outline-variant/30` for dividers, and primary blue only for selected
state or action. Dense application screens should use smaller radii and weaker
shadows than marketing cards.

## 7. Motion and interaction

- Standard hover/focus transition: `180–220ms ease`.
- Reveal animation: opacity plus a small upward translation.
- Stagger related cards lightly.
- Button lift: maximum `2px`; card lift: maximum `3px`.
- Status pulse may be used only for a genuinely live/running state.
- Do not animate large background layers continuously.
- Do not use bounce effects.

All content must remain visible and understandable under
`prefers-reduced-motion: reduce`. The existing `.reveal` system must resolve to
full opacity with no transform in reduced-motion mode.

## 8. Responsive rules

- Mobile: below `640px`.
- Tablet: `640–1023px`.
- Desktop: `1024px` and above.
- Wide desktop container caps at `1536px`.

Required behavior:

- Hero split layout becomes one column below desktop.
- Hero copy appears before its visual.
- Two-column grids collapse to one column; three-column grids may pass through
  two columns at tablet width.
- CTAs become full width on narrow phones when two buttons would wrap poorly.
- Section padding reduces to approximately `64–76px`.
- Outer gutters reduce to `16px`.
- Hide decorative elements before shrinking meaningful content.
- Tables scroll within their own wrapper and never cause body overflow.
- The header menu becomes the existing mobile accordion.

Test at 360, 768, 1024, and 1440 CSS pixels.

## 9. Accessibility and content

- Use one H1 per page and preserve heading order.
- Body text should meet WCAG AA contrast; muted text is not allowed for critical
  information.
- Use real buttons for actions and links for navigation.
- Icon-only controls require an accessible name.
- Decorative icons and connector graphics use `aria-hidden="true"`.
- Focus uses a visible blue ring with `2–3px` offset.
- Interactive target size is at least `44 × 44px`.
- Never disable zoom or rely on hover alone.
- Do not claim an agent completed, secured, or monitored something unless the
  interface has data supporting that state.
- Prefer specific copy: “Connect Google Calendar” over “Unlock productivity.”

## 10. Implementation conventions

- In PHP, escape translated text and URLs with the appropriate WordPress
  functions (`esc_html_e`, `esc_attr_e`, `esc_url`).
- Use Material Symbols already loaded by the theme; do not add another icon
  library for a few icons.
- Prefer semantic classes and reusable component rules over long inline styles.
- If Tailwind classes are assembled dynamically, add them to the safelist.
- Extend the existing page scope instead of adding global element selectors.
- Reuse `.reveal`, `.oc-badge`, `.mero-faq-*`, shared buttons, and shared
  container rules before creating a new variant.
- Preserve the shared header, footer, currency behavior, and navigation scripts.
- Do not edit generated `tailwind.css` without also updating its source.

## 11. Anti-patterns

Do not:

- Introduce a different blue or a new purple as the main brand color.
- Use gradients on every card or section.
- Fill the page with floating glass panels.
- Use emoji as production icons.
- Mix pill buttons and square buttons randomly in the same component family.
- Use huge centered paragraphs.
- Place important copy over a busy image without a strong overlay.
- Animate connector lines so aggressively that they compete with the content.
- represent AI with unexplained sparkles alone.
- Duplicate the header or footer inside a page.
- copy styling from `twenty*` WordPress themes.

## 12. AI agent completion checklist

Before considering a UI complete, verify:

- [ ] The active MeroVPS header and footer are reused.
- [ ] Colors map to existing semantic tokens.
- [ ] Fonts follow Inter Tight for headings and Inter for UI/body.
- [ ] The page aligns to the `1536px` container and responsive gutters.
- [ ] The opening section has one outcome-led H1 and no more than two CTAs.
- [ ] Cards share border, radius, padding, and elevation behavior.
- [ ] Agent visuals communicate tools, routes, and status—not decoration alone.
- [ ] Empty, loading, running, approval, success, and failure states are
      understandable where applicable.
- [ ] Keyboard focus, labels, contrast, reduced motion, and target size are
      handled.
- [ ] The page is checked at 360, 768, 1024, and 1440 widths.
- [ ] No body-level horizontal overflow exists.
- [ ] Generated CSS sources, PHP escaping, and Tailwind safelist requirements
      are respected.
