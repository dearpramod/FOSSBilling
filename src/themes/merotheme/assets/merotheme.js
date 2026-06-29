import Alpine from 'alpinejs';
import Collapse from '@alpinejs/collapse';
import TomSelect from 'tom-select';

// ── Alpine.js setup ────────────────────────────────────────────────────────────
Alpine.plugin(Collapse);

// Expose Alpine globally so x-data inline expressions and devtools work.
window.Alpine = Alpine;

Alpine.start();

// ── TomSelect — enhance <select> inputs ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
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
