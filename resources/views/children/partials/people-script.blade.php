{{--
    The People step and the drawer, in one component because they share a list:
    saving a person in the drawer has to change the name on the row behind it,
    and unlinking a row has to close the drawer if it was open on that person.
--}}
<script>
function peopleStep(config) { return {
    childId: config.childId,
    childName: config.childName,
    household: config.household,
    relationships: config.relationships,
    rows: config.rows,

    query: '',
    results: [],
    searching: false,
    error: '',

    creating: false,
    saving: false,
    createError: '',
    draft: {},

    drawer: { open: false, person: {}, children: [], ssn: '', saving: false, error: '' },

    /*
     * Asking before taking something away.
     *
     * Replaces window.confirm, which announced itself as "127.0.0.1:8000 says"
     * and could only manage one line — so unlinking somebody and deleting them
     * outright read identically, when one is reversible and the other is not.
     *
     * A promise, so the callers below still read top to bottom the way they did
     * with confirm(): ask, and stop if the answer is no.
     */
    confirming: { open: false, name: '', title: '', body: '', detail: '', action: '', tone: 'amber', marks: [], resolve: null },

    ask(options) {
        return new Promise(resolve => {
            this.confirming = {
                open: true, tone: 'amber', marks: [], detail: '', body: '', name: '',
                ...options, resolve,
            };

            // The safe answer is the one under the finger — Escape and a click
            // outside both cancel, so only a deliberate press goes through.
            this.$nextTick(() => this.$refs.confirmAction?.focus());
        });
    },

    answer(yes) {
        const resolve = this.confirming.resolve;

        this.confirming.open = false;
        this.confirming.resolve = null;

        if (resolve) resolve(yes);
    },

    /** What a person is for this child, as the dialog lists it back. */
    marksFor(row) {
        return [
            row.relationship,
            row.is_guardian ? 'Legal guardian' : null,
            row.can_pickup ? (row.restriction ? 'Pick-up (restricted)' : 'Can pick up') : null,
            row.is_emergency ? 'Emergency' + (row.priority ? ' #' + row.priority : '') : null,
        ].filter(Boolean);
    },

    init() {
        this.resetDraft();
    },

    resetDraft() {
        this.draft = {
            name: '', address: '', same_as_household: true,
            home_phone: '', work_phone: '', cell: '', email: '',
            employer: '', title: '', fax: '', ssn: '',
            relationship: '', is_guardian: false, can_pickup: false, is_emergency: false,
        };
    },

    /* ---- the list ---- */

    /**
     * Write one row's ticks.
     *
     * Sent on every change rather than held for the form's Save: a link is a
     * row in another table, about a person who may belong to three other
     * children, and holding it in a browser tab is how it gets lost.
     */
    async save(row) {
        this.error = '';

        try {
            const response = await window.postJson(`/people/${row.id}/link`, {
                child_id: this.childId,
                relationship: row.relationship || null,
                is_guardian: row.is_guardian,
                can_pickup: row.can_pickup,
                is_emergency: row.is_emergency,
                priority: row.is_emergency ? (row.priority || null) : null,
                restriction: row.restriction || null,
            });

            const data = await response.json().catch(() => ({}));

            if (! response.ok) throw new Error(data.message || 'That change could not be saved.');

            // The server renumbers the call order across the whole child, so
            // one row being ticked can move another's number. The saved row
            // reads its own back; the rest are re-read rather than guessed.
            Object.assign(row, {
                priority: data.person.priority,
                is_emergency: data.person.is_emergency,
                restriction: data.person.restriction,
            });

            await this.reloadRows();
        } catch (problem) {
            this.error = problem.message;
        }
    },

    /** The list as the server has it, after a write that can renumber it. */
    async reloadRows() {
        try {
            const response = await fetch(`/children/${this.childId}/people`, {
                headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
            });

            if (! response.ok) return;

            const data = await response.json();

            this.rows = data.people ?? this.rows;
        } catch {
            // The row that was just saved is already right; the others keep
            // whatever they had rather than the list emptying itself.
        }
    },

    async unlink(row) {
        const others = this.otherChildrenCount(row);

        const yes = await this.ask({
            name: row.name,
            title: `Take ${row.name} off ${this.childName}'s record?`,
            body: `They will no longer be listed for ${this.childName} — not for pick-up, and not as a number to ring.`,
            marks: this.marksFor(row),
            // The distinction the old one-liner could not draw: the person is
            // not being deleted, and their other children are untouched.
            detail: others > 0
                ? `${row.name} stays on file, along with the ${others === 1 ? 'other child' : others + ' other children'} they are linked to.`
                : `${row.name} stays on file and can be linked again at any time.`,
            action: 'Unlink',
            tone: 'amber',
        });

        if (! yes) return;

        this.error = '';

        try {
            const response = await window.postJson(`/people/${row.id}/unlink`, { child_id: this.childId });

            if (! response.ok) throw new Error('That person could not be unlinked.');

            this.rows = this.rows.filter(other => other.id !== row.id);

            if (this.drawer.open && this.drawer.person.id === row.id) this.closeDrawer();
        } catch (problem) {
            this.error = problem.message;
        }
    },

    /* ---- finding somebody already on file ---- */

    async runSearch() {
        const query = this.query.trim();

        if (query.length < 2) { this.results = []; return; }

        this.searching = true;

        try {
            const response = await fetch(
                `/people/search?q=${encodeURIComponent(query)}&child_id=${this.childId}`,
                { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }
            );
            const data = await response.json();

            this.results = data.people ?? [];
        } catch { this.results = []; } finally { this.searching = false; }
    },

    async linkExisting(person) {
        this.error = '';

        try {
            const response = await window.postJson(`/people/${person.id}/link`, {
                child_id: this.childId,
                can_pickup: false, is_guardian: false, is_emergency: false,
            });

            const data = await response.json().catch(() => ({}));

            if (! response.ok) throw new Error(data.message || 'That person could not be linked.');

            this.rows.push(this.rowFrom(data.person));
            this.query = '';
            this.results = [];
        } catch (problem) {
            this.error = problem.message;
        }
    },

    /* ---- somebody genuinely new ---- */

    async createPerson() {
        this.createError = '';
        this.saving = true;

        try {
            const body = { child_id: this.childId, ...this.draft };

            // Blank means "the child's household", which is what the tick says.
            if (this.draft.same_as_household) body.address = null;
            delete body.same_as_household;

            const response = await window.postJson('/people', body);
            const data = await response.json().catch(() => ({}));

            if (! response.ok) {
                throw new Error(data.message || Object.values(data.errors ?? {}).flat()[0] || 'That person could not be created.');
            }

            this.rows.push(this.rowFrom(data.person));

            // Somebody who turned out to be on file already is linked rather
            // than refused — the press said they belong to this child, and that
            // was true whether or not the record had to be made.
            if (data.status === 'linked_existing') {
                this.error = `${data.person.name} was already on file, so the existing record was linked.`;
            }

            this.creating = false;
            this.resetDraft();
        } catch (problem) {
            this.createError = problem.message;
        } finally {
            this.saving = false;
        }
    },

    rowFrom(person) {
        return {
            id: person.id,
            name: person.name,
            cell: person.cell,
            relationship: person.relationship,
            is_guardian: person.is_guardian,
            can_pickup: person.can_pickup,
            is_emergency: person.is_emergency,
            priority: person.priority,
            restriction: person.restriction,
            linked_count: person.linked_count ?? 1,
        };
    },

    /* ---- the drawer ---- */

    async openDrawer(personId) {
        this.drawer = { open: true, person: {}, children: [], ssn: '', saving: false, error: '' };

        try {
            const response = await fetch(`/people/${personId}`, {
                headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
            });
            const data = await response.json();

            this.drawer.person = data.person;
            this.drawer.children = data.children;
        } catch {
            this.drawer.error = 'That person could not be opened.';
        }
    },

    closeDrawer() {
        this.drawer.open = false;
    },

    async savePerson() {
        this.drawer.saving = true;
        this.drawer.error = '';

        try {
            const body = { ...this.drawer.person };

            // Only a newly typed SSN is sent; the masked one on screen is not a
            // value, it is a statement that one is on file.
            if (this.drawer.ssn) body.ssn = this.drawer.ssn;
            else delete body.ssn;

            const response = await window.postJson(`/people/${this.drawer.person.id}`, body);
            const data = await response.json().catch(() => ({}));

            if (! response.ok) {
                throw new Error(Object.values(data.errors ?? {}).flat()[0] || 'That person could not be saved.');
            }

            // The row behind the drawer carries the same name and number.
            const row = this.rows.find(other => other.id === this.drawer.person.id);

            if (row) { row.name = data.person.name; row.cell = data.person.cell; }

            this.drawer.open = false;
        } catch (problem) {
            this.drawer.error = problem.message;
        } finally {
            this.drawer.saving = false;
        }
    },

    async deletePerson() {
        const yes = await this.ask({
            name: this.drawer.person.name,
            title: `Delete ${this.drawer.person.name}?`,
            body: 'This removes the person from the centre entirely — their details, their number and everything on file about them.',
            detail: 'This cannot be undone. To take them off one child only, close this and use Unlink on their row instead.',
            action: 'Delete person',
            tone: 'rose',
        });

        if (! yes) return;

        this.drawer.error = '';

        try {
            const response = await window.postJson(`/people/${this.drawer.person.id}/delete`, {});
            const data = await response.json().catch(() => ({}));

            if (! response.ok) throw new Error(data.message || 'That person could not be deleted.');

            this.rows = this.rows.filter(other => other.id !== this.drawer.person.id);
            this.drawer.open = false;
        } catch (problem) {
            this.drawer.error = problem.message;
        }
    },

    /* ---- small things the markup asks for ---- */

    initials(name) {
        return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0].toUpperCase()).join('');
    },

    /**
     * How many other children this person belongs to.
     *
     * Only the drawer has been told, so the honest answer before it has been
     * opened is none — and the dialog says "stays on file" either way, which
     * is true whether the number is nought or three.
     */
    otherChildrenCount(row) {
        if (this.drawer.person.id === row.id) {
            return Math.max(0, this.drawer.children.length - 1);
        }

        return Math.max(0, (row.linked_count ?? 1) - 1);
    },

    firstName(name) {
        return (name || 'this person').split(/\s+/)[0];
    },

    filledCount() {
        const fields = ['name', 'address', 'home_phone', 'work_phone', 'cell', 'email', 'employer', 'title', 'fax'];
        const filled = fields.filter(field => (this.drawer.person[field] ?? '') !== '').length;

        return filled + (this.drawer.person.ssn_last4 ? 1 : 0);
    },
}; }
</script>
