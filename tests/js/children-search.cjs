/*
 * The Children list's search box, in a headless DOM — as JSON on stdout.
 *
 * Driven by tests/Feature/ChildrenSearchFollowsTest.php. The page arrives as
 * a file rendered by PHP, opened at the address it was rendered for; Alpine's
 * browser build is appended as the last classic script. Typed into the box:
 * which rows show, what "Showing X of Y" and the room and pay chips say, the
 * address (?search=, replaceState), and whether the links back to the list
 * carry the search.
 *
 *   node tests/js/children-search.cjs <rendered.html> <alpine cdn.js> <url>
 */
const fs = require('fs');
const { JSDOM, VirtualConsole } = require('jsdom');

const [, , pagePath, alpinePath, url] = process.argv;

let html = fs.readFileSync(pagePath, 'utf8');
const alpine = fs.readFileSync(alpinePath, 'utf8');

// The bundle (app.js) is not loaded here, so the layout's own components are
// stood in for: the body's appShell, and matchMedia, which jsdom lacks.
const stub = `
if (! window.matchMedia) window.matchMedia = q => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
document.addEventListener('alpine:init', () => {
    Alpine.data('appShell', () => ({ mobileOpen: false, collapsed: false, dark: false }));
});`;

html = html
    .replace(/<link[^>]+(preload|modulepreload|stylesheet)[^>]*>/g, '')
    .replace(/<script type="module"[^>]*><\/script>/g, '')
    // A function, not a string: in a replacement string "$$" means "$", and
    // Alpine's source defines its magics as `$${name}` — they came out as
    // "watch" rather than "$watch".
    .replace('</body>', () => '<script>' + stub + '</script><script>' + alpine + '</script></body>');

const noise = /appShell|mobileOpen|collapsed|dark is not defined|kidsBackground|headerBar/;
const errors = [];
const vc = new VirtualConsole();
vc.on('jsdomError', e => { const m = e.detail?.message || e.message || String(e); if (! noise.test(m)) errors.push(m.split('\n')[0]); });

const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc, url });
const { window } = dom;
const tick = (ms = 60) => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    for (let i = 0; i < 6; i++) await tick();
    const doc = window.document;
    const box = doc.querySelector('[aria-label="Search children"]');
    const shownRows = () => [...doc.querySelectorAll('[data-child-row]')].filter(r => r.style.display !== 'none').map(r => r.dataset.childRow);
    const chip = (selector) => doc.querySelector(selector)?.lastElementChild?.textContent.trim();
    const state = () => ({
        rows: shownRows(),
        showing: doc.querySelector('[data-showing]')?.textContent.trim(),
        toddler: chip('[data-room-chip="Toddler"]'),
        infant: chip('[data-room-chip="Infant"]'),
        allRooms: chip('[data-room-chip-all]'),
        dss: chip('[data-pay-chip="dss"]'),
        empty: doc.querySelector('[data-search-empty]')?.style.display !== 'none',
        address: window.location.search,
        tabLink: doc.querySelector('[data-count="Pending"]')?.getAttribute('href'),
    });
    const type = async (text) => {
        box.value = text;
        box.dispatchEvent(new window.Event('input', { bubbles: true }));
        await tick(450);
    };

    const result = { errors, onLoad: state() };
    await type('stone');
    result.stone = state();
    await type('zzz');
    result.nobody = state();
    await type('');
    result.cleared = state();

    process.stdout.write(JSON.stringify(result));
    window.close();
})().catch(error => { process.stderr.write(String(error.stack || error)); process.exit(1); });
