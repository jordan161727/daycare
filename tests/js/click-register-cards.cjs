/*
 * Open the register in Card view in a headless DOM and tap one child's card
 * six times, reporting every request it makes — as JSON on stdout.
 *
 * Driven by tests/Feature/RegisterCardsClickTest.php. The page arrives as a
 * file rendered by PHP; the built bundle is not available here, so Alpine's
 * browser build is appended as the last classic script, which starts it on
 * DOMContentLoaded exactly as a browser would.
 *
 * The network is stubbed: a sign-in answers as the register's sign-in does,
 * a retime as retime does, a return as return does, so the card can move on
 * to its next step. What comes out is the sequence of requests — which is
 * the whole of what a card is for: in, out, next session's in, its out, and
 * then back in and out again.
 *
 *   node tests/js/click-register-cards.cjs <rendered.html> <alpine cdn.js>
 */
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');

// A third argument names the scenario: "cards" (the default) taps one card
// six times; "sheet" opens Table view and taps today's AM box three times
// (in, out, back) and the PM box twice (in, out) — the same steps, on the
// boxes the director's view draws.
const [, , pagePath, alpinePath, scenario = 'cards'] = process.argv;

let html = fs.readFileSync(pagePath, 'utf8');
const alpine = fs.readFileSync(alpinePath, 'utf8');

const stub = `
// jsdom has no matchMedia; the register asks it whether this is a phone.
if (! window.matchMedia) {
    window.matchMedia = query => ({ matches: false, media: query, onchange: null, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, dispatchEvent() { return false; } });
}
if (! window.requestIdleCallback) {
    window.requestIdleCallback = fn => setTimeout(() => fn({ didTimeout: false, timeRemaining: () => 50 }), 0);
}
try { localStorage.setItem('attendance.view', '${scenario === 'sheet' ? 'sheet' : 'avatar'}'); } catch {}
window.__posted = [];
// The afternoon is clocked out twice — once before the trip out, once after
// — so its departures come off a list.
const pmOuts = ['3:00p', '4:15p'];
window.postJson = async (url, body) => {
    window.__posted.push({ url, body });
    if (url.includes('/retime')) {
        const out = body.session === 'PM' ? pmOuts.shift() : '11:30a';
        return { ok: true, json: async () => ({ success: true, attendance_id: 1, time: '8:05a', out_time: body.signed_out_time ? out : null }) };
    }
    if (url.includes('/return')) {
        return { ok: true, json: async () => ({ success: true, attendance_id: 1, time: '12:30p', out_time: null, returns: [['3:00p', '3:20p']] }) };
    }
    // The register's sign-in: the time it stamped, for the session asked.
    return { ok: true, json: async () => ({ success: true, created: true, attendance_id: 1, time: body.session === 'PM' ? '12:30p' : '8:05a', session: body.session, amendment: null, child: { name: 'x', classroom: 'School Age' }, health_in_code: null, health_in_note: null, sick: false }) };
};`;

html = html
    .replace(/<link[^>]+(preload|modulepreload|stylesheet)[^>]*>/g, '')
    .replace(/<script type="module"[^>]*><\/script>/g, '')
    .replace('</body>', '<script>' + stub + '</script><script>' + alpine + '</script></body>');

const noise = /appShell|mobileOpen|collapsed|dark is not defined|weekPicker/;
const errors = [];
const vc = new VirtualConsole();
vc.on('jsdomError', e => { const m = e.detail?.message || e.message || String(e); if (! noise.test(m)) errors.push(m.split('\n')[0]); });
vc.on('warn', (...a) => { const m = String(a[0]); if (! noise.test(m)) errors.push(m.split('\n')[0]); });

const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc, url: 'http://localhost/attendance' });
const { window } = dom;
const tick = () => new Promise(resolve => setTimeout(resolve, 60));

(async () => {
    // The register builds its rows after first paint; give it a few ticks.
    for (let i = 0; i < 8; i++) await tick();

    const doc = window.document;

    if (scenario === 'sheet') {
        // The register's own today — the test travels in time, the clock here
        // does not — read off the component rather than guessed from the page.
        const root = doc.querySelector('[x-data^="attendanceApp"]');
        const today = (root && window.Alpine?.$data(root)?.today) || new Date().toISOString().slice(0, 10);
        const box = session => doc.querySelector('[data-cell$="|' + today + '|' + session + '"]');
        const result = { alpine: !! window.Alpine, boxesFound: !! (box('AM') && box('PM')), steps: [], errors };

        for (const session of ['AM', 'AM', 'AM', 'PM', 'PM']) {
            const target = box(session);
            if (! target) break;
            const before = target.getAttribute('title');
            target.click();
            await tick(); await tick();
            result.steps.push({ session, tapped: before, text: box(session)?.textContent.trim().replace(/\s+/g, ' ') ?? null });
        }

        result.posted = window.__posted;
        process.stdout.write(JSON.stringify(result));
        window.close();
        return;
    }

    const card = () => doc.querySelector('template[x-if="mode === \'avatar\'"] ~ div button, [x-html="child.avatar"]')?.closest('button');
    const result = { alpine: !! window.Alpine, cardFound: !! card(), steps: [], errors };

    // A tap opens the child's pop-up; its one button does the clocking. So
    // each step is a tap, then the press — and a note of what the pop-up
    // offered, which has no health code in it.
    // "forgot": the AM was never clocked out and it is ten past four. Two
    // taps: out of the AM, then into the PM.
    const taps = scenario === 'forgot' ? 2 : 6;

    for (let i = 0; i < taps && card(); i++) {
        // The morning's two taps at nine, the afternoon's four at one: the
        // pop-up only offers the half of the day the clock is in.
        window.__clockMinutes = scenario === 'forgot' ? 16 * 60 + 13 : (i < 2 ? 9 * 60 : 13 * 60);
        const before = card().getAttribute('title');
        const wasDisabled = card().disabled;
        card().click();
        await tick(); await tick();
        const dialog = doc.querySelector('[data-card-dialog]');
        const button = dialog?.querySelector('button.w-full') ?? null;
        const offered = button?.textContent.trim() ?? null;
        const hasSelect = !! dialog?.querySelector('select');
        const sessions = [...(dialog?.querySelectorAll('[data-session]') ?? [])].map(b => b.textContent.trim().split(/\s+/)[0]);
        const chosen = dialog?.querySelector('[data-session][aria-pressed="true"]')?.textContent.trim().split(/\s+/)[0] ?? null;
        const off = [...(dialog?.querySelectorAll('[data-session]:disabled') ?? [])].map(b => b.textContent.trim().split(/\s+/)[0]);
        button?.click();
        await tick(); await tick();
        result.steps.push({ tapped: before, wasDisabled, offered, hasSelect, sessions, chosen, off, dialogClosed: ! doc.querySelector('[data-card-dialog]'), state: card().querySelector('span.mt-2')?.textContent.trim() ?? null });
    }

    // The Recent panel: there at all, and opened by its button. It once
    // shared a teleport with the card pop-up and was never drawn.
    const recentPanel = () => doc.querySelector('[role="dialog"][aria-label="Recent sign-ins"]');
    result.recentPanelDrawn = !! recentPanel();
    [...doc.querySelectorAll('button')].find(b => /Recent/.test(b.textContent))?.click();
    await tick(); await tick();
    result.recentOpened = !! recentPanel() && recentPanel().style.display !== 'none';
    result.recentCount = recentPanel()?.querySelectorAll('.truncate.font-semibold').length ?? 0;

    result.posted = window.__posted;
    result.finalTitle = card()?.getAttribute('title') ?? null;
    result.finalDisabled = card()?.disabled ?? null;

    process.stdout.write(JSON.stringify(result));
    window.close();
})();
