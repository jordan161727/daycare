/*
 * Add child's live duplicate check, in a headless DOM — as JSON on stdout.
 *
 * Driven by tests/Feature/ChildDuplicateCheckTest.php. The rendered Add
 * child page, Alpine's browser build appended, fetch stubbed to answer as
 * the duplicate-check endpoint does. Types Jace / Miller / 01/07/2020 (the
 * date into a stand-in for flatpickr's box, which is where it is typed on
 * the real page), waits out the debounce, and reads back the warning, then
 * tries to save without and with "Continue anyway".
 *
 *   node tests/js/duplicate-check.cjs <rendered.html> <alpine cdn.js> <url>
 */
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');

const [, , pagePath, alpinePath, url] = process.argv;

let html = fs.readFileSync(pagePath, 'utf8');
const alpine = fs.readFileSync(alpinePath, 'utf8');

const stub = `
if (! window.matchMedia) window.matchMedia = q => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
window.__fetches = [];
window.fetch = async (address) => {
    window.__fetches.push(String(address));
    return { ok: true, json: async () => ({
        count: 5,
        matches: [
            { id: 1, lan: '10102', name: 'Jace Miller', status: 'Active', room: 'UPK-4', born: '01/07/2020', url: '/children/1' },
            { id: 2, lan: '10103', name: 'Jace Miller', status: 'Pending', room: null, born: '01/07/2020', url: '/children/2' },
        ],
    }) };
};
document.addEventListener('alpine:init', () => {
    Alpine.data('appShell', () => ({ mobileOpen: false, collapsed: false, dark: false }));
});`;

html = html
    .replace(/<link[^>]+(preload|modulepreload|stylesheet)[^>]*>/g, '')
    .replace(/<script type="module"[^>]*><\/script>/g, '')
    .replace('</body>', () => '<script>' + stub + '</script><script>' + alpine + '</script></body>');

const errors = [];
const vc = new VirtualConsole();
vc.on('jsdomError', () => {});

const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc, url });
const { window } = dom;
const tick = (ms = 60) => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    for (let i = 0; i < 6; i++) await tick();
    const doc = window.document;
    const form = doc.querySelector('[data-unsaved-guard]');
    const first = form.querySelector('[name="first_name"]');
    const last = form.querySelector('[name="last_name"]');
    const birth = form.querySelector('[name="birth_date"]');
    const warning = form.querySelector('[data-duplicate-live]');
    if (! first || ! last || ! birth) errors.push('a name or birth date box is missing');
    if (! warning) errors.push('no live warning on the page');

    // flatpickr's own text box beside the named input, as dates.js leaves it.
    const box = doc.createElement('input');
    box.type = 'text';
    birth.insertAdjacentElement('afterend', box);
    birth._flatpickr = { altInput: box };

    const shown = () => !! warning && warning.style.display !== 'none';
    const type = async (el, text) => { el.value = text; el.dispatchEvent(new window.Event('input', { bubbles: true })); await tick(10); };

    const result = { errors };
    result.shownAtFirst = shown();

    await type(first, 'Jace');
    await type(last, 'Miller');
    await type(box, '01/07/20');
    await tick(500);
    result.fetchesBeforeWholeDate = window.__fetches.length;
    result.shownBeforeWholeDate = shown();

    await type(box, '01/07/2020');
    await tick(100);
    result.fetchesTooSoon = window.__fetches.length;
    await tick(500);
    result.fetches = window.__fetches;
    result.shown = shown();
    result.text = warning?.querySelector('[data-duplicate-live-text]')?.textContent.trim();
    result.links = [...(warning?.querySelectorAll('[data-duplicate-live-open]') || [])].map(a => a.getAttribute('href'));

    const submit = () => {
        const e = new window.Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(e);
        return e.defaultPrevented;
    };
    result.heldWithoutConfirm = submit();

    const confirm = warning?.querySelector('[data-duplicate-live-confirm]');
    if (! confirm) errors.push('no Continue anyway box');
    confirm.checked = true;
    confirm.dispatchEvent(new window.Event('change', { bubbles: true }));
    await tick(500);
    result.confirmPosts = new window.FormData(form).get('confirm_duplicate');
    result.heldWithConfirm = submit();

    process.stdout.write(JSON.stringify(result));
    window.close();
})().catch(error => { process.stderr.write(String(error.stack || error)); process.exit(1); });
