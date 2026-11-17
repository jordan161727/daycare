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

/*
 * The example in the "not a date" message, per field (tester report, Nov
 * 2026): a birth date was being shown 06/16/2027, a day nobody here was born
 * on. A field can name its own with data-date-example; a birth date gets a
 * past one, anything else a plain one.
 */
const isBirthField = (input) => /birth|dob/i.test(`${input.name ?? ''} ${input.id ?? ''}`);
export function dateExample(input) {
    return input.dataset.dateExample || (isBirthField(input) ? '06/16/2021' : '06/16/2026');
}
/**
 * Whether typed text is as long as it is going to get: a full mm/dd/yyyy or
 * yyyy-mm-dd shape, more than ten characters, or a character no date has.
 * "06/1" is still being typed; "13/45/2030" and "06/1x" are finished wrong.
 */
export function looksFinished(text) {
    const value = String(text ?? '').trim();
    return /^\d{1,2}\/\d{1,2}\/\d{4}$/.test(value)
        || /^\d{4}-\d{1,2}-\d{1,2}$/.test(value)
        || value.length > 10
        || /[^\d/-]/.test(value)
        || /^\d{3}\//.test(value)
        || /^\d{1,2}\/\d{3}/.test(value);
}

export function invalidMessage(input) {
    return `Type the date as mm/dd/yyyy, for example ${dateExample(input)}.`;
}

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

    const placement = input.dataset.datePosition || (isBirthField(input) ? 'static' : 'auto');

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
        // data-date-weekdays: Saturdays and Sundays greyed out and not
        // pickable — the Holidays page, where the center is shut anyway
        // (QA, 17 Nov 2026). The server refuses a typed one too.
        disable: input.dataset.dateWeekdays !== undefined ? [(date) => date.getDay() === 0 || date.getDay() === 6] : [],
        // Monday first, as the register's week runs.
        locale: { firstDayOfWeek: 1 },
        // Where the calendar opens. 'auto' flips it above a field low on the
        // screen — on Add child that put it over the First and Last name
        // boxes (tester report, Nov 2026). A birth date's calendar opens in
        // the page itself instead, under the box and its message, pushing
        // what follows down: it covers no field at all. Any field can ask for
        // that with data-date-position="static", or for 'below' / 'above'.
        position: placement === 'static' ? 'below' : placement,
        static: placement === 'static',
        // Typed text is read strictly; see parseStrictDate. (A Date passed
        // in by script is taken as it is.)
        parseDate: (text) => parseStrictDate(text) ?? undefined,
    });

    const box = picker.altInput;

    // In the page, not over it: the wrapper flatpickr puts round a static
    // field is made full width (it is inline-block, which shrank the box),
    // and the calendar takes its place in the flow rather than floating.
    if (placement === 'static') {
        const wrapper = box.parentElement;
        if (wrapper?.classList.contains('flatpickr-wrapper')) Object.assign(wrapper.style, { display: 'block', width: '100%' });
        Object.assign(picker.calendarContainer.style, { position: 'relative', top: 'auto', left: 'auto', marginTop: '6px' });
    }

    box.placeholder = 'mm/dd/yyyy';
    box.setAttribute('inputmode', 'numeric');
    box.setAttribute('autocomplete', 'off');

    // A typed date that is not one is said so, and the form will not post
    // with it: the box is marked invalid, the hidden value emptied, and the
    // browser's own check stops the submit with the message.
    // flatpickr empties the box on its own when it cannot read what was
    // typed, so what was typed is kept here and put back with the message —
    // an empty box is the silent failure this is here to stop.
    //
    // The message is written under the field the moment the box is left
    // (tester report, Nov 2026) rather than only in the browser's bubble on
    // submit, and the box carries aria-invalid="true" and points at it.
    const message = input.ownerDocument.createElement('span');
    message.id = `${input.id || input.name || 'date'}-date-error`.replace(/[^\w-]/g, '_');
    message.className = 'date-field-error mt-1.5 text-xs font-medium text-rose-600 dark:text-rose-400';
    message.setAttribute('role', 'alert');
    message.hidden = true;
    message.style.display = 'none';
    box.insertAdjacentElement('afterend', message);
    const describedBy = (box.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
    box.setAttribute('aria-describedby', [...describedBy, message.id].join(' '));

    const mark = (bad) => {
        const text = bad ? invalidMessage(input) : '';
        box.setCustomValidity(text);
        if (bad) box.setAttribute('aria-invalid', 'true');
        else box.removeAttribute('aria-invalid');
        message.textContent = text;
        message.hidden = ! bad;
        message.style.display = bad ? 'block' : 'none';
    };

    let typed = '';
    box.addEventListener('input', () => { typed = box.value; });
    const check = (report = false) => {
        if (box.value.trim() === '' && typed.trim() !== '' && parseStrictDate(typed) === null) box.value = typed;
        const text = box.value.trim();
        const bad = text !== '' && parseStrictDate(text) === null;
        if (! bad) typed = '';
        mark(bad);
        if (bad) {
            if (native.get.call(input) !== '') {
                native.set.call(input, '');
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (report) box.reportValidity();
        }
        return ! bad;
    };
    box.addEventListener('blur', () => check());
    box.addEventListener('change', () => check());
    box.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && ! check(true)) e.preventDefault();
        // Tab moves on, and the calendar goes with it.
        if (e.key === 'Tab' && picker.isOpen) picker.close();
    });
    // While typing (second QA brief, Nov 2026): as soon as what is typed is
    // a whole date that is not one — 13/45/2030 — or cannot become one, the
    // message is shown and the calendar closed so it covers nothing; typing
    // it right clears the message without waiting for the box to be left.
    box.addEventListener('input', () => {
        box.setCustomValidity('');
        const text = box.value.trim();
        if (text !== '' && parseStrictDate(text) === null && looksFinished(text)) {
            mark(true);
            if (picker.isOpen) picker.close();
        } else if (! message.hidden && (text === '' || parseStrictDate(text) !== null || ! looksFinished(text))) {
            mark(false);
        }
    });

    // The calendar closes on Escape, on a click anywhere else and on the
    // form being sent (tester report, Nov 2026). flatpickr leaves Escape to
    // the calendar itself when the box takes typing, so it is caught here,
    // ahead of any page-level Escape (a dialog closes on the second press).
    input.ownerDocument.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && picker.isOpen) { picker.close(); e.stopPropagation(); }
    }, true);
    // The field sits inside a <label>: a click on the label's text or the
    // space around the box went to the box and opened the calendar again,
    // so "clicking elsewhere" never closed it. Only the box itself opens it.
    // (A static calendar sits inside the label itself: a click on a day is
    // kept from refocusing the box, which would open it again, but is not
    // taken as a click elsewhere.)
    box.closest('label')?.addEventListener('click', (e) => {
        if (e.target === box || e.target.closest?.('input, select, textarea, button, a')) return;
        e.preventDefault();
        if (picker.isOpen && ! picker.calendarContainer.contains(e.target)) picker.close();
    });
    input.form?.addEventListener('submit', (e) => {
        picker.close();
        if (! check(true)) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);

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
