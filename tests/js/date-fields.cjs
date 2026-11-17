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
    <form id="f"><input type="date" name="birth_date" id="dob" value="2022-12-15">
        <label id="enr-label"><span id="enr-text">Enrolled</span> <input type="date" name="enrolled_on" id="enr"></label>
        <button id="send">Save</button></form>
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

    // ---- Tester report, Nov 2026 ----
    const key = (el, k) => el.dispatchEvent(new window.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
    const enr = doc.getElementById('enr');
    const enrBox = enr._flatpickr.altInput;
    const dobPicker = dob._flatpickr;

    // A birth date's calendar sits in the page under the box, not over it.
    result.dobStatic = dobPicker.config.static === true;
    result.dobCalendarInFlow = dobPicker.calendarContainer.style.position === 'relative'
        && dobPicker.calendarContainer.parentElement === shown().parentElement;
    result.enrPosition = enr._flatpickr.config.position;

    // Escape, Tab and the form being sent each close it.
    dobPicker.open(); result.openedOnce = dobPicker.isOpen;
    key(shown(), 'Escape'); result.closedByEscape = ! dobPicker.isOpen;
    dobPicker.open(); key(shown(), 'Tab'); result.closedByTab = ! dobPicker.isOpen;
    dobPicker.open();
    let submitted = 0;
    doc.getElementById('f').addEventListener('submit', (e) => { submitted++; e.preventDefault(); });
    doc.getElementById('f').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    result.closedBySubmit = ! dobPicker.isOpen;

    // A click on the label's text does not open the calendar again, and
    // closes one that is open.
    enr._flatpickr.open();
    doc.getElementById('enr-text').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
    result.labelClickCloses = ! enr._flatpickr.isOpen;

    // Nonsense typed: the message is under the field at once, with the
    // field's own example — a past one for a birth date.
    const messageOf = (box) => doc.getElementById(box.getAttribute('aria-describedby').split(' ').pop());
    dobPicker.open();
    shown().value = '13/45/2030';
    shown().dispatchEvent(new window.Event('input', { bubbles: true }));
    result.whileTypingMessage = messageOf(shown()).textContent;
    result.whileTypingShown = messageOf(shown()).style.display !== 'none';
    result.whileTypingInvalid = shown().getAttribute('aria-invalid');
    result.whileTypingClosed = ! dobPicker.isOpen;
    // Half typed is not yet wrong.
    shown().value = '06/1';
    shown().dispatchEvent(new window.Event('input', { bubbles: true }));
    result.halfTypedShown = messageOf(shown()).style.display !== 'none';
    shown().value = '06/16/2021';
    shown().dispatchEvent(new window.Event('input', { bubbles: true }));
    result.fixedInvalid = shown().getAttribute('aria-invalid');

    // Left half typed: said on leaving the box, with a plain example.
    enrBox.value = '06/1';
    enrBox.dispatchEvent(new window.Event('input', { bubbles: true }));
    result.enrHalfTypedShown = messageOf(enrBox).style.display !== 'none';
    enrBox.dispatchEvent(new window.Event('blur'));
    await tick();
    result.blurMessage = messageOf(enrBox).textContent;
    result.blurInvalid = enrBox.getAttribute('aria-invalid');
    result.submittedWithBadDate = (() => { const before = submitted; doc.getElementById('f').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })); return submitted > before; })();

    process.stdout.write(JSON.stringify(result));
    window.close();
})().catch(error => { process.stderr.write(String(error.stack || error)); process.exit(1); });
