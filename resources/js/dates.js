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
    });

    picker.altInput.placeholder = 'mm/dd/yyyy';
    picker.altInput.setAttribute('inputmode', 'numeric');
    picker.altInput.setAttribute('autocomplete', 'off');

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
