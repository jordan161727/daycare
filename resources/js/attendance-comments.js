// The notes on a child's day, in the door screen's dialog — what used to go
// on the back of the paper sign-in sheet. Opens on what the server sent, posts
// new notes, and tells the roster so the card's count follows.
//
// `url` is the row's comments endpoint, `initial` its notes newest first,
// `canAdd` whether the child is still in (no notes once they have gone), and
// `onAdded` a hook the dialog uses to keep its own copy of the row in step.
export default function attendanceComments({ url, childId, canAdd = true, initial = [], onAdded = null }) {
    return {
        comments: [...(initial ?? [])],
        canAdd,
        open: false,
        tag: 'parent_note',
        body: '',
        saving: false,
        error: null,
        tags: [
            { value: 'parent_note', label: 'Parent note' },
            { value: 'health', label: 'Health' },
            { value: 'belongings', label: 'Belongings' },
            { value: 'incident', label: 'Incident' },
            { value: 'other', label: 'Other' },
        ],
        get canSave() {
            return this.body.trim() !== '' && !this.saving;
        },
        openComposer() {
            this.open = true;
            this.$nextTick(() => this.$refs.body?.focus());
        },
        cancel() {
            this.open = false;
            this.body = '';
            this.tag = 'parent_note';
            this.error = null;
        },
        async save() {
            if (!this.canSave) return;
            this.saving = true;
            this.error = null;
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ tag: this.tag, body: this.body.trim() }),
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    // The text stays and the composer stays open; the reason is said.
                    this.error = Object.values(json.errors ?? {}).flat()[0] ?? json.message ?? "Couldn't save. Try again.";
                    return;
                }
                const comment = json.data ?? json;
                this.comments.unshift(comment);
                if (onAdded) onAdded(comment);
                this.cancel();
                window.dispatchEvent(new CustomEvent('comment-added', {
                    detail: { childId, count: this.comments.length },
                }));
            } catch (e) {
                this.error = "Couldn't save. Check your connection and try again.";
            } finally {
                this.saving = false;
            }
        },
    };
}
