# NameHero-Inspired Motion and SVG Implementation Plan

Last reviewed: 2026-07-30

Reference: [NameHero homepage](https://www.namehero.com/)

Target: MeroServer public homepage in `src/themes/meroserver`

## Intent

Use the visual rhythm of NameHero's marketing page as inspiration for MeroVPS: layered hero artwork, restrained ambient motion, staged content reveals, interactive cards, and smooth transitions between dense product sections.

This is a behavioral reference, not a source-code or asset port. Do not copy NameHero's illustrations, SVG paths, logos, text, CSS, or JavaScript. Create original MeroVPS cloud-platform artwork using the existing blue, cyan, green, and warm-neutral palette.

## Reference observations

The accessible NameHero page and rendered page structure show a motion-friendly composition built around:

- A large illustrated hero with several independently positioned visual layers.
- Product-family selectors followed by pricing cards.
- Repeated illustration-and-copy feature sections that alternate visual weight.
- Technology/logo bands, location artwork, testimonials, support content, and a final conversion area.
- Rounded cards and controls whose depth changes on interaction.
- Decorative SVG-style artwork used to make transitions between sections feel continuous.

The raw homepage response currently presents an anti-bot interstitial, so exact private animation code, keyframe names, SVG paths, and easing values could not be reliably inspected. The recipes below reproduce the observable design language with original implementation choices suited to this codebase.

## Existing MeroServer foundations

The homepage already has useful motion hooks:

- `html/mod_index_dashboard.html.twig`
  - Alpine announcement rotation with named enter and leave transitions.
  - Inline SVG icons and an SVG metrics path in the account console.
  - Dynamic category cards, capability items, feature cards, CTA, and FAQ.
- `assets/css/custom.css`
  - Homepage styles start at `.ref-home`.
  - The announcement already performs a vertical 3D flip.
  - Buttons and cards already have basic hover elevation.
  - Light and dark homepage variants are present.
  - Reduced-motion rules exist.
- `assets/meroserver.js`
  - Alpine and the Collapse plugin are already loaded.
  - Vanilla JavaScript is the appropriate choice for viewport reveal orchestration.

No animation library is required. CSS, Alpine, and one `IntersectionObserver` are sufficient.

## Motion principles

1. **Motion must explain hierarchy.** Hero copy enters before the console; section headings enter before their cards.
2. **Ambient motion stays quiet.** Only small decorative layers should loop. Content must remain stable and readable.
3. **Use transforms and opacity.** Avoid animating layout properties such as width, height, top, left, or margin.
4. **Keep one visual direction.** Page reveals rise slightly; the announcement continues its existing vertical flip.
5. **Interaction is faster than entrance.** Hover and press feedback should complete in 120–240 ms; viewport reveals can take 480–720 ms.
6. **Motion is progressive enhancement.** Content is visible if JavaScript fails, and all essential state changes remain understandable without animation.

## Shared motion tokens

Add these near the homepage variables in `assets/css/custom.css`:

```css
.ref-home {
    --motion-instant: 120ms;
    --motion-fast: 180ms;
    --motion-base: 260ms;
    --motion-reveal: 620ms;
    --motion-slow: 900ms;
    --ease-standard: cubic-bezier(.2, 0, 0, 1);
    --ease-out: cubic-bezier(.16, 1, .3, 1);
    --ease-emphasized: cubic-bezier(.22, 1, .36, 1);
    --reveal-distance: 22px;
}
```

Use the same durations in light and dark mode. Theme changes may alter colors and shadows, but not movement.

## Reveal system

### Markup

Add `data-reveal` to meaningful groups rather than every small element. Use `data-reveal="hero"`, `"up"`, `"scale"`, or `"line"`. Add `style="--reveal-index: …"` only for short staggered collections.

Example:

```twig
<div class="ref-home-features-heading" data-reveal="up">
    ...
</div>

<div class="grid ...">
    {% for card in cards %}
        <article
            data-reveal="up"
            style="--reveal-index: {{ loop.index0 }}"
        >
            ...
        </article>
    {% endfor %}
</div>
```

### CSS

Only hide reveal elements after JavaScript declares the behavior available. This prevents invisible content when scripts are delayed or blocked.

```css
html.has-reveal [data-reveal] {
    opacity: 0;
    transform: translate3d(0, var(--reveal-distance), 0);
    transition:
        opacity var(--motion-reveal) var(--ease-out),
        transform var(--motion-reveal) var(--ease-out);
    transition-delay: min(calc(var(--reveal-index, 0) * 70ms), 280ms);
}

html.has-reveal [data-reveal="scale"] {
    transform: translate3d(0, 12px, 0) scale(.975);
}

html.has-reveal [data-reveal="line"] {
    transform: scaleX(.82);
    transform-origin: left center;
}

html.has-reveal [data-reveal].is-revealed {
    opacity: 1;
    transform: none;
}
```

### JavaScript

Add a small initializer inside the existing `DOMContentLoaded` callback in `assets/meroserver.js`:

```js
function initHomepageReveals() {
  const elements = Array.from(document.querySelectorAll('.ref-home [data-reveal]'));
  if (!elements.length) return;

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduceMotion || !('IntersectionObserver' in window)) {
    elements.forEach((element) => element.classList.add('is-revealed'));
    return;
  }

  document.documentElement.classList.add('has-reveal');

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-revealed');
      observer.unobserve(entry.target);
    });
  }, {
    rootMargin: '0px 0px -10% 0px',
    threshold: 0.12,
  });

  elements.forEach((element) => observer.observe(element));
}

initHomepageReveals();
```

Reveals run once. Replaying every time a user scrolls past a section makes the page feel unstable.

## Original SVG system

### Art direction

Build original MeroVPS artwork around the current account console instead of adding a generic superhero illustration. Recommended motifs:

- Cloud nodes connected by curved deployment paths.
- Kathmandu region marker and small latency/status chips.
- Container, database, SSL shield, Git branch, and autoscale symbols.
- A deployment stream flowing into the existing console.
- Soft grid arcs that bridge the hero background to the product cards.

### SVG construction rules

- Use inline SVG when paths need to animate or inherit theme colors.
- Use `viewBox`; do not hard-code both rendered width and height.
- Use `currentColor` for simple icons.
- Put shared gradients, masks, and filters in one `<defs>` block.
- Prefix IDs, such as `mh-hero-gradient`, to avoid collisions.
- Keep decorative SVGs `aria-hidden="true"` and `focusable="false"`.
- Give meaningful standalone diagrams a visible caption or `<title>`.
- Prefer strokes between `1.5` and `2`, with round caps and joins.
- Optimize each illustration; target less than 20 KB of minified SVG markup.
- Avoid large animated blur or displacement filters. They are expensive on mobile.

Example deployment path:

```svg
<svg class="ref-home-deploy-map" viewBox="0 0 420 240"
     fill="none" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="mh-deploy-line" x1="40" y1="200" x2="360" y2="40">
            <stop stop-color="#5271ff"/>
            <stop offset=".55" stop-color="#17a7d4"/>
            <stop offset="1" stop-color="#20b978"/>
        </linearGradient>
    </defs>
    <path class="ref-home-deploy-path"
          pathLength="1"
          d="M34 198 C118 198 116 96 214 112 S312 54 382 42"
          stroke="url(#mh-deploy-line)"
          stroke-width="2"/>
    <circle class="ref-home-deploy-node" cx="34" cy="198" r="6"/>
    <circle class="ref-home-deploy-node" cx="214" cy="112" r="6"/>
    <circle class="ref-home-deploy-node" cx="382" cy="42" r="6"/>
</svg>
```

```css
.ref-home-deploy-path {
    stroke-dasharray: 1;
    stroke-dashoffset: 1;
}

.is-revealed .ref-home-deploy-path {
    animation: ref-home-draw-path 1.15s var(--ease-emphasized) 180ms forwards;
}

@keyframes ref-home-draw-path {
    to { stroke-dashoffset: 0; }
}
```

## Homepage implementation map

### 1. Announcement

Keep the existing 5-second Alpine rotation and vertical flip. It already has the right personality.

Improve it by:

- Pausing when the document is hidden.
- Resetting the interval after keyboard focus or pointer hover ends.
- Disabling the flip under `prefers-reduced-motion`, while still changing the text.
- Keeping the title on one line with ellipsis to prevent layout shift.

Do not add another marquee or ticker near it.

### 2. Hero copy

Apply a single staged entrance on initial load:

| Element | Delay | Motion |
|---|---:|---|
| Announcement | 0 ms | Fade and rise 10 px |
| Heading | 80 ms | Fade and rise 18 px |
| Description | 160 ms | Fade and rise 18 px |
| Primary CTA | 240 ms | Fade, rise 14 px, scale from .98 |
| Trust row | 320 ms | Fade only |

The heading must remain a stable block. Do not animate words or letters individually.

### 3. Account console

This is the primary illustrated hero object and should receive the richest motion:

- Enter 100 ms after the hero heading begins.
- Fade, rise 24 px, and rotate no more than `1deg`.
- Draw the metrics chart once using `stroke-dasharray` and `stroke-dashoffset`.
- Grow metric bars with `transform: scaleX()` from the left.
- Pulse the green operational dot every 2.8 seconds.
- Float one or two small deployment/status chips around the console by 4–6 px.
- Pause all ambient motion when the tab is not visible through normal browser animation throttling; no manual requestAnimationFrame loop is needed.

```css
@keyframes ref-home-status-pulse {
    0%, 70%, 100% { box-shadow: 0 0 0 4px rgba(32,185,120,.10); }
    82% { box-shadow: 0 0 0 9px rgba(32,185,120,0); }
}

@keyframes ref-home-float {
    0%, 100% { transform: translate3d(0, 0, 0); }
    50% { transform: translate3d(0, -6px, 0); }
}
```

Use `6–8s` for floating layers with different negative delays. Avoid moving the whole console continuously; it is content, not decoration.

### 4. Dynamic product cards

Reveal the four cards with a 70 ms stagger. Keep the existing hover lift, then add:

- Arrow translation of 3 px on hover.
- Border highlight using the card's badge color.
- A soft radial highlight that follows the visual center, not the pointer.
- `:focus-visible` treatment equal to hover.
- `translateY(-4px)` maximum to avoid excessive motion.

Do not animate the price value when it is rendered from Twig; doing so could imply that pricing is live-changing.

### 5. Capability band

Reveal the label first, then the five numbered capabilities at 55 ms intervals. A short connector line may draw left-to-right on desktop. On mobile, remove the line and reveal the stacked rows normally.

### 6. Feature cards

Use one reveal per card. Icons can rotate or scale by a very small amount as their card enters:

```css
html.has-reveal .ref-home-feature-icon {
    transform: scale(.88) rotate(-3deg);
    transition: transform 520ms var(--ease-emphasized);
}

.is-revealed .ref-home-feature-icon {
    transform: none;
}
```

No perpetual icon animation is needed in this section.

### 7. Final CTA

Use one slow ambient gradient drift behind the card. Keep the CTA content static after its reveal.

```css
@keyframes ref-home-gradient-drift {
    0%, 100% { transform: translate3d(-3%, 0, 0) scale(1); }
    50% { transform: translate3d(3%, -2%, 0) scale(1.04); }
}
```

Animate a pseudo-element, not the card background itself. Use a duration of `14–18s`.

### 8. FAQ

Continue using Alpine Collapse. Add:

- Chevron rotation in `220ms var(--ease-standard)`.
- Question color transition in 180 ms.
- A subtle background tint on the open item.
- No fade on the answer text while its height is changing; combining both can look blurry.

## Dark-mode treatment

- Motion timing stays identical.
- Replace heavy dark shadows with a thin illuminated border and low-opacity blue glow.
- SVG strokes use theme variables rather than fixed black or white.
- Decorative grid opacity should be lower in dark mode to prevent shimmer while scrolling.
- Green status pulses need less spread in dark mode.
- Test animated gradients for banding on typical laptop displays.

Suggested variables:

```css
.ref-home {
    --motion-accent: #5271ff;
    --motion-accent-2: #17a7d4;
    --motion-success: #20b978;
    --motion-trail: rgba(82,113,255,.18);
}

html[data-theme="dark"] .ref-home {
    --motion-accent: #7890ff;
    --motion-accent-2: #46c4e8;
    --motion-success: #43d69b;
    --motion-trail: rgba(120,144,255,.24);
}
```

## Reduced-motion contract

Add one consolidated homepage rule:

```css
@media (prefers-reduced-motion: reduce) {
    .ref-home *,
    .ref-home *::before,
    .ref-home *::after {
        scroll-behavior: auto !important;
    }

    .ref-home [data-reveal],
    .ref-home [class*="ref-home-"] {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .01ms !important;
    }

    .ref-home [data-reveal] {
        opacity: 1 !important;
        transform: none !important;
    }
}
```

Prefer narrower selectors if the broad `[class*="ref-home-"]` rule conflicts with Alpine Collapse during testing. Reduced motion should remove movement, not hide state changes or prevent the announcement from updating.

## Performance budget

- Zero animation dependencies.
- One `IntersectionObserver` for all homepage reveal elements.
- Disconnect each observed element after its first reveal.
- At most two perpetual animated elements above the fold.
- No JavaScript animation loops.
- No animation of layout-triggering properties.
- No full-screen animated blur.
- SVG markup below 20 KB per major illustration.
- Avoid more than 12 simultaneous `will-change` layers; remove `will-change` after entrance if practical.
- Preserve hero height and SVG aspect ratios to prevent cumulative layout shift.

## File-by-file work

### `html/mod_index_dashboard.html.twig`

1. Add `data-reveal` attributes to section-level groups.
2. Add stagger indices to product, capability, and feature-card loops.
3. Add original deployment-path SVG layers around the account console.
4. Add semantic wrappers for the feature heading and CTA reveal.
5. Keep dynamic products, announcements, and pricing logic unchanged.

### `assets/css/custom.css`

1. Add shared motion tokens.
2. Add progressive reveal states.
3. Add hero entrance and console chart/bar animations.
4. Refine product-card hover and focus states.
5. Add capability connector and CTA ambient motion.
6. Add dark-mode color overrides.
7. Consolidate reduced-motion rules without breaking the announcement or FAQ.

### `assets/meroserver.js`

1. Add `initHomepageReveals()`.
2. Do not add GSAP, AOS, Lottie, or ScrollReveal.
3. Keep all behavior scoped to `.ref-home`.

### Optional original assets

If the illustration becomes too large for the Twig file, put optimized decorative SVGs in:

```text
assets/images/home/
```

Keep any SVG whose individual paths animate inline in the template. External SVG files cannot have their inner paths targeted by page CSS when loaded through `<img>`.

## Recommended rollout

### Phase 1 — foundation

- Add tokens, `IntersectionObserver`, reveal attributes, and reduced-motion handling.
- Verify that no content is hidden when JavaScript is disabled.

### Phase 2 — hero

- Animate the hero sequence.
- Draw the existing console chart.
- Add original deployment-path SVG and two ambient status layers.

### Phase 3 — sections

- Stagger product and capability cards.
- Add feature-card entrance, CTA gradient drift, and FAQ polish.

### Phase 4 — quality pass

- Remove unnecessary animation layers.
- Tune mobile timing and dark-mode contrast.
- Recheck performance and accessibility.

## Acceptance checklist

- [ ] Animation behavior is original and does not reuse NameHero assets or code.
- [ ] Hero content is visible before or without JavaScript.
- [ ] Announcement still shows the latest three news items and pauses on interaction.
- [ ] Dynamic category names and lowest prices remain unchanged.
- [ ] No text reflows when animations start.
- [ ] All hover effects have keyboard-equivalent focus states.
- [ ] The page works at 320, 375, 768, 1024, 1440, and 1920 px widths.
- [ ] The account console does not overflow on mobile.
- [ ] Light and dark modes both pass contrast checks.
- [ ] `prefers-reduced-motion: reduce` removes non-essential movement.
- [ ] FAQ state remains clear without motion.
- [ ] No new runtime dependency is added.
- [ ] Lighthouse shows no animation-related CLS regression.
- [ ] Mobile scrolling remains smooth on a mid-range device.

## Definition of done

The homepage should feel more alive and illustrated without becoming busy: a staged hero, one expressive console animation, original cloud/deployment SVG details, quiet section reveals, precise card interactions, and complete reduced-motion support. The motion should reinforce the MeroVPS PaaS identity rather than imitate NameHero's brand artwork.
