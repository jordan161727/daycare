/*
 * The Check in page's QA fixes of 12 Nov 2026, clicked in a headless DOM.
 * Reports what it saw as JSON on stdout.
 *
 * Driven by tests/Feature/CheckInQaNovemberTest.php, the same way
 * click-check-in.cjs is driven by CheckInDialogOpensTest: the page is
 * rendered by PHP, the comments component is read from its module, Alpine's
 * browser build is appended, and postJson is stubbed so nothing leaves.
 *
 *   node tests/js/check-in-qa.cjs <rendered.html> <alpine cdn.js> <scenario>
 *
 * Scenarios:
 *   missing  — a day gone with an arrival and no clock-out (QA #7): the
 *              card's badges, the dialog's flag, and "Fix sign-out time"
 *              opening the correcting form and saving the Out time.
 *   comment  — today, a child in, a comment typed in the dialog's box and
 *              not saved, then Clock out (QA #36): what was posted.
 *   room     — a room chip pressed, then where the day stepper goes (QA #16).
 *   weight   — how many elements the page is once Alpine has run.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const [, , pagePath, alpinePath, scenario = 'missing'] = process.argv;

let html = fs.readFileSync(pagePath, 'utf8');
const alpine = fs.readFileSync(alpinePath, 'utf8');

const stub = `
window.__clockMinutes = 15 * 60;
window.__posted = [];
window.postJson = async (url, body) => {
    window.__posted.push({ url, body });
    if (url.includes('/retime')) {
        return { ok: true, json: async () => ({ success: true, attendance_id: 1, time: '8:00a', out_time: body.signed_out_time ? '4:30p' : null }) };
    }
    if (url.includes('/health')) {
        return { ok: true, json: async () => ({ success: true, direction: body.direction, code: body.code, note: body.note ?? null }) };
    }
    const comments = body.comment_body ? [{ id: 99, tag: 'other', body: body.comment_body, author: 'Admin', time: '3:00 PM', at: '2026-11-12T15:00:00-05:00', date: '2026-11-12', can_edit: true }] : [];
    return { ok: true, json: async () => ({ success: true, attendance_id: 1, session: 'FULL', in_at: '8:00a', out_at: url.endsWith('/out') ? '3:00p' : null, health_in: 0, health_in_note: null, health_out: url.endsWith('/out') ? body.health_code : null, health_out_note: null, trips: [], comments, comments_count: comments.length }) };
};`;

const comments = fs.readFileSync(path.join(__dirname, '../../resources/js/attendance-comments.js'), 'utf8')
    .replace('export default function attendanceComments', 'function attendanceComments');
const register = "document.addEventListener('alpine:init', () => Alpine.data('attendanceComments', attendanceComments));";

html = html
    .replace(/<link[^>]+(preload|modulepreload|stylesheet)[^>]*>/g, '')
    .replace(/<script type="module"[^>]*><\/script>/g, '')
    .replace('</body>', '<script>' + stub + '</script><script>' + comments + '\n' + register + '</script><script>' + alpine + '</script></body>');

// $nextTick: jsdom's Alpine trips on it (see type-register-time.cjs); the
// focus it schedules is checked in a real browser instead.
const noise = /appShell|mobileOpen|collapsed|dark is not defined|Not implemented: navigation|\$nextTick is not a function/;
const errors = [];
const vc = new VirtualConsole();
vc.on('jsdomError', e => { const m = e.detail?.message || e.message || String(e); if (! noise.test(m)) errors.push(m.split('\n')[0]); });
vc.on('warn', (...a) => { const m = String(a[0]); if (! noise.test(m)) errors.push(m.split('\n')[0]); });

const url = process.env.PAGE_URL || 'http://localhost/check-in';
const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc, url });
const { window } = dom;
const tick = () => new Promise(resolve => setTimeout(resolve, 50));
const visible = el => !! el && el.style.display !== 'none' && ! el.hasAttribute('x-cloak');
const text = el => el?.textContent.trim().replace(/\s+/g, ' ') ?? null;

(async () => {
    await tick(); await tick();

    const doc = window.document;
    const result = { alpine: !! window.Alpine, errors };
    const card = () => doc.querySelector('button[aria-label*="Open attendance"]');
    const dialog = () => doc.querySelector('[role="dialog"][aria-modal="true"]');

    if (scenario === 'missing') {
        const c = card();
        result.cardLabel = c?.getAttribute('aria-label');
        result.checkedInBadge = visible([...c.querySelectorAll('span')].find(s => s.textContent.trim() === 'Checked in'));
        result.missingBadge = visible(c.querySelector('[data-missing-sign-out]'));
        result.cardState = text(c.querySelector('span.mt-2'));

        c.click();
        await tick(); await tick();

        const d = dialog();
        result.note = text(d?.querySelector('[data-missing-sign-out-note]'));
        result.status = text(d?.querySelector('[data-status-chip]'));
        const fix = d?.querySelector('[data-fix-sign-out]');
        result.fixButton = !! fix;

        if (fix) {
            fix.click();
            await tick(); await tick();
            const out = dialog().querySelector('#ci-out');
            result.outField = !! out;
            result.outFocused = doc.activeElement === out;
            result.inValue = dialog().querySelector('#ci-in')?.value ?? null;
            result.noteAfterFix = !! dialog().querySelector('[data-missing-sign-out-note]');
            if (out) {
                out.value = '16:30';
                out.dispatchEvent(new window.Event('input', { bubbles: true }));
                await tick();
                dialog().querySelector('form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
                await tick(); await tick(); await tick();
            }
            result.posted = window.__posted;
            result.missingBadgeAfter = visible(card().querySelector('[data-missing-sign-out]'));
            result.cardStateAfter = text(card().querySelector('span.mt-2'));
        }
    }

    if (scenario === 'comment') {
        card().click();
        await tick(); await tick();
        const d = dialog();
        d.querySelector('[data-add-comment]')?.click();
        await tick();
        const box = d.querySelector('[data-comment-composer] textarea');
        result.composer = !! box;
        if (box) {
            box.value = 'Going home with grandma';
            box.dispatchEvent(new window.Event('input', { bubbles: true }));
            await tick();
        }
        result.button = text(d.querySelector('button[type="submit"]'));
        d.querySelector('form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await tick(); await tick(); await tick();
        result.posted = window.__posted;
        result.latest = [...doc.querySelectorAll('[data-latest-comment]')].map(text);
    }

    if (scenario === 'room') {
        const chip = [...doc.querySelectorAll('[aria-label="Filter children by room"] button')].find(b => b.textContent.includes('Infant'));
        chip?.click();
        await tick();
        result.address = window.location.pathname + window.location.search;
        result.prev = doc.querySelector('[data-day-prev]')?.getAttribute('href');
        result.next = doc.querySelector('[data-day-next]')?.getAttribute('href') ?? null;
        result.today = doc.querySelector('[data-back-to-today]')?.getAttribute('href') ?? null;
        result.heading = text(doc.querySelector('section.glass-card h2'));
        const all = [...doc.querySelectorAll('[aria-label="Filter children by room"] button')].find(b => b.textContent.trim().startsWith('All'));
        all?.click();
        await tick();
        result.prevAll = doc.querySelector('[data-day-prev]')?.getAttribute('href');
        result.headingAll = text(doc.querySelector('section.glass-card h2'));
    }

    if (scenario === 'weight') {
        result.elements = doc.querySelectorAll('*').length;
        result.sheetCells = doc.querySelectorAll('.att-grid-table td').length;
        result.cards = doc.querySelectorAll('button[aria-label*="Open attendance"]').length;
    }

    process.stdout.write(JSON.stringify(result));
    window.close();
})();
