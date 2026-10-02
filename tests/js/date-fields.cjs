/*
 * The date fields, exercised in a headless DOM — as JSON on stdout.
 *
 * Driven by tests/Feature/DateFieldsReadMonthFirstTest.php. Four things are
 * checked: a server-rendered date shows as mm/dd/yyyy while the form still
 * holds Y-m-d; a value written by script (as Alpine's x-model does) reaches
 * the visible box; a date typed into the visible box reaches the form as
 * Y-m-d and fires the change the page listens for; and a field added after
 * load is picked up.
 *
 * Everything runs inside the page, the way a browser would run it: the
 * flatpickr browser build and dates.js are appended as scripts, so no DOM
 * globals have to be faked for them.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const root = path.join(__dirname, '..', '..');
const flatpickr = fs.readFileSync(path.join(root, 'node_modules', 'flatpickr', 'dist', 'flatpickr.js'), 'utf8');
const dates = fs.readFileSync(path.join(root, 'resources', 'js', 'dates.js'), 'utf8')
    .replace(/import flatpickr from 'flatpickr';/, '')
    .replace(/export function/g, 'function')
    + '\nwindow.__dates = { upgradeDateInput, watchDateInputs };';

const errors = [];
const vc = new VirtualConsole();
vc.on('jsdomError', e => errors.push(String(e.detail?.message || e.message || e).split('\n')[0]));

const dom = new JSDOM(`<!doctype html><html><body>
    <form id="f"><input type="date" name="birth_date" id="dob" value="2022-12-15"></form>
    <div id="later"></div>
    <script>${flatpickr}</script>
    <script>${dates}</script>
</body></html>`, { runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc, url: 'http://localhost/' });

const { window } = dom;
const tick = () => new Promise(resolve => setTimeout(resolve, 30));

(async () => {
    await tick();
    const doc = window.document;
    window.__dates.watchDateInputs();
    await tick();

    const dob = doc.getElementById('dob');
    const shown = () => dob._flatpickr.altInput;
    const result = { errors };

    // A server-rendered date: what the reader sees, what the form holds.
    result.rendered = shown().value;
    result.posts = dob.value;
    result.hidden = dob.type;
    result.placeholder = shown().placeholder;

    // Script writes the form field, as x-model does: the box follows.
    dob.value = '2024-03-02';
    result.afterScriptWrite = shown().value;

    // The reader types a date and leaves the box: the form gets Y-m-d, and
    // the change the page listens for fires.
    let changes = 0;
    dob.addEventListener('change', () => changes++);
    shown().value = '06/15/2023';
    shown().dispatchEvent(new window.Event('blur'));
    await tick();
    result.afterTyping = dob.value;
    result.changes = changes;

    // A field that arrives after load is picked up as it appears.
    doc.getElementById('later').innerHTML = '<input type="date" id="late" value="2026-10-03">';
    await tick(); await tick();
    const late = doc.getElementById('late');
    result.lateUpgraded = !! late._flatpickr;
    result.lateShown = late._flatpickr?.altInput.value ?? null;

    process.stdout.write(JSON.stringify(result));
    window.close();
})().catch(error => { process.stderr.write(String(error.stack || error)); process.exit(1); });
