import Alpine from 'alpinejs';
import Collapse from '@alpinejs/collapse';
import TomSelect from 'tom-select';
import sidebar from './js/ui/sidebar';

// ── Alpine.js setup ────────────────────────────────────────────────────────────
Alpine.plugin(Collapse);
Alpine.data('mobileSidebar', sidebar);

// Expose Alpine globally so x-data inline expressions and devtools work.
window.Alpine = Alpine;

Alpine.start();

// ── Shared async-action motion state ─────────────────────────────────────────
// Opt-in with data-submit-motion. Markup supplies idle/loading/success labels,
// while this helper owns disabled and aria-busy state without replacing content.
window.MeroMotion = Object.assign(window.MeroMotion || {}, {
  setSubmitState(button, state) {
    if (!button) return;
    const nextState = ['idle', 'loading', 'success'].includes(state) ? state : 'idle';
    button.dataset.state = nextState;
    button.disabled = nextState !== 'idle';
    button.setAttribute('aria-busy', nextState === 'loading' ? 'true' : 'false');
  },
});

// ── TomSelect — enhance <select> inputs ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  // Homepage viewport reveals are progressive enhancement: content remains
  // visible when JavaScript is unavailable or reduced motion is requested.
  function initHomepageReveals() {
    const elements = Array.from(document.querySelectorAll('.ref-home [data-reveal]'));
    if (!elements.length) return;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion || !('IntersectionObserver' in window)) {
      elements.forEach(function (element) {
        element.classList.add('is-revealed');
      });
      return;
    }

    document.documentElement.classList.add('has-home-reveal');

    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-revealed');
        observer.unobserve(entry.target);
      });
    }, {
      rootMargin: '0px 0px -10% 0px',
      threshold: 0.12,
    });

    elements.forEach(function (element) {
      observer.observe(element);
    });
  }

  initHomepageReveals();

  /**
   * Global error handler for unhandled Promise rejections.
   * Delegates to FOSSBilling.message() provided by fossbilling.js.
   */
  window.addEventListener('unhandledrejection', function (event) {
    const err = event.reason;
    let msg = 'An unexpected error occurred';
    if (err && typeof err === 'object') {
      msg = err.message || err.code || msg;
    } else if (typeof err === 'string') {
      msg = err;
    }
    if (typeof FOSSBilling !== 'undefined' && FOSSBilling.message) {
      FOSSBilling.message(msg, 'error');
    }
  });

  // Locale / currency selectors
  document.querySelectorAll('select.js-locale-selector').forEach(function (el) {
    new TomSelect(el, {
      maxOptions: null,
      render: {
        option: function (data, escape) {
          var flag = data.value ? '<span class="fi fi-' + escape(data.value.toLowerCase().slice(3)) + ' locale-flag"></span>' : '';
          return '<div class="option">' + flag + escape(data.text) + '</div>';
        },
        item: function (data, escape) {
          var flag = data.value ? '<span class="fi fi-' + escape(data.value.toLowerCase().slice(3)) + ' locale-flag"></span>' : '';
          return '<div>' + flag + escape(data.text) + '</div>';
        },
      },
    });
  });

  // Generic enhanced selects (used by order forms, etc.)
  document.querySelectorAll('select.js-select').forEach(function (el) {
    if (!el.tomselect) {
      new TomSelect(el, { maxOptions: null });
    }
  });
});
