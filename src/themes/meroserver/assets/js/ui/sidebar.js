export default () => ({
  desktop: window.matchMedia('(min-width: 1024px)').matches,
  trigger: null,
  media: null,
  onViewportChange: null,
  init() {
    this.media = window.matchMedia('(min-width: 1024px)');
    this.onViewportChange = (event) => {
      this.desktop = event.matches;
      if (this.desktop) this.sidebarOpen = false;
    };
    this.media.addEventListener('change', this.onViewportChange);
    this.$watch('sidebarOpen', (open) => {
      if (open && !this.desktop) {
        this.trigger = document.activeElement;
        this.$nextTick(() => this.$refs.sidebarClose.focus());
      } else if (!this.desktop && this.trigger?.isConnected) {
        this.trigger.focus();
      }
    });
  },
  trapFocus(event) {
    if (this.desktop || !this.sidebarOpen || event.key !== 'Tab') return;
    const controls = [...this.$el.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')]
      .filter((el) => el.tabIndex >= 0 && !el.disabled && !el.closest('[inert]') && el.getClientRects().length);
    const first = controls[0];
    const last = controls.at(-1);
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  },
  destroy() {
    this.media.removeEventListener('change', this.onViewportChange);
  },
});
