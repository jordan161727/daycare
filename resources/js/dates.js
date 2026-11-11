/*
 * Every date field reads mm/dd/yyyy, whoever is looking.
 *
 * A plain <input type="date"> is drawn by the browser in the language the
 * computer is set to — dd/mm/yyyy on one machine, yyyy-mm-dd on the next —
 * and a director who keeps the roll in Excel as 12/15/2022 was reading
 * 15/12/2022 under it. The page cannot tell the browser otherwise, so the
 * field is drawn here instead: a text box showing 12/15/2022 with a calendar
 * under it, while the form still posts Y-m-d exactly as before. Nothing on
 * the server changes.
 *
 * Fields added after the page loads — a row opened by Alpine — are picked
 * up as they appear. A value written by script, as x-model does, is shown
 * too: the field's value setter is wrapped so the visible box follows it.
 */
import flatpickr from 'flatpickr';

const FORMAT = 'm/d/Y';
const INVALID = 'Type the date as mm/dd/yyyy, for example 06/16/2027.';

/**
 * A typed date, read strictly (QA #27, #39, 12 Nov 2026). mm/dd/yyyy — one-
 * or two-digit month and day, four-digit year — or the posted Y-m-d; anything
 * else, 13/45/2023 included, is no date at all rather than whatever the
 * browser's own date parser makes of it (06/16/2027 had become 11/08/2026).
 */
export function parseStrictDate(text) {
    const value = String(text ?? '').trim();
    let m, d, y;
    let match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(value);
    if (match) [, m, d, y] = match.map(Number);
    else if ((match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value))) [, y, m, d] = match.map(Number);
    else return null;

    const date = new Date(y, m - 1, d);
    if (date.getFullYear() !== y || date.getMonth() !== m - 1 || date.getDate() !== d) return null;
    return date;
}

export function upgradeDateInput(input) {
    if (input._flatpickr || input.dataset.nativeDate !== undefined) return input._flatpickr ?? null;

    const picker = flatpickr(input, {
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: FORMAT,
        allowInput: true,
        // The same box on a tablet as on a desk: a native picker there
        // would read the tablet's language again.
        disableMobile: true,
        minDate: input.min || undefined,
        maxDate: input.max || undefined,
        // Monday first, as the register's week runs.
        locale: { firstDayOfWeek: 1 },
        // Typed text is read strictly; see parseStrictDate. (A Date passed
        // in by script is taken as it is.)
        parseDate: (text) => parseStrictDate(text) ?? undefined,
    });

    const box = picker.altInput;
    box.placeholder = 'mm/dd/yyyy';
    box.setAttribute('inputmode', 'numeric');
    box.setAttribute('autocomplete', 'off');

    // A typed date that is not one is said so, and the form will not post
    // with it: the box is marked invalid, the hidden value emptied, and the
    // browser's own check stops the submit with the message.
    // flatpickr empties the box on its own when it cannot read what was
    // typed, so what was typed is kept here and put back with the message —
    // an empty box is the silent failure this is here to stop.
    let typed = '';
    box.addEventListener('input', () => { typed = box.value; });
    const check = () => {
        if (box.value.trim() === '' && typed.trim() !== '' && parseStrictDate(typed) === null) box.value = typed;
        const text = box.value.trim();
        const bad = text !== '' && parseStrictDate(text) === null;
        if (! bad) typed = '';
        box.setCustomValidity(bad ? INVALID : '');
        box.toggleAttribute('aria-invalid', bad);
        if (bad) {
            if (native.get.call(input) !== '') {
                native.set.call(input, '');
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            box.reportValidity();
        }
        return ! bad;
    };
    box.addEventListener('blur', check);
    box.addEventListener('keydown', (e) => { if (e.key === 'Enter' && ! check()) e.preventDefault(); });
    box.addEventListener('input', () => box.setCustomValidity(''));
    input.form?.addEventListener('submit', (e) => { if (! check()) { e.preventDefault(); e.stopImmediatePropagation(); } }, true);

    // Script writes the hidden field (Alpine's x-model, a reset button); the
    // visible box has to follow, and nothing fires for a property write — so
    // the setter is wrapped.
    const native = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    let syncing = false;

    Object.defineProperty(input, 'value', {
        configurable: true,
        get() { return native.get.call(this); },
        set(value) {
            const before = native.get.call(this);
            native.set.call(this, value);

            // flatpickr writes this same field when it updates, so a write
            // made while it is updating, or one that changes nothing, is not
            // handed back to it — that was a loop.
            if (syncing || before === native.get.call(this)) return;

            syncing = true;
            try { picker.setDate(value || null, false); } finally { syncing = false; }
        },
    });

    return picker;
}

function upgradeAll(root = document) {
    root.querySelectorAll?.('input[type="date"]').forEach(upgradeDateInput);
}

export function watchDateInputs() {
    upgradeAll();

    new MutationObserver(records => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (!(node instanceof Element)) continue;
                if (node.matches('input[type="date"]')) upgradeDateInput(node);
                upgradeAll(node);
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
}
