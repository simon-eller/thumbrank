(function () {
    'use strict';

    const STORAGE_KEY = 'drawer-open-left';

    const drawer   = document.getElementById('drawer-nav');
    const backdrop = document.getElementById('drawer-backdrop');
    const main     = document.getElementById('main-content');
    const openBtn  = document.getElementById('drawer-open');
    const wide     = window.matchMedia('(min-width: 768px)');

    if (!drawer) return;

    const togglers = document.querySelectorAll('[data-drawer-toggle]');

    function isOpen() { return drawer.classList.contains('show'); }

    function setDrawer(open, options) {
        options = options || {};

        const overlay        = open && !wide.matches;
        const focusWasInside = drawer.contains(document.activeElement);

        drawer.classList.toggle('show', open);
        document.body.classList.toggle('drawer-open-left', open);

        togglers.forEach(btn => btn.setAttribute('aria-expanded', open ? 'true' : 'false'));

        if (backdrop) backdrop.hidden = !overlay;
        if (main)     main.inert      = overlay;

        if (options.manageFocus !== false) {
            if (overlay) {
                const dismiss = drawer.querySelector('[data-drawer-dismiss]');
                if (dismiss) dismiss.focus();
            } else if (!open && focusWasInside && openBtn) {
                openBtn.focus();
            }
        }

        if (options.remember !== false) {
            try { localStorage.setItem(STORAGE_KEY, open ? '1' : '0'); } catch (e) { /* private mode */ }
        }
    }

    togglers.forEach(btn => btn.addEventListener('click', () => setDrawer(!isOpen())));

    if (backdrop) backdrop.addEventListener('click', () => setDrawer(false));

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && isOpen() && !wide.matches) setDrawer(false);
    });

    wide.addEventListener('change', () => setDrawer(isOpen(), { remember: false, manageFocus: false }));

    let stored = null;
    try { stored = localStorage.getItem(STORAGE_KEY); } catch (e) { stored = null; }

    setDrawer(stored === null ? wide.matches : stored === '1', { remember: false, manageFocus: false });
    document.body.classList.add('drawers-ready');

    /** Close the overlay drawer after navigating on a narrow screen. */
    window.drawer = {
        closeIfOverlay() { if (!wide.matches && isOpen()) setDrawer(false); },
    };
})();
