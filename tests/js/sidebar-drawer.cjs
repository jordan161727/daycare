/*
 * The phone-width side menu, in a headless DOM — as JSON on stdout.
 *
 * Driven by tests/Feature/SidebarDrawerKeyboardTest.php. The page arrives as
 * a file rendered by PHP; the layout's real shell (resources/js/app-shell.js)
 * is read and registered the way app.js registers it, and Alpine's browser
 * build is appended as the last classic script. Opens the drawer from the
 * menu button, Tabs off each end of it, then presses Escape.
 *
 *   node tests/js/sidebar-drawer.cjs <rendered.html> <alpine cdn.js>
 */
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const [, , pagePath, alpinePath] = process.argv;

let html = fs.readFileSync(pagePath, 'utf8');
const alpine = fs.readFileSync(alpinePath, 'utf8');
const shell = fs.readFileSync(path.join(__dirname, '../../resources/js/app-shell.js'), 'utf8')
    .replace('export default () =>', 'const appShell = () =>');

const stub = `
if (! window.matchMedia) window.matchMedia = q => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
${shell}
document.addEventListener('alpine:init', () => Alpine.data('appShell', appShell));`;

html = html
    .replace(/<link[^>]+(preload|modulepreload|stylesheet)[^>]*>/g, '')
    .replace(/<script type="module"[^>]*><\/script>/g, '')
    .replace('</body>', () => '<script>' + stub + '</script><script>' + alpine + '</script></body>');

// Other bundle components the layout names are not under test here.
const noise = /kidsBackground|headerBar|Not implemented/;
const errors = [];
const vc = new VirtualConsole();
vc.on('jsdomError', e => { const m = e.detail?.message || e.message || String(e); if (! noise.test(m)) errors.push(m.split('\n')[0]); });

const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc, url: 'http://localhost/' });
const { window } = dom;
const tick = (ms = 60) => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    for (let i = 0; i < 6; i++) await tick();
    const doc = window.document;
    const button = doc.querySelector('[aria-controls="app-sidebar"]');
    const drawer = doc.getElementById('app-sidebar');
    const shellData = () => window.Alpine.$data(doc.body);
    const press = (key, shiftKey = false) => {
        const target = doc.activeElement || doc.body;
        const event = new window.KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true });
        target.dispatchEvent(event);
        return event.defaultPrevented;
    };
    const focusables = () => shellData().menuFocusables();
    const result = { errors };

    result.closedExpanded = button.getAttribute('aria-expanded');

    button.focus();
    button.click();
    // The focus waits for Alpine to finish drawing the open drawer.
    await tick(400);
    result.open = shellData().mobileOpen;
    result.openExpanded = button.getAttribute('aria-expanded');
    result.openRole = drawer.getAttribute('role');
    result.focusInsideOnOpen = drawer.contains(doc.activeElement);
    result.focusables = focusables().length;

    // Tab off the last item lands on the first; Shift+Tab off the first, the last.
    const items = focusables();
    items[items.length - 1].focus();
    result.tabFromLastPrevented = press('Tab');
    result.tabFromLastLandsOnFirst = doc.activeElement === items[0];
    items[0].focus();
    result.shiftTabFromFirstPrevented = press('Tab', true);
    result.shiftTabFromFirstLandsOnLast = doc.activeElement === items[items.length - 1];
    // Focus that has escaped to the page is brought back in.
    doc.querySelector('main').setAttribute('tabindex', '-1');
    doc.querySelector('main').focus();
    press('Tab');
    result.strayFocusPulledBack = drawer.contains(doc.activeElement);

    press('Escape');
    await tick();
    result.closedByEscape = ! shellData().mobileOpen;
    result.focusBackOnButton = doc.activeElement === button;
    result.expandedAfterEscape = button.getAttribute('aria-expanded');

    // Closed, Tab is the browser's again.
    result.tabWhenClosedPrevented = press('Tab');

    process.stdout.write(JSON.stringify(result));
    window.close();
})().catch(e => { process.stderr.write(String(e && e.stack || e)); process.exit(1); });
