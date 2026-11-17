/*
 * Add child's "Leave site?" guard, in a headless DOM — as JSON on stdout.
 *
 * Driven by tests/Feature/AddChildUnsavedGuardTest.php. The rendered Add
 * child page, Alpine's browser build appended. After each step a
 * beforeunload is dispatched and whether the page held it is read back:
 * opened untouched, events fired with nothing changed, a name typed, the
 * name deleted again, a name typed and Cancel pressed.
 *
 *   node tests/js/unsaved-guard.cjs <rendered.html> <alpine cdn.js> <url>
 */
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');

const [, , pagePath, alpinePath, url] = process.argv;

let html = fs.readFileSync(pagePath, 'utf8');
const alpine = fs.readFileSync(alpinePath, 'utf8');

const stub = `
if (! window.matchMedia) window.matchMedia = q => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
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
    const name = form.querySelector('[name="first_name"]');
    if (! name) errors.push('no first_name box');

    // Whether leaving now would be held: a cancelled beforeunload.
    const held = () => {
        const e = new window.Event('beforeunload', { cancelable: true });
        window.dispatchEvent(e);
        return e.defaultPrevented;
    };
    const fire = async (el, type) => { el.dispatchEvent(new window.Event(type, { bubbles: true })); await tick(); };
    const type = async (text) => { name.value = text; await fire(name, 'input'); };

    const result = { errors };
    result.untouched = held();

    // The pickers and the steps fire input/change without a real change.
    await fire(name, 'change');
    await fire(form, 'input');
    result.eventsOnly = held();

    await type('Ava');
    result.typed = held();

    await type('');
    result.typedAndDeleted = held();

    await type('Ava');
    const cancel = form.querySelector('[data-cancel-form]');
    if (! cancel) errors.push('no Cancel');
    cancel?.addEventListener('click', e => e.preventDefault()); // stay in the DOM to read the guard
    cancel?.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
    await tick();
    result.afterCancel = held();

    process.stdout.write(JSON.stringify(result));
    window.close();
})().catch(error => { process.stderr.write(String(error.stack || error)); process.exit(1); });
