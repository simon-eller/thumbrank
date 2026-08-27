(function () {
    'use strict';

    // On narrow screens the drawer overlays the page. Close it before a nav entry
    // navigates away, so the next page does not open behind an overlay again.
    document.querySelectorAll('#drawer-nav a[href], #drawer-nav button[type="submit"]')
        .forEach(el => el.addEventListener('click', () => {
            if (window.drawer) window.drawer.closeIfOverlay();
        }));

    // Copy the room link, with a short confirmation on the nav label itself.
    const copyBtn = document.getElementById('copy-room-link');
    if (!copyBtn) return;

    const label = copyBtn.querySelector('[data-copy-label]');
    const original = label.textContent;
    let restore = null;

    copyBtn.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(window.location.href);
        } catch (e) {
            return; // clipboard blocked (no https, no permission)
        }

        label.textContent = copyBtn.dataset.copiedText;
        clearTimeout(restore);
        restore = setTimeout(() => { label.textContent = original; }, 1500);
    });
})();
