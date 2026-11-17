/*
 * The date picker's "today" in a browser on the other side of the world —
 * as JSON on stdout. Driven by tests/Feature/CentreTodayTest.php.
 *
 * Run with TZ=Asia/Manila. The clock is fixed at 22:00 New York time on
 * Tuesday 17 Nov 2026, which is already 11:00 on Wednesday the 18th in
 * Manila. The same field is drawn twice: once on a page carrying the centre's
 * timezone (as layouts/app does), once on a page without it, so the result
 * shows both what the fix does and that the browser really was in Manila.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const root = path.join(__dirname, '..', '..');
const flatpickr = fs.readFileSync(path.join(root, 'node_modules', 'flatpickr', 'dist', 'flatpickr.js'), 'utf8');
const helper = fs.readFileSync(path.join(root, 'resources', 'js', 'centre-today.js'), 'utf8');
const dates = fs.readFileSync(path.join(root, 'resources', 'js', 'dates.js'), 'utf8')
    .replace(/import flatpickr from 'flatpickr';/, '')
    .replace(/export function/g, 'function')
    + '\nwindow.__dates = { upgradeDateInput };';

// 22:00 Eastern (UTC-5 in November) on the 17th = 03:00 UTC on the 18th.
const frozen = `(function () {
    const Real = Date, at = Real.parse('2026-11-18T03:00:00Z');
    class Frozen extends Real { constructor(...a) { a.length ? super(...a) : super(at); } static now() { return at; } }
    window.Date = Frozen;
})();`;

const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

async function draw(withCentre) {
    const errors = [];
    const vc = new VirtualConsole();
    vc.on('jsdomError', e => errors.push(String(e.detail?.message || e.message || e).split('\n')[0]));

    const head = withCentre
        ? `<meta name="centre-timezone" content="America/New_York"><meta name="centre-today" content="2026-11-17"><script>${helper}</script>`
        : '';

    const dom = new JSDOM(`<!doctype html><html><head><script>${frozen}</script>${head}</head><body>
        <input type="date" name="closed_on" id="closed" data-date-weekdays>
        <script>${flatpickr}</script><script>${dates}</script>
    </body></html>`, { runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc, url: 'http://localhost/' });

    await new Promise(r => setTimeout(r, 30));
    const { window } = dom;
    const picker = window.__dates.upgradeDateInput(window.document.getElementById('closed'));
    const today = picker.days.querySelector('.flatpickr-day.today');

    return {
        errors,
        browserDate: iso(new window.Date()),
        centreToday: typeof window.centreToday === 'function' ? window.centreToday() : null,
        pickerNow: iso(picker.now),
        highlighted: today ? iso(today.dateObj) : null,
    };
}

(async () => {
    const result = { tz: process.env.TZ, centre: await draw(true), browserOnly: await draw(false) };
    process.stdout.write(JSON.stringify(result));
})().catch(e => { process.stderr.write(String(e.stack || e)); process.exit(1); });
