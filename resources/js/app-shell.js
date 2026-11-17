/*
 * The layout's shell: the dark-mode switch, the desktop rail's collapse, and
 * the phone-width drawer. Its own module so tests/js/sidebar-drawer.cjs can
 * drive the real thing rather than a stand-in.
 */
export default () => ({
    mobileOpen: false,
    collapsed: false,
    dark: localStorage.getItem('daycare-dark') === 'true',
    init() {
        this.$watch('dark', value => {
            document.documentElement.classList.toggle('dark', value);
            localStorage.setItem('daycare-dark', value);
        });
        document.documentElement.classList.toggle('dark', this.dark);
    },

    /*
     * The phone-width drawer behaves as a dialog (QA, 17 Nov 2026): it opens
     * with focus inside it, Tab cycles round it rather than wandering into
     * the page under the dimmer, and Escape closes it and puts focus back on
     * the menu button that opened it.
     */
    openMenu() {
        this.mobileOpen = true;
        this.$nextTick(() => this.menuFocusables()[0]?.focus());
    },
    closeMenu() {
        if (!this.mobileOpen) return;
        this.mobileOpen = false;
        this.$nextTick(() => this.$refs.menuButton?.focus());
    },
    menuFocusables() {
        const drawer = this.$refs.drawer;
        if (!drawer) return [];
        return [...drawer.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
            // checkVisibility drops what CSS hides at this width (the rail's
            // collapse toggle); the closest() is for browsers without it.
            .filter(el => !el.closest('[hidden], [style*="display: none"]') && (typeof el.checkVisibility !== 'function' || el.checkVisibility()));
    },
    trapMenuFocus(event) {
        if (!this.mobileOpen) return;
        const items = this.menuFocusables();
        if (!items.length) return;
        const first = items[0];
        const last = items[items.length - 1];
        const active = document.activeElement;
        if (!this.$refs.drawer.contains(active)) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
        } else if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    },
});
