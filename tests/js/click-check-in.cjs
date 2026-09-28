/*
 * Open the Check In roster in a headless DOM, click the first card, and
 * report what happened — as JSON on stdout.
 *
 * Driven by tests/Feature/CheckInDialogOpensTest.php. The page arrives as a
 * file rendered by PHP; the built bundle is not available here, so Alpine's
 * browser build is appended as the last classic script, which starts it on
 * DOMContentLoaded exactly as a browser would, after the inline component has
 * been defined.
 *
 * Why this exists: the dialog once failed to open on a click while every PHP
 * assertion stayed green and the script parsed — the loop variable and the
 * component property shared a name, and Alpine wrote the click's result into
 * the wrong scope. Nothing short of a click can see that.
 *
 *   node tests/js/click-check-in.cjs <rendered.html> <alpine cdn.js>
 */
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');

const [, , pagePath, alpinePath] = process.argv;

let html = fs.readFileSync(pagePath, 'utf8');
const alpine = fs.readFileSync(alpinePath, 'utf8');

html = html
    .replace(/<link[^>]+(preload|modulepreload|stylesheet)[^>]*>/g, '')
    .replace(/<script type="module"[^>]*><\/script>/g, '')
    .replace('</body>', '<script>' + alpine + '</script></body>');

// Only errors from our own component matter. The layout's shell (appShell,
// collapsed, dark…) lives in the bundle that is not loaded here.
const noise = /appShell|mobileOpen|collapsed|dark is not defined/;
const errors = [];
const vc = new VirtualConsole();
vc.on('jsdomError', e => { const m = e.detail?.message || e.message || String(e); if (! noise.test(m)) errors.push(m.split('\n')[0]); });
vc.on('warn', (...a) => { const m = String(a[0]); if (! noise.test(m)) errors.push(m.split('\n')[0]); });

const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc, url: 'http://localhost/check-in' });
const { window } = dom;
const tick = () => new Promise(resolve => setTimeout(resolve, 50));

(async () => {
    await tick(); await tick();

    const doc = window.document;
    const card = doc.querySelector('button[aria-label*="Open attendance"]');
    const result = { alpine: !! window.Alpine, cards: doc.querySelectorAll('button[aria-label*="Open attendance"]').length, clicked: null, dialog: false, errors };

    if (card) {
        result.clicked = card.getAttribute('aria-label');
        card.click();
        await tick(); await tick();

        const dialog = doc.querySelector('[role="dialog"][aria-modal="true"]');
        result.dialog = !! dialog;

        if (dialog) {
            result.heading = dialog.querySelector('h2')?.textContent.trim() ?? null;
            result.options = dialog.querySelectorAll('select#ci-code option').length;
            result.button = dialog.querySelector('button[type="submit"]')?.textContent.trim() ?? null;
        }
    }

    process.stdout.write(JSON.stringify(result));
    window.close();
})();
