// The note on a child's day, in the door screen's dialog — what used to go
// on the back of the paper sign-in sheet. One a day: the box writes it when
// there is none, and pressing the note opens it again to change it. Tells
// the roster so the card's mark follows.
//
// `url` is the row's comments endpoint, `initial` its notes newest first,
// `canAdd` whether the composer is offered at all (it is, all day), `onAdded`
// a hook the dialog uses to keep its own copy of the row in step when the
// note is written, and `onChanged` the same when it is changed.
export default function attendanceComments({ url, childId, canAdd = true, initial = [], onAdded = null, onChanged = null }) {
    return {
        comments: [...(initial ?? [])],
        canAdd,
        open: false,
        // The id of the note being changed, or null when writing a new one.
        editing: null,
        body: '',
        saving: false,
        error: null,
        get canSave() {
            return this.body.trim() !== '' && !this.saving;
        },
        openComposer() {
            this.editing = null;
            this.open = true;
            this.$nextTick(() => this.$refs.body?.focus());
        },
        // Pressing a note you may change opens the composer on its words.
        edit(comment) {
            if (!comment.can_edit) return;
            this.editing = comment.id;
            this.body = comment.body;
            this.error = null;
            this.open = true;
            this.$nextTick(() => this.$refs.body?.focus());
        },
        cancel() {
            this.open = false;
            this.editing = null;
            this.body = '';
            this.error = null;
        },
        async save() {
            if (!this.canSave) return;
            this.saving = true;
            this.error = null;
            const editing = this.editing;
            try {
                const res = await fetch(editing ? `${url}/${editing}` : url, {
                    method: editing ? 'PATCH' : 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ body: this.body.trim() }),
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    // The text stays and the composer stays open; the reason is said.
                    this.error = Object.values(json.errors ?? {}).flat()[0] ?? json.message ?? "Couldn't save. Try again.";
                    return;
                }
                const comment = json.data ?? json;
                const known = this.comments.some((c) => c.id === comment.id);
                if (editing || known) {
                    // Changed in place — or the server changed the day's one
                    // note because there already was one.
                    this.comments = this.comments.map((c) => (c.id === comment.id ? comment : c));
                    if (onChanged) onChanged(comment);
                } else {
                    this.comments.unshift(comment);
                    if (onAdded) onAdded(comment);
                    window.dispatchEvent(new CustomEvent('comment-added', {
                        detail: { childId, count: this.comments.length },
                    }));
                }
                this.cancel();
            } catch (e) {
                this.error = "Couldn't save. Check your connection and try again.";
            } finally {
                this.saving = false;
            }
        },
    };
}
