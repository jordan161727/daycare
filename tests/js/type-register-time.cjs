/*
 * Open Director Attendance in a headless DOM, press the pencil on one box,
 * type into its two hours and press Enter — once per pair given — reporting
 * what was sent and what the page said, as JSON on stdout.
 *
 * Driven by tests/Feature/DirectorAttendanceTypedTimeTest.php. The page is
 * rendered by PHP and Alpine's browser build appended, as in
 * click-register-cards.cjs; the network is stubbed to answer as retime does.
 *
 *   node tests/js/type-register-time.cjs <rendered.html> <alpine cdn.js> <cell key> <json [[in, out], ...]>
 */
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');

const [, , pagePath, alpinePath, cellKey, pairsJson] = process.argv;
const pairs = JSON.parse(pairsJson);

let html = fs.readFileSync(pagePath, 'utf8');
const alpine = fs.readFileSync(alpinePath, 'utf8');

const stub = `
if (! window.matchMedia) {
    window.matchMedia = query => ({ matches: false, media: query, onchange: null, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, dispatchEvent() { return false; } });
}
if (! window.requestIdleCallback) {
    window.requestIdleCallback = fn => setTimeout(() => fn({ didTimeout: false, timeRemaining: () => 50 }), 0);
}
window.__posted = [];
window.postJson = async (url, body) => {
    window.__posted.push({ url, body });
    return { ok: true, json: async () => ({ success: true, attendance_id: 1, time: '8:10a', out_time: null, amendment: null }) };
};`;

html = html
    .replace(/<link[^>]+(preload|modulepreload|stylesheet)[^>]*>/g, '')
    .replace(/<script type="module"[^>]*><\/script>/g, '')
    .replace('</body>', '<script>' + stub + '</script><script>' + alpine + '</script></body>');

// $nextTick: the focus-on-open x-init inside an x-if, which jsdom's Alpine trips on.
const noise = /appShell|mobileOpen|collapsed|dark is not defined|weekPicker|\$nextTick is not defined/;
const errors = [];
const vc = new VirtualConsole();
vc.on('jsdomError', e => { const m = e.detail?.message || e.message || String(e); if (! noise.test(m)) errors.push(m.split('\n')[0]); });

const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc, url: 'http://localhost/attendance' });
const { window } = dom;
const tick = () => new Promise(resolve => setTimeout(resolve, 60));

(async () => {
    for (let i = 0; i < 8; i++) await tick();

    const doc = window.document;
    const root = doc.querySelector('[x-data^="attendanceApp"]');
    const app = window.Alpine?.$data(root);
    const result = { alpine: !! window.Alpine, steps: [], errors };

    for (const [typedIn, typedOut] of pairs) {
        const pencil = doc.querySelector('[data-cell="' + cellKey + '"] [data-pencil]');
        if (! pencil) { result.steps.push({ pencil: false }); break; }
        pencil.click();
        await tick(); await tick();

        const inputs = [...doc.querySelectorAll('.att-editing input')];
        const type = (input, value) => { input.value = value; input.dispatchEvent(new window.Event('input', { bubbles: true })); };
        type(inputs[0], typedIn);
        if (inputs[1]) type(inputs[1], typedOut);
        await tick();
        inputs[0].dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        await tick(); await tick();

        result.steps.push({ pencil: true, boxes: inputs.length, notice: app.notice, sent: window.__posted.length, stillOpen: !! doc.querySelector('.att-editing') });
        app.notice = '';
        await tick();
    }

    result.posted = window.__posted;
    process.stdout.write(JSON.stringify(result));
    window.close();
})();
