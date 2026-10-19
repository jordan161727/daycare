{{-- The earlier comments of a child, as the dialog keeps and fetches them.

     Spread into an Alpine component (`...pastCommentsMixin({...})`) that
     has a `child` with an id: the door screen's roster, and the comments
     log, which opens the same dialog. One page of days at a time, from
     GET /children/:id/comments; "Load older" asks for the next page under
     the last day shown. Rendered once a page by @once. --}}
@once
<script>
function pastCommentsMixin({ childrenUrl, today }) { return {
    past: {
        // The "Show" box: all | 30d | 7d | year | date.
        range: 'all',
        // The one day, when the box says "Pick a date…".
        date: '',
        days: [],
        loading: false,
        hasMore: false,
        nextBefore: null,
        error: '',
        // The day, and the comment, to scroll to and light up when the
        // tab opens from the panel or the log.
        goto: null,
        highlightId: null,
        highlightDate: null,
        // Which child the list belongs to, so a stale answer is dropped.
        forChild: null,
        request: 0,
    },
    pastToday: today,

    /** Options for the Show box, in the order the dialog offers them. */
    pastRanges: [
        { value: 'all', label: 'All comments' },
        { value: '30d', label: 'Last 30 days' },
        { value: '7d', label: 'Last 7 days' },
        { value: 'year', label: 'This school year' },
        { value: 'date', label: 'Pick a date…' },
    ],

    resetPast() {
        this.past = { ...this.past, range: 'all', date: '', days: [], loading: false, hasMore: false, nextBefore: null, error: '', goto: null, highlightId: null, highlightDate: null, forChild: null };
    },

    /** Open the tab, fetching the list if it is not this child's yet. */
    showPast(date = null, commentId = null) {
        this.tab = 'past';
        if (date || commentId) this.past.goto = { date, commentId };
        if (this.past.forChild !== (this.child?.id ?? null) || (! this.past.days.length && ! this.past.loading)) {
            this.loadPast(true);
        } else if (! this.past.loading) {
            this.revealPast();
        }
        // A load already in flight reveals the latest `goto` when it lands.
    },

    /** The Show box changed: the list afresh. */
    changePastRange() {
        if (this.past.range === 'date' && ! this.past.date) {
            this.past.days = [];
            this.past.hasMore = false;
            return;
        }
        this.loadPast(true);
    },

    /** Fetch a page of days: the first (`reset`), or the next under the last shown. */
    async loadPast(reset = false) {
        const child = this.child;
        if (! child) return;

        if (reset) {
            this.past.days = [];
            this.past.hasMore = false;
            this.past.nextBefore = null;
        }
        this.past.forChild = child.id;
        this.past.loading = true;
        this.past.error = '';
        const ticket = ++this.past.request;

        const params = new URLSearchParams({ limit: '20' });
        if (this.past.range === 'date') {
            if (! this.past.date) { this.past.loading = false; return; }
            params.set('date', this.past.date);
        } else {
            params.set('range', this.past.range);
        }
        if (! reset && this.past.nextBefore) params.set('before', this.past.nextBefore);

        try {
            const response = await fetch(childrenUrl + '/' + child.id + '/comments?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json().catch(() => ({}));
            if (ticket !== this.past.request) return; // a newer request took over

            if (! response.ok) {
                this.past.error = data.message || 'Those comments could not be loaded.';
                return;
            }

            this.past.days = reset ? (data.days ?? []) : [...this.past.days, ...(data.days ?? [])];
            this.past.hasMore = !! data.has_more;
            this.past.nextBefore = data.next_before ?? null;
            this.revealPast();
        } catch {
            if (ticket === this.past.request) this.past.error = 'Those comments could not be loaded. Check the connection and try again.';
        } finally {
            if (ticket === this.past.request) this.past.loading = false;
        }
    },

    loadOlder() {
        if (this.past.loading || ! this.past.hasMore) return;
        this.loadPast(false);
    },

    /** Scroll to the day asked for and light the comment up for a moment. */
    revealPast() {
        const goto = this.past.goto;
        if (! goto) return;
        this.past.goto = null;

        const has = goto.date && this.past.days.some(d => d.date === goto.date);
        if (! has) return;

        this.past.highlightDate = goto.date;
        this.past.highlightId = goto.commentId ?? null;

        // Once Alpine has drawn the cards. The day's card lands on one
        // flush and the comments inside it on the next, so this looks a
        // few times rather than once. Plain timers rather than $nextTick,
        // which is not on `this` when a method is reached from the nested
        // comments component — the "View" link beside the comment label.
        let tries = 0;
        const look = () => {
            const dialog = document.querySelector('[data-card-dialog]') || document;
            const comment = goto.commentId ? dialog.querySelector('[data-past-comment="' + goto.commentId + '"]') : null;
            const day = dialog.querySelector('[data-past-day="' + goto.date + '"]');

            if ((goto.commentId && ! comment && tries++ < 10) || (! day && tries++ < 10)) {
                setTimeout(look, 30);
                return;
            }

            (comment || day)?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
            setTimeout(() => { this.past.highlightId = null; this.past.highlightDate = null; }, 2400);
        };
        setTimeout(look, 0);
    },

    /** "Fri, Oct 16" for the tab, from a Y-m-d. */
    pastDayLabel(iso) {
        return new Date(iso + 'T12:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
    },
}}
</script>
@endonce
