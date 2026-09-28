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

// A third argument names the scenario: "today" (the default) clocks the
// first card in; "edit" opens a day already gone, types a leaving time into
// the dialog and presses Save, which is the direct-edit path.
const [, , pagePath, alpinePath, scenario = 'today'] = process.argv;

let html = fs.readFileSync(pagePath, 'utf8');
const alpine = fs.readFileSync(alpinePath, 'utf8');

// The network is stubbed: every postJson() call is recorded and answered as
// the server would answer a clock-in, so the whole path from the button to
// the card's new state runs without a server — and a broken handler throws
// here instead of in a room at eight in the morning.
const stub = `
window.__posted = [];
window.postJson = async (url, body) => {
    window.__posted.push({ url, body });
    // The register's retime answers with both times; the door answers with
    // the row. Enough of each for the dialog to update the card.
    if (url.includes('/retime')) {
        return { ok: true, json: async () => ({ success: true, attendance_id: 1, time: '8:05a', out_time: body.signed_out_time ? '4:30p' : null }) };
    }
    if (url.includes('/health')) {
        return { ok: true, json: async () => ({ success: true, direction: body.direction, code: body.code, note: body.note ?? null }) };
    }
    return { ok: true, json: async () => ({ success: true, attendance_id: 1, session: 'FULL', in_at: '8:42a', out_at: null, health_in: body.health_code ?? 0, health_in_note: null, health_out: null, health_out_note: null }) };
};`;

html = html
    .replace(/<link[^>]+(preload|modulepreload|stylesheet)[^>]*>/g, '')
    .replace(/<script type="module"[^>]*><\/script>/g, '')
    .replace('</body>', '<script>' + stub + '</script><script>' + alpine + '</script></body>');

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
            // The session tabs a School Age child gets, and which one opened.
            result.tabs = [...dialog.querySelectorAll('[data-session]')].map(tab => ({ text: tab.textContent.trim().replace(/\s+/g, ' '), selected: tab.getAttribute('aria-pressed') === 'true' }));
            result.status = dialog.querySelector('span.mt-3 span, span.mt-3')?.textContent.trim() ?? null;

            const form = dialog.querySelector('form');

            if (scenario === 'edit') {
                // A day already gone: type a leaving time and save. The Out
                // field is x-model'd, so an input event is what moves it.
                const out = dialog.querySelector('#ci-out');
                result.outFieldPresent = !! out;
                result.inFieldValue = dialog.querySelector('#ci-in')?.value ?? null;
                if (out) {
                    out.value = '16:30';
                    out.dispatchEvent(new window.Event('input', { bubbles: true }));
                    await tick();
                }
                result.saveButton = dialog.querySelector('button[type="submit"]')?.textContent.trim() ?? null;
                result.saveEnabled = ! (dialog.querySelector('button[type="submit"]')?.disabled ?? true);
            }

            // Press the form's button: Clock in today, Save on a day gone.
            if (form) {
                form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
                await tick(); await tick(); await tick();
            }

            result.posted = window.__posted;
            result.dialogAfter = !! doc.querySelector('[role="dialog"][aria-modal="true"]');
            result.toast = doc.querySelector('[role="status"]')?.textContent.trim() ?? '';
            result.cardState = doc.querySelector('button[aria-label*="Open attendance"] span.mt-2')?.textContent.trim() ?? null;
        }
    }

    process.stdout.write(JSON.stringify(result));
    window.close();
})();
