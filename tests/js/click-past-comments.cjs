/*
 * Read earlier comments in a headless DOM, and report what happened as JSON.
 *
 * Driven by tests/Feature/PastCommentsDialogTest.php. Three scenarios:
 *
 *   today  — the door screen on today: press a card, press "Past comments",
 *            and the stubbed GET /children/:id/comments fills the tab; press
 *            "View" beside the comment label, which does the same.
 *   past   — the door screen on a day gone: the read-only bar is up, a card
 *            opens straight on Past comments, "With comments only" narrows
 *            the cards, and a press on the panel's latest comment opens the
 *            dialog lit on that comment.
 *   log    — the comments log: "Open →" on a row opens the dialog on the
 *            child's past comments, scrolled to that comment.
 *
 *   node tests/js/click-past-comments.cjs <rendered.html> <alpine cdn.js> <scenario>
 */
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const [, , pagePath, alpinePath, scenario = 'today'] = process.argv;

let html = fs.readFileSync(pagePath, 'utf8');
const alpine = fs.readFileSync(alpinePath, 'utf8');

// The network is stubbed: GET /children/:id/comments answers with two days
// of notes, and records what was asked for.
const stub = `
window.__clockMinutes = 8 * 60;
window.__fetched = [];
window.__posted = [];
window.postJson = async (url, body) => { window.__posted.push({ url, body }); return { ok: true, json: async () => ({ success: true }) }; };
window.fetch = async (url) => {
    window.__fetched.push(String(url));
    const page = String(url).includes('before=') ? {
        days: [{ date: '2026-09-02', label: 'Wed, Sep 2', in: '8:00a', out: '4:00p', out_code: 0, out_code_label: 'Normal', summary: 'In 8:00a → Out 4:00p · Leaving check 0 · Normal',
            comments: [{ id: 3, body: 'Older note', author: 'Ms. Alvarez', author_role: 'Teacher', time: '8:30 AM', type: 'general', edited: false }] }],
        has_more: false, next_before: null,
    } : {
        days: [
            { date: '2026-10-16', label: 'Fri, Oct 16', in: '8:11a', out: '5:05p', out_code: 0, out_code_label: 'Normal', summary: 'In 8:11a → Out 5:05p · Leaving check 0 · Normal',
              // id 2 is the newest note the PHP test writes — the one the
              // panel and the log open on, so the lit comment is this one.
              comments: [{ id: 2, body: 'Friday note', author: 'Ms. Alvarez', author_role: 'Teacher', time: '8:14 AM', type: 'general', edited: false }] },
            { date: '2026-10-14', label: 'Wed, Oct 14', in: '7:50a', out: '2:05p', out_code: 3, out_code_label: 'Cough', summary: 'In 7:50a → Out 2:05p · Leaving check 3 · Cough',
              comments: [{ id: 9, body: 'Wednesday note', author: 'Ms. Alvarez', author_role: 'Teacher', time: '9:02 AM', type: 'health', edited: true }] },
        ],
        has_more: true, next_before: '2026-10-14',
    };
    return { ok: true, json: async () => page };
};
Element.prototype.scrollIntoView = function () { window.__scrolled = this.getAttribute('data-past-comment') || this.getAttribute('data-past-day'); };
`;

const comments = fs.readFileSync(path.join(__dirname, '../../resources/js/attendance-comments.js'), 'utf8')
    .replace('export default function attendanceComments', 'function attendanceComments');
const register = "document.addEventListener('alpine:init', () => Alpine.data('attendanceComments', attendanceComments));";

html = html
    .replace(/<link[^>]+(preload|modulepreload|stylesheet)[^>]*>/g, '')
    .replace(/<script type="module"[^>]*><\/script>/g, '')
    .replace('</body>', '<script>' + stub + '</script><script>' + comments + '\n' + register + '</script><script>' + alpine + '</script></body>');

const noise = /appShell|mobileOpen|collapsed|dark is not defined/;
const errors = [];
const vc = new VirtualConsole();
vc.on('jsdomError', e => { const m = e.detail?.message || e.message || String(e); if (! noise.test(m)) errors.push(m.split('\n')[0]); });
vc.on('warn', (...a) => { const m = String(a[0]); if (! noise.test(m)) errors.push(m.split('\n')[0]); });

const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc, url: 'http://localhost/check-in' });
const { window } = dom;
const tick = () => new Promise(resolve => setTimeout(resolve, 50));
const visible = el => !! el && el.style.display !== 'none' && ! el.closest('[style*="display: none"]');
const text = el => (el?.textContent ?? '').trim().replace(/\s+/g, ' ');

(async () => {
    await tick(); await tick();

    const doc = window.document;
    const result = { alpine: !! window.Alpine, scenario, errors };
    const dialog = () => doc.querySelector('[role="dialog"][aria-modal="true"]');
    const pastTab = () => dialog()?.querySelector('[data-tab-panel-past]');
    const readPast = () => ({
        fetched: [...window.__fetched],
        visible: visible(pastTab()),
        days: [...(pastTab() ?? dialog())?.querySelectorAll('[data-past-day]') ?? []].map(d => ({ date: d.getAttribute('data-past-day'), head: text(d.querySelector('header')), comments: [...d.querySelectorAll('[data-past-comment]')].map(c => ({ id: c.getAttribute('data-past-comment'), text: text(c), lit: c.className.includes('bg-[#DCEBFA]') })) })),
        loadOlder: visible((pastTab() ?? dialog())?.querySelector('[data-load-older]')),
        scrolled: window.__scrolled ?? null,
    });

    if (scenario === 'today') {
        const card = doc.querySelector('button[aria-label*="Open attendance"]');
        result.cards = doc.querySelectorAll('button[aria-label*="Open attendance"]').length;
        card?.click();
        await tick(); await tick();

        result.dialog = !! dialog();
        result.todayTab = text(dialog()?.querySelector('[data-tab-today]'));
        result.todayPanelVisible = visible(dialog()?.querySelector('[data-tab-panel-today]'));
        result.pastPanelVisible = visible(pastTab());
        result.lastComment = text(dialog()?.querySelector('[data-last-comment]'));
        result.clockButton = text(dialog()?.querySelector('button[type="submit"]'));

        dialog()?.querySelector('[data-tab-past]')?.click();
        await tick(); await tick(); await tick();
        result.afterTab = readPast();
        result.todayPanelAfter = visible(dialog()?.querySelector('[data-tab-panel-today]'));
        result.clockButtonAfter = text(dialog()?.querySelector('button[type="submit"]'));

        // Load older: the next page lands under the first.
        (pastTab() ?? dialog())?.querySelector('[data-load-older]')?.click();
        await tick(); await tick(); await tick();
        result.afterOlder = readPast();

        // Back to today, then "View" beside the comment label.
        dialog()?.querySelector('[data-tab-today]')?.click();
        await tick();
        result.backToToday = visible(dialog()?.querySelector('[data-tab-panel-today]'));
        dialog()?.querySelector('[data-view-last-comment]')?.click();
        await tick(); await tick(); await tick();
        result.afterView = readPast();

        // The Show box: a change fetches again with the range.
        const select = (pastTab() ?? dialog())?.querySelector('[data-past-range]');
        if (select) { select.value = '7d'; select.dispatchEvent(new window.Event('change', { bubbles: true })); }
        await tick(); await tick(); await tick();
        result.afterRange = readPast().fetched;
    }

    if (scenario === 'past') {
        result.readOnlyBar = text(doc.querySelector('[data-read-only-bar]'));
        result.backToToday = !! doc.querySelector('[data-back-to-today]');
        result.glance = text(doc.querySelector('aside h2'));
        result.tiles = [...doc.querySelectorAll('aside dl dt')].map(text);
        result.latest = [...doc.querySelectorAll('[data-latest-comment]')].map(text);
        result.latestCount = text(doc.querySelector('[data-latest-comments] h3 + span'));
        result.seeAll = doc.querySelector('[data-see-all-comments]')?.getAttribute('href') ?? null;
        result.cardsBefore = [...doc.querySelectorAll('button[aria-label*="Open attendance"]')].map(c => text(c.querySelector('span.mt-3')));
        result.cardStates = [...doc.querySelectorAll('button[aria-label*="Open attendance"]')].map(c => text(c.querySelector('span.mt-2')));
        result.badges = [...doc.querySelectorAll('[data-comment-badge]')].filter(visible).map(text);

        doc.querySelector('[data-with-comments]')?.click();
        await tick(); await tick();
        result.cardsWithComments = [...doc.querySelectorAll('button[aria-label*="Open attendance"]')].map(c => text(c.querySelector('span.mt-3')));

        doc.querySelector('button[aria-label*="Open attendance"]')?.click();
        await tick(); await tick(); await tick();
        result.dialog = !! dialog();
        result.todayTab = text(dialog()?.querySelector('[data-tab-today]'));
        result.opened = readPast();
        result.clockButton = dialog()?.querySelector('button[type="submit"]') ? text(dialog().querySelector('button[type="submit"]')) : null;
        result.correctingForm = !! dialog()?.querySelector('#ci-in');

        // Close, then open from the panel's latest comment: lit on that one.
        dialog()?.querySelector('button[aria-label="Close"]')?.click();
        await tick();
        result.closed = ! dialog();
        doc.querySelector('[data-latest-comment]')?.click();
        await tick(); await tick(); await tick();
        result.fromPanel = readPast();
        result.fromPanelHeading = text(dialog()?.querySelector('h2'));
    }

    if (scenario === 'log') {
        result.rows = doc.querySelectorAll('[data-comment-row]').length;
        result.summary = text(doc.querySelector('[data-comments-summary]'));
        result.chips = [...doc.querySelectorAll('[data-type-chip]')].map(c => c.getAttribute('data-type-chip') + ':' + text(c));
        doc.querySelector('[data-open-comment]')?.click();
        await tick(); await tick(); await tick();
        result.dialog = !! dialog();
        result.heading = text(dialog()?.querySelector('h2'));
        result.opened = readPast();
        dialog()?.querySelector('button[aria-label="Close"]')?.click();
        await tick();
        result.closed = ! dialog();
        result.rowsAfter = doc.querySelectorAll('[data-comment-row]').length;
    }

    process.stdout.write(JSON.stringify(result));
    window.close();
})();
