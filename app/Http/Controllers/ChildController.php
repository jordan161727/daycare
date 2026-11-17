<?php

namespace App\Http\Controllers;

use App\Exports\ChildrenExport;
use App\Imports\ChildrenImport;
use App\Models\Child;
use App\Models\RoomSchedule;
use App\Models\User;
use App\Services\ClassroomAssignment;
use App\Services\WeekSchedule;
use App\Services\PeopleDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;

class ChildController extends Controller
{
    /*
     * Whose record, not which fields.
     *
     * A teacher keeps the whole record of the children in their own rooms —
     * the phone numbers and the pick-up list, and the hours, the dates and the
     * room too. They are the one who is told any of it first, and splitting the
     * record in half only decided which corrections had to wait on the office.
     *
     * The guard that matters is which child, and it is the same rule the roster
     * is filtered by: isVisibleTo, checked on both edit() and update(). That is
     * what keeps the room field safe to hand over. A teacher can only act on a
     * child they already hold, so setting the room can move one out of their
     * own roster — never pull one in. There is no record here that becomes
     * readable by editing.
     *
     * Creating a child is still the director's: a new record decides which room
     * it lands in before anybody holds it, so there is no roster to check it
     * against. See the route group in routes/web.php.
     */
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        [$children, $sort, $direction, $status, $room, $pay, $gap] = $this->roster(defaultStatus: 'Active', lenient: true);

        $requestUser = request()->user();

        // Everybody attached to the roll and every paper on file, in two
        // queries rather than two per child: the Guardian and Record columns
        // and the "Records to finish" counts all read these.
        $children->load(['personLinks.person', 'documents']);

        // The whole roll, not the page: "61 active" counted off ten rows would
        // be a different number on every page of the same list.
        // Active is "enrolled today" — Child::inStatusTab, the rule every
        // other page counts by (RoomCounts::isEnrolled; QA retest, 16 Nov 2026).
        $activeCount = Child::visibleTo($requestUser)->inStatusTab('Active')->count();

        // Shown only when there are any: a standing "0 pending" is a number
        // that is read once and then stops being looked at.
        $pendingCount = Child::visibleTo($requestUser)->inStatusTab('Pending')->count();

        // Every status and how many are in it, for the chips. Counted over the
        // whole roll rather than the filtered view — they are how the filter is
        // chosen, so a count that moved when a chip was pressed would be
        // answering about somewhere else. Each tab by Child::inStatusTab, so
        // the Active tab is the dashboard's enrolled and a child past their
        // last day is with the leavers until the nightly sync moves them.
        //
        // The archived are not on the roll at all: All, Active and Inactive
        // never include them, whichever tab is open.
        $statusCounts = collect(['Active', 'Pending', 'Inactive'])->mapWithKeys(fn (string $tab) => [
            $tab => Child::visibleTo($requestUser)->notArchived()->inStatusTab($tab)->count(),
        ]);

        $rollCount = Child::visibleTo($requestUser)->notArchived()->count();

        // The Archived tab is a setting, off by default: a centre that has
        // never archived anybody should not be offered an empty drawer. Its
        // count is separate on purpose — it is not part of the roll.
        $showArchived = self::showsArchived();
        $archivedCount = $showArchived ? Child::visibleTo($requestUser)->archived()->count() : 0;

        /*
         * Records to finish (Phase 2.1): what is still missing across the
         * active roll, counted once here so the banner can say "3 no
         * emergency contact" and a press lists just those children. Over the
         * active children only — a withdrawn child's missing consent is not
         * a job for today.
         */
        $active = Child::visibleTo($requestUser)->inStatusTab('Active')->with(['personLinks', 'documents'])->get();
        $gapCounts = collect(Child::GAPS)->map(fn ($label, $key) => $active->filter(fn (Child $child) => array_key_exists($key, $child->missingItems()))->count())->filter();

        // The room chips and the pay chips count the tab that is open, as the
        // mock has them: "Toddler 11" is eleven of the children listed.
        $tab = Child::query()->visibleTo($requestUser)
            ->when($status !== Child::ARCHIVED, fn ($q) => $q->notArchived())
            ->when($status !== '', fn ($q) => $q->inStatusTab($status))
            ->get(['id', 'classroom', 'dss_case_no', 'dss_cin']);
        $roomCounts = ClassroomAssignment::rooms();
        $roomCounts = collect($roomCounts)->mapWithKeys(fn ($r) => [$r => $tab->where('classroom', $r)->count()])->all();
        $payCounts = [
            'all' => $tab->count(),
            'dss' => $tab->filter(fn (Child $c) => $c->isDss())->count(),
            'private' => $tab->filter(fn (Child $c) => ! $c->isDss())->count(),
        ];

        /*
         * The search box filters in the browser, so the room and pay chips
         * have to be recounted there too (tester report, Nov 2026: "Showing
         * 118 of 123" and "Toddler 11" stood still under a search that found
         * nobody). Every child the chips count, by LAN: the search text, the
         * room, and whether DSS pays.
         */
        $searchIndex = Child::query()->visibleTo($requestUser)
            ->when($status !== Child::ARCHIVED, fn ($q) => $q->notArchived())
            ->when($status !== '', fn ($q) => $q->inStatusTab($status))
            ->with('personLinks.person')
            ->get()
            ->mapWithKeys(fn (Child $c) => [(string) $c->lan => ['h' => $c->rosterSearchText(), 'r' => (string) $c->classroom, 'd' => $c->isDss()]])
            ->all();

        return view('children.index', compact('searchIndex','children', 'sort', 'direction', 'activeCount', 'pendingCount', 'status', 'statusCounts', 'rollCount', 'showArchived', 'archivedCount', 'room', 'pay', 'gap', 'gapCounts', 'roomCounts', 'payCounts'));
    }

    /** Whether the Children page has an Archived tab. Set in Settings › Children. */
    public static function showsArchived(): bool
    {
        return \App\Models\Setting::bool(self::SHOW_ARCHIVED_SETTING, false);
    }

    /** The setting that adds the Archived tab. Read here and written in SettingController. */
    public const SHOW_ARCHIVED_SETTING = 'children.show_archived';

    /** The status= value for the All tab, now that no status means Active (QA #5). */
    public const ALL_STATUSES = 'all';

    /**
     * The roll as the page is asking for it: filtered, sorted, and narrowed to
     * what this reader may see.
     *
     * Shared with the export rather than written twice. A spreadsheet that
     * quietly held a different set of children from the screen it was
     * downloaded from is the kind of difference nobody notices until it has
     * been sent somewhere.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Collection<int, Child>, 1: string, 2: string, 3: string}
     */
    private function roster(string $defaultStatus = '', bool $lenient = false): array
    {
        /*
         * $lenient is the page's: an address with a sort, status or chip
         * nobody offers — an old bookmark, a hand-edited link — opens the
         * default list rather than a 404 page (tester report, Nov 2026). The
         * export stays strict: a file is not to be quietly built from a
         * different question than the one asked.
         */
        $refuse = function (bool $bad) use ($lenient) {
            if ($bad && ! $lenient) {
                abort(404);
            }

            return $bad;
        };

        $allowedSorts = ['lan', 'first_name', 'last_name', 'dob', 'age', 'classroom', 'schedule', 'alerts', 'status'];
        // The roll opens in LAN order: it is the number on the cabinet, the
        // parent letter and every sheet the centre keeps, so it is what
        // somebody arrives already holding.
        $sort = trim((string) request('sort')) ?: 'lan';
        $direction = trim((string) request('direction')) ?: 'asc';

        if ($refuse(! in_array($sort, $allowedSorts, true))) {
            $sort = 'lan';
        }
        if ($refuse(! in_array($direction, ['asc', 'desc'], true))) {
            $direction = 'asc';
        }

        // Which status the roll is filtered to, or all of them. An invented one
        // is a 404 rather than an empty list: a page that says "no children"
        // when the truth is "no such status" sends somebody looking for a bug
        // in their data.
        //
        // The page opens on Active (QA #5, 12 Nov 2026): the "Records to
        // finish" counts are over the active roll, and a list that opened on
        // everybody — withdrawn included — answered a different question from
        // the badge above it (84 counted, 88 listed). All is now asked for by
        // name, status=all; an empty status= still means All, so an address
        // written before this keeps meaning what it did.
        $status = request()->has('status') ? trim((string) request('status')) : $defaultStatus;
        if (strtolower($status) === self::ALL_STATUSES) {
            $status = '';
        }
        if ($refuse(! ($status === '' || in_array($status, Child::STATUSES, true)))) {
            $status = $defaultStatus;
        }

        // The archived are reachable from this page only through the Archived
        // tab, and the tab only exists while the setting is on. With it off
        // the address is as invented as any other.
        if ($refuse($status === Child::ARCHIVED && ! self::showsArchived())) {
            $status = $defaultStatus;
        }
        $wantsArchived = $status === Child::ARCHIVED;

        // The room chips, the pay chips and the "Records to finish" buttons
        // (Phase 2.1). An unknown value is refused for the reason a status is
        // — or, on the page, dropped.
        $room = trim((string) request('room'));
        if ($refuse(! ($room === '' || in_array($room, ClassroomAssignment::rooms(), true)))) {
            $room = '';
        }
        $pay = trim((string) request('pay'));
        if ($refuse(! in_array($pay, ['', 'dss', 'private'], true))) {
            $pay = '';
        }
        $gap = trim((string) request('gap'));
        if ($refuse(! ($gap === '' || array_key_exists($gap, Child::GAPS)))) {
            $gap = '';
        }

        $children = Child::query()
            ->visibleTo(request()->user())
            // Every tab but Archived leaves the archived out — All included.
            ->when(! $wantsArchived, fn ($query) => $query->notArchived())
            ->when($status !== '', fn ($query) => $query->inStatusTab($status))
            ->when($room !== '', fn ($query) => $query->where('classroom', $room))
            ->when($pay === 'dss', fn ($query) => $query->where(fn ($q) => $q->whereNotNull('dss_case_no')->where('dss_case_no', '!=', '')->orWhere(fn ($q) => $q->whereNotNull('dss_cin')->where('dss_cin', '!=', ''))))
            ->when($pay === 'private', fn ($query) => $query->where(fn ($q) => $q->whereNull('dss_case_no')->orWhere('dss_case_no', ''))->where(fn ($q) => $q->whereNull('dss_cin')->orWhere('dss_cin', '')))
            // Age is shown, not stored, so it sorts by the date it is worked out
            // from — the other way round, since the youngest child is the one
            // with the latest date of birth.
            ->when($sort === 'age', fn ($query) => $query->orderByRaw(
                'COALESCE(birth_date, dob) '.($direction === 'asc' ? 'desc' : 'asc')
            ))
            // The date itself, the other way up: earliest birthday first.
            ->when($sort === 'dob', fn ($query) => $query->orderByRaw('COALESCE(birth_date, dob) '.$direction))
            /*
             * Schedule sorts by when the day starts, then when it ends — the
             * earliest drop-off at the top — with children who have no hours
             * on record at the foot either way, because "unknown" is not an
             * hour and should not pass for one.
             */
            ->when($sort === 'schedule', fn ($query) => $query
                // Nothing on record at all — no hours, no days — at the foot either way.
                ->orderByRaw("CASE WHEN drop_off_time IS NULL AND (schedule_days IS NULL OR schedule_days = '' OR schedule_days = '[]' OR schedule_days = 'null') THEN 1 ELSE 0 END")
                // Hours before days alone, then by the hour.
                ->orderByRaw('CASE WHEN drop_off_time IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('drop_off_time '.$direction)
                ->orderByRaw('pick_up_time '.$direction)
                // Then the fuller week first: the days are a JSON list, and a
                // longer list is more days.
                ->orderByRaw('LENGTH(COALESCE(schedule_days, \'\')) '.($direction === 'asc' ? 'desc' : 'asc')))
            /*
             * Alerts sorts the children who have one to the top (ascending),
             * or to the foot (descending): the question the column answers is
             * "who needs a word before the day starts", and the roll should
             * be able to put those children together. Within each group, by
             * the alert text, so like alerts sit beside each other.
             */
            ->when($sort === 'alerts', fn ($query) => $query
                ->orderByRaw("CASE WHEN alerts IS NULL OR alerts = '' OR alerts = '[]' OR alerts = 'null' THEN 1 ELSE 0 END ".($direction === 'asc' ? 'asc' : 'desc'))
                ->orderByRaw('alerts '.$direction))
            /*
             * A LAN is stored as a string, because a centre's numbering can
             * carry a prefix and storing it as an integer would destroy one
             * that does. Sorted as a string it reads 1, 10, 100, 1001, 2 —
             * so it is cast for the sort alone. Anything non-numeric casts
             * to nought and falls to the top, which is where a record with a
             * malformed number should be.
             */
            ->when($sort === 'lan', fn ($query) => $query
                ->orderByRaw('CAST(lan AS UNSIGNED) '.$direction)
                ->orderBy('lan', $direction))
            // Rooms sort youngest first, the way the centre says them, not A to Z.
            ->when($sort === 'classroom', fn ($query) => $query->orderByRoom($direction)->orderBy('last_name')->orderBy('first_name'))
            ->when(! in_array($sort, ['age', 'dob', 'lan', 'classroom', 'schedule', 'alerts'], true), fn ($query) => $query->orderBy($sort, $direction))
            // The computed sorts settle ties by name, so the order is the same every load.
            ->when(in_array($sort, ['schedule', 'alerts'], true), fn ($query) => $query->orderBy('last_name')->orderBy('first_name'))
            ->when($sort === 'last_name', fn ($query) => $query->orderBy('first_name'))
            // The whole roll on one page. It used to page at ten, which put a
            // sixty-child centre six clicks from the child they were looking
            // for and broke the search box — it could only find children on the
            // page it was on. A roster is read by scrolling, like the sheet.
            ->get();

        // A gap is worked out from the people and the papers, not a column,
        // so it narrows the list after the query rather than in it.
        if ($gap !== '') {
            $children->load(['personLinks', 'documents']);
            $children = $children->filter(fn (Child $child) => array_key_exists($gap, $child->missingItems()))->values();
        }

        return [$children, $sort, $direction, $status, $room, $pay, $gap];
    }

    /**
     * The roll as a spreadsheet.
     *
     * Named for what it holds and when it was taken, because these end up in a
     * downloads folder beside last month's and the one before that.
     */
    public function export()
    {
        // No status here still means everybody: the page's Export button
        // names the tab it was pressed on (status=Active, status=all), so
        // only an address typed without one lands here (QA #5).
        [$children, , , $status] = $this->roster();

        $children = $children->values();

        // Everybody attached to the roll, in two queries rather than two per
        // child. Both sheets read these same links.
        $children->load(['personLinks.person']);

        $name = 'children-'.($status !== '' ? strtolower($status).'-' : '').today()->format('Y-m-d').'.xlsx';

        // Sent with headers that forbid the browser to keep it — see the
        // never-cached middleware on the route, which every export carries.
        // A teacher's file is their own rooms (the roster above) and the basic
        // columns: the office-only ones are left out (spec §4).
        return Excel::download(new ChildrenExport($children, request()->user()?->nameFormat(), (bool) request()->user()?->isAdmin()), $name);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('children.create', ['nextLan' => Child::nextLan()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, PeopleDirectory $people)
    {
        $data = $this->validatedData($request);

        /*
         * Added as Active with no enrolled date: they are enrolled from the
         * day they were put on the roll (QA #32, 12 Nov 2026). Left empty,
         * the record read "Enrolled date not set" and counted as less than
         * complete for a fact the save itself settles. Today in the centre's
         * time zone, so a record typed at 9pm is not dated tomorrow. A date
         * somebody gave is kept as given; Pending keeps its blank, which
         * means "no first day agreed yet".
         */
        if (($data['status'] ?? null) === 'Active' && blank($data['enrolled_on'] ?? null)) {
            $data['enrolled_on'] = now(config('app.timezone'))->toDateString();
        }

        $child = Child::create($data);

        $this->syncPhoto($request, $child);

        /*
         * The adults on the form become people, linked to this child.
         *
         * Without this a scanned enrollment filled the mother and father
         * columns and stopped there: the child's page showed two parents while
         * the People step showed nobody, and a mother already on file for an
         * older sibling was not recognised as the same woman.
         *
         * The same reading the migration does, so a form saved today and a
         * form read out of the old columns produce the same people. It matches
         * against everybody on file first, so a second child reuses the
         * parent's record rather than making a second one.
         */
        $fromDocument = filled($request->input('import_token'));

        $people->absorbContactBlocks($child, $fromDocument ? 'pdf_import' : 'manual');

        // A record a model read off handwriting is worth somebody's eye before
        // it is trusted. One typed in by hand has already had it.
        if ($fromDocument) {
            $child->forceFill(['import_status' => 'needs_review'])->save();
        }

        // The child is on file, so the imported document no longer needs keeping.
        ChildDocumentController::discard($request->input('import_token'));

        // More forms in the pile: straight on to the next one rather than
        // back to the roll and in again through the import screen.
        if ($fromDocument && ($next = ChildDocumentController::nextToken())) {
            $waiting = count(ChildDocumentController::pendingTokens());

            return redirect()->route('children.document-import.review', $next)
                ->with('success', $child->first_name.' '.$child->last_name.' added. '.$waiting.' form'.($waiting === 1 ? '' : 's').' still to review — next: '.ChildDocumentController::nextName().'.');
        }

        // Back to the tab the child is on: the list opens on Active now
        // (QA #5), and a Pending child added a moment ago must not vanish.
        return redirect()->route('children.index', ['status' => $child->status])->with('success', 'Child added successfully.')
            ->with('hours_warning', Child::hoursOutsideOpening($child->drop_off_time, $child->pick_up_time, $child->classroom));
    }

    /**
     * The child's record as a page rather than a form.
     *
     * Most of what is on file about a child is read far more often than it is
     * changed — a phone number at pick-up time, who is allowed to collect them,
     * the note about the allergy. The edit form holds all of it behind inputs
     * and is director-only; this is the same record readable by the teacher who
     * actually has the child in front of them.
     */
    public function show(Child $child, PeopleDirectory $people)
    {
        // The same rule the roster list is filtered by, applied to the one
        // record: a teacher may read the children in their own rooms and
        // nobody else's.
        abort_unless($this->isVisibleTo($child, request()->user()), 403);

        // What this reader has in front of them, for the stale-save check (RecordLock).
        \App\Services\RecordLock::seen($child);
        \App\Services\RecordLock::alertsSeen($child);

        $child->load(['personLinks.person', 'documents.uploadedBy', 'updatedBy']);

        return view('children.show', [
            'child' => $child,
            'roomSchedule' => RoomSchedule::byRoom()[$child->classroom] ?? null,
            'back' => $this->backFrom($child),
            // What happened to their place on the roll, newest first. Eager the
            // user, or the panel asks for each name one query at a time.
            'statusChanges' => $child->statusChanges()->with('changedBy')->get(),

            /*
             * The rest of the record (Phase 2.3): the teachers' notes from
             * check-in beside the office's own, this month's attendance, and
             * who the search box offers before "create a new person".
             */
            // All of them (QA F4): the page shows eight and "Show older" the
            // rest, so a cut-off here made the oldest notes unreachable and
            // the filter counts wrong. The cap is only a guard on a page size.
            'teacherComments' => \App\Models\AttendanceComment::with('author')->where('child_id', $child->id)->latest('created_at')->latest('id')->limit(1000)->get(),
            'officeNotes' => $child->notes()->with('author')->limit(1000)->get(),
            'month' => $this->attendanceMonth($child),
            'relationships' => PersonController::RELATIONSHIPS,

            /*
             * Everybody on this child's record, and the two lists drawn from
             * them.
             *
             * All three come from child_people, so the page can no longer
             * disagree with itself or with the People step. It used to read the
             * old mother_* and father_* columns, which nothing writes to any
             * more — a parent unlinked on the People step went on being shown
             * here, and a parent added there never appeared.
             *
             * The lists are queries rather than stored: the pick-up list is the
             * tick minus anyone under a restriction, worked out on every read,
             * so a court order typed a minute ago is on the screen the person
             * at the door is looking at.
             */
            'people' => $people->forChild($child),
            'pickups' => $people->pickupList($child),
            'emergencies' => $people->emergencyList($child),
        ]);
    }

    /**
     * This month on the register, for the Attendance card: each weekday as
     * in, absent, today, planned or closed, with the totals and the average
     * first-in and last-out. Absent is a day they were booked for that has
     * gone by with no sign-in — there is no absent switch until Phase 3.
     */
    private function attendanceMonth(Child $child): array
    {
        $today = Carbon::today();
        $first = $today->copy()->startOfMonth();
        $last = $today->copy()->endOfMonth();

        $rows = \App\Models\Attendance::where('child_id', $child->id)
            ->whereBetween('attendance_date', [$first->toDateString(), $last->toDateString()])
            ->whereNotNull('signed_in_at')
            ->get()
            ->groupBy(fn ($row) => $row->attendance_date->toDateString());

        $closed = \App\Models\ClosureDay::whereBetween('closed_on', [$first->toDateString(), $last->toDateString()])->pluck('closed_on')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

        $planned = \App\Models\ScheduleSlot::where('child_id', $child->id)->where('is_scheduled', true)
            ->whereBetween('slot_date', [$first->toDateString(), $last->toDateString()])
            ->pluck('slot_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->all();

        // The word on a day, where somebody put one: absent, or not scheduled
        // after all — the same marks Director Attendance and the DSS page set.
        $marks = \App\Models\DssDayMark::where('child_id', $child->id)
            ->whereBetween('marked_on', [$first->toDateString(), $last->toDateString()])
            ->get()->mapWithKeys(fn ($m) => [$m->marked_on->toDateString() => $m->mark])->all();

        $days = [];
        $in = 0;
        $absent = 0;
        $ins = [];
        $outs = [];

        for ($date = $first->copy(); $date->lte($last); $date->addDay()) {
            if ($date->isWeekend()) {
                continue;
            }

            $key = $date->toDateString();
            $booked = in_array($key, $planned, true) || ($planned === [] && $child->attendsOn($date));
            $enrolled = $child->isEnrolledOn($key);

            if (in_array($key, $closed, true)) {
                $state = 'closed';
            } elseif (isset($rows[$key])) {
                $state = $date->isSameDay($today) ? 'today' : 'in';
                $in++;
                $firstIn = $rows[$key]->min('signed_in_at');
                $lastOut = $rows[$key]->whereNotNull('signed_out_at')->max('signed_out_at');
                if ($firstIn) {
                    $ins[] = Carbon::parse($firstIn)->timezone(config('app.timezone'))->secondsSinceMidnight();
                }
                if ($lastOut) {
                    $outs[] = Carbon::parse($lastOut)->timezone(config('app.timezone'))->secondsSinceMidnight();
                }
            } elseif (($marks[$key] ?? null) === \App\Models\DssDayMark::ABSENT) {
                $state = 'absent';
                $absent++;
            } elseif ($date->isSameDay($today)) {
                $state = 'today';
            } elseif ($date->gt($today)) {
                $state = $booked && $enrolled ? 'planned' : 'none';
            } elseif ($booked && $enrolled && ($marks[$key] ?? null) !== \App\Models\DssDayMark::NONE) {
                $state = 'absent';
                $absent++;
            } else {
                $state = 'none';
            }

            $days[] = ['date' => $date->copy(), 'state' => $state, 'times' => isset($rows[$key])
                ? Child::timeShort($rows[$key]->min('signed_in_at')?->timezone(config('app.timezone')))
                : null];
        }

        $average = fn (array $seconds) => $seconds === [] ? null : Carbon::today()->addSeconds((int) round(array_sum($seconds) / count($seconds)))->format('g:ia');

        return [
            'label' => $today->format('F'),
            'days' => $days,
            'lead' => max(0, $first->dayOfWeekIso - 1),
            'in' => $in,
            'absent' => $absent,
            'avgIn' => $average($ins),
            'avgOut' => $average($outs),
        ];
    }

    /**
     * Where "Back" on a child's record goes.
     *
     * Wherever the reader came from, which is the only answer that is right
     * for all of them: the roster, the attendance sheet with a week on it, a
     * search. It used to be the roster by name, so anyone arriving from the
     * sheet pressed a button labelled Roster and lost the week they had open.
     *
     * Three things are refused, each of which would make Back do nothing
     * useful or something surprising:
     *
     *  - another site, because a Back button is not a way off this one;
     *  - this record itself, which is where a saved edit sends the reader, so
     *    Back would reload the page it is on;
     *  - this record's own edit form, for the same reason one step further —
     *    Back would return to the form that has just been left.
     */
    private function backFrom(Child $child): string
    {
        $previous = url()->previous();
        $roster = route('children.index');

        if (! $previous || ! str_starts_with($previous, url('/'))) {
            return $roster;
        }

        $mine = [route('children.show', $child), route('children.edit', $child)];

        // Where Back went last time this record was opened from elsewhere.
        // A save on one of the record's cards reloads the record, so the
        // referer becomes the record itself; without this the reader who
        // came from "Active · Toddler · by age" was sent to the bare roster
        // after every in-place save.
        $remembered = 'children.back.'.$child->getKey();

        foreach ($mine as $own) {
            if (str_starts_with(strtok($previous, '?'), $own)) {
                return session($remembered, $roster);
            }
        }

        session([$remembered => $previous]);

        return $previous;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Child $child)
    {
        // The same rule the roster is filtered by, asked about one record: a
        // teacher edits the children in their own rooms and nobody else's.
        abort_unless($this->isVisibleTo($child, request()->user()), 403);

        // The form shows an admin the last four of each parent's SSN on file (QA #34).
        if (\App\Services\SensitiveAccess::allowed()) {
            foreach (['mother_ssn' => 'mother', 'father_ssn' => 'father'] as $column => $who) {
                if (strlen((string) preg_replace('/\D+/', '', (string) $child->{$column})) >= 4) {
                    $name = trim($child->first_name.' '.$child->last_name);
                    \App\Services\SensitiveAccess::record(\App\Services\SensitiveAccess::VIEWED, "{$who}'s last four shown on the edit form of {$name}", $child, $name);
                }
            }
        }

        // The version the form is drawn from, for the stale-save check (RecordLock).
        \App\Services\RecordLock::seen($child);
        \App\Services\RecordLock::alertsSeen($child);

        return view('children.edit', compact('child'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Child $child)
    {
        abort_unless($this->isVisibleTo($child, $request->user()), 403);

        // Archived records are read-only until restored. The one save the
        // form may still make is the restore itself (status off Archived).
        if ($child->isArchived() && in_array($request->input('status'), [null, '', Child::ARCHIVED], true)) {
            return redirect()->route('children.show', $child)->withErrors(['archived' => 'This record is archived and read-only until restored.']);
        }

        $before = $child->scheduleDays();

        $data = $this->validatedData($request, $child);

        /*
         * A form drawn before somebody else saved this record would write
         * every field back as it was then — their phone fix, their case
         * number, an alert added from the record meanwhile (QA J3, E5). It is
         * refused instead, nothing written, and the form is drawn again from
         * the record as it is now.
         */
        if (\App\Services\RecordLock::isStale($request, $child)) {
            return redirect()->route('children.edit', $child)->with('error', \App\Services\RecordLock::message($child));
        }

        // The last day and why are the director's, set through Withdraw: a
        // teacher's form leaves them as they are.
        if (! $request->user()?->isAdmin()) {
            unset($data['withdrawn_on'], $data['withdrawal_reason'], $data['withdrawal_note'], $data['could_return']);
        }

        /*
         * Archiving from the form is the same act as the Archive button on
         * the roll, so it leaves the same trail — when, who, and what they
         * were — and coming back out of Archived clears it. Done here rather
         * than in a model hook because the hook would also fire on an import
         * that happens to carry the word, which is not somebody archiving.
         */
        $archiving = ($data['status'] ?? null) === Child::ARCHIVED && ! $child->isArchived();
        $restoring = $child->isArchived() && ($data['status'] ?? null) !== Child::ARCHIVED;

        if ($archiving) {
            $child->forceFill(['status_before_archive' => $child->status, 'archived_at' => now(), 'archived_by' => $request->user()?->id]);
        } elseif ($restoring) {
            $child->forceFill(['status_before_archive' => null, 'archived_at' => null, 'archived_by' => null]);
        }

        $child->fill($data)->save();

        $this->syncPhoto($request, $child);
        \App\Services\RecordLock::seen($child);
        if (array_key_exists('alerts', $data)) {
            \App\Services\RecordLock::alertsSeen($child);
        }

        /*
         * The days they come, brought through to the weeks that have not
         * happened yet.
         *
         * A week takes its ticks when it is opened, so without this a child
         * registered for every day after the week was opened reads on the
         * sheet as attending none of them — a dot against a day they are
         * booked for. Finished weeks are left alone: those are a record of
         * what happened, and editing a profile in October must not rewrite
         * September.
         */
        $message = 'Child details updated successfully.';

        if ($child->scheduleDays() !== $before) {
            $moved = app(WeekSchedule::class)->resyncRegisteredDays($child);

            if ($moved > 0) {
                $message .= ' '.$moved.' '.\Illuminate\Support\Str::plural('day', $moved)
                    .' on the attendance sheet updated to match.';
            }
        }

        // Inside the hard bounds but outside when the centre or room is open:
        // saved, and said — the same words the Overview card uses.
        return redirect()->route('children.index', ['status' => $child->status])->with('success', $message)
            ->with('hours_warning', Child::hoursOutsideOpening($child->drop_off_time, $child->pick_up_time, $child->classroom));
    }

    /**
     * The child's photograph, streamed to whoever may already see the child.
     *
     * The file sits on the private disk, so this route is the only way to it —
     * which is the point. A staff member's avatar is theirs to publish and goes
     * on the public disk; a photograph of somebody else's four-year-old is not
     * a thing to leave on a guessable URL.
     */
    public function photo(Child $child)
    {
        // Any signed-in staff member, not only one assigned to the child's
        // room: the door screen shows the whole floor to whoever is at it,
        // and a face there has to load for the teacher covering another
        // room. The record itself (show, edit) keeps the room check; this
        // route is under auth, so nobody outside the building reaches it.
        abort_unless(request()->user()?->isAdmin() || request()->user()?->role === 'teacher', 403);
        abort_if(blank($child->photo_path) || ! Storage::disk('local')->exists($child->photo_path), 404);

        return Storage::disk('local')->response($child->photo_path, null, [
            // Private, so a shared cache never holds it: it is one person's
            // photograph, served on the strength of who asked for it. The hour
            // is safe because photoUrl() stamps the address with the file, so
            // a replaced photo is fetched from a new address rather than
            // found in the cache under the old one.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Saves an uploaded photograph, or drops the one on file when the form asks.
     *
     * Both cases delete what was there first — a replaced photo left on disk is
     * a picture of a child nothing in the system points at any more.
     */
    private function syncPhoto(Request $request, Child $child): void
    {
        if ($request->hasFile('photo')) {
            $this->deletePhoto($child);

            $child->update(['photo_path' => $request->file('photo')->store('children', 'local')]);

            return;
        }

        if ($request->boolean('remove_photo')) {
            $this->deletePhoto($child);

            $child->update(['photo_path' => null]);
        }
    }

    private function deletePhoto(Child $child): void
    {
        if (filled($child->photo_path)) {
            Storage::disk('local')->delete($child->photo_path);
        }
    }

    /** The rule the roster list is filtered by, asked about one child. */
    private function isVisibleTo(Child $child, ?User $user): bool
    {
        return $user !== null
            && Child::whereKey($child->getKey())->visibleTo($user)->exists();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Child $child)
    {
        //
    }

    private function validatedData(Request $request, ?Child $child = null): array
    {
        if ($request->filled('birth_date')) {
            $request->merge(['birth_date' => $this->normalizeDate($request->input('birth_date'))]);
        }

        // <input type="time"> posts H:i, but a value read back out of MySQL is
        // H:i:s and a browser with the seconds step set posts that too. Both are
        // cut down to H:i here so the rules below only ever see one shape.
        foreach (['drop_off_time', 'pick_up_time'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => $this->normalizeTime($request->input($field))]);
            }
        }

        // The hidden empty input in front of the day boxes keeps "cleared" and
        // "untouched" apart, but it also means the array arrives with a stray
        // "" in it whenever anything is ticked. Strip it here so the rules and
        // the column only ever see weekday numbers.
        if (is_array($request->input('schedule_days'))) {
            $request->merge(['schedule_days' => array_values(array_filter(
                $request->input('schedule_days'),
                fn ($day) => $day !== '' && $day !== null
            ))]);
        }
        $rules = [
            'child_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'dob' => ['nullable', 'date'],
            // The room is worked out from the date of birth. What the director
            // sets here is the departure from it, not the room itself.
            'classroom_override' => ['nullable', 'string', Rule::in(ClassroomAssignment::rooms())],
            // Blank means today, the same as it does on the schedule page. It is
            // not a second thing to fill in before a room can be picked.
            'classroom_override_from' => ['nullable', 'date_format:Y-m-d'],
            // Active, Pending, Inactive or Archived — the list lives on the
            // model, so the form's options and what the form will accept
            // cannot drift apart. Archived is the director's alone: a teacher
            // may mark a child in their room as having left, not take them
            // off every list the centre keeps.
            //
            // Since Phase 2.2 the lifecycle (start, cancel, withdraw,
            // re-enroll, archive) is the director's, from the record's status
            // pill, so a teacher's form keeps the status the child already has.
            'status' => ['required', Rule::in($request->user()?->isAdmin() ? Child::STATUSES : ($child ? [$child->status] : Child::ROLL_STATUSES))],

            // What a room has to know before the day starts. A kind and a line
            // apiece; the kind is what colours the chip on the roll.
            'alerts' => ['nullable', 'array', 'max:12'],
            'alerts.*.type' => ['required', Rule::in(array_keys(Child::ALERT_TYPES))],
            'alerts.*.text' => ['nullable', 'string', 'max:120'],
            // What the record page adds to an alert (Phase 2.3): kept through
            // a save from this form rather than silently dropped by it.
            'alerts.*.severity' => ['nullable', Rule::in(['mild', 'moderate', 'severe'])],
            'alerts.*.reaction' => ['nullable', 'string', 'max:200'],
            'alerts.*.action' => ['nullable', 'string', 'max:200'],
            'alerts.*.plan_on' => ['nullable', 'date'],
            'enrolled_on' => ['nullable', 'date'],
            'withdrawn_on' => ['nullable', 'date', 'after_or_equal:enrolled_on'],
            // What the parent contracted for. Blank means nobody has said, and
            // the projection then reports the days without claiming a target;
            // zero is the deliberate "not coming" and suppresses it.
            // The days the parent signed up for. The form posts a hidden empty
            // value in front of the boxes so that clearing every day arrives as
            // "no days" rather than as nothing at all — otherwise unticking the
            // last box would be indistinguishable from never touching the field.
            'schedule_days' => ['nullable', 'array'],
            'schedule_days.*' => [Rule::in(array_keys(Child::WEEKDAYS))],
            'expected_hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:168'],
            // The hours of the day the child is here, inside the hours the
            // centre is open. Either end may stand alone while the other is
            // still being agreed, so neither requires the other.
            // One copy of the rule, shared with the Overview card.
            ...Child::scheduleTimeRules(),
            // Normalised to Y-m-d above when it is a real date; anything else
            // arrives here as typed and is refused by name (QA #27).
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->addYear()->toDateString()],
            // Blank stays a real answer: only the drawn stand-in face reads
            // this, and a record that does not say is drawn as one that does
            // not say rather than being guessed at from the name.
            'gender' => ['nullable', Rule::in(Child::GENDERS)],
            // Two letters from the list; Parents status stays free text (see
            // Child::PARENTS_STATUSES) so an imported word is not refused.
            'state' => ['nullable', Rule::in(array_keys(Child::STATES))],
            // The same shapes the Overview card checks (QA C9) — when the
            // number is being typed, so an older record carrying a number in
            // another shape still saves its other fields.
            'dss_case_no' => ['nullable', 'string', 'max:40', ...(($child && trim((string) $request->input('dss_case_no')) === (string) $child->dss_case_no) ? [] : ['regex:'.Child::DSS_CASE_NO_PATTERN])],
            'dss_cin' => ['nullable', 'string', 'max:40', ...(($child && trim((string) $request->input('dss_cin')) === (string) $child->dss_cin) ? [] : ['regex:'.Child::DSS_CIN_PATTERN])],
            // A few words on how the child looks, as the centre's sheet keeps it.
            'description' => ['nullable', 'string', 'max:255'],
            'other_notes' => ['nullable', 'string'],
            'important_notes' => ['nullable', 'string'],
            // Validated here so a bad upload is reported with the rest of the
            // form; the file itself is stored by syncPhoto once the record
            // exists, since a new child has no id to hang a file on yet.
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];

        foreach ($this->enrollmentFields() as $field) {
            $rules[$field] ??= ['nullable', 'string', 'max:255'];
        }

        /*
         * Every refusal at once (QA, Nov 2026). The duplicate check used to
         * run only after the field rules and the Active check had passed, so
         * a new child saved as Active without a guardian was refused for that
         * alone and the "already on file" warning never showed — five Jace
         * Millers went in that way. Now the field rules, the Active check and
         * the duplicate check each run, and whatever they find is sent back
         * together.
         */
        $errors = [];

        try {
            $data = $request->validate($rules, [
                'photo.max' => 'The photo may not be larger than 4 MB.',
                'birth_date.date_format' => 'That is not a real date. Type the date of birth as mm/dd/yyyy, for example 02/14/2024.',
                'birth_date.before_or_equal' => 'That date of birth is more than a year from now. Check the year.',
                'photo.image' => 'The photo must be an image file.',
            ] + Child::scheduleTimeMessages() + Child::dssMessages());

            try {
                $this->ensureCanBeActive($data, $child);
            } catch (\Illuminate\Validation\ValidationException $refused) {
                $errors = $refused->errors();
            }
        } catch (\Illuminate\Validation\ValidationException $refused) {
            $errors = $refused->errors();
            $data = null;
        }

        if ($duplicate = $this->duplicateError($request, $data, $child, $errors)) {
            $errors['duplicate'] = [$duplicate];
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        /*
         * The same child twice.
         *
         * A scanned form imported a second time, or typed in by somebody who
         * did not find the first record, makes a second child with a second
         * LAN — and two rows on the register for one child, which the roll
         * count, the export and the billing all get wrong. So a new record
         * whose name is already on file is refused, naming the record it
         * matches, unless the person says this really is a different child
         * (two Ava Smiths do happen) — "Continue anyway" on the form. A
         * birth date on both that differs is taken as that answer already.
         * Editing a record is never a duplicate of itself.
         *
         * The names are compared without case or spaces ("Mary Ann" is
         * "maryann"), and the birthday is read from either date column — a
         * record that carries only birth_date was never matched before.
         * confirm_duplicate is the form's "Continue anyway"; allow_duplicate
         * is the older name for it and still honoured. See duplicateError,
         * which runs alongside the other checks above.
         */
        // The upload is not a column. syncPhoto puts the stored path in.
        unset($data['photo']);

        // A parent's SSN (QA #34): only a director or the office may write
        // one, and the edit form never holds the number on file — its box is
        // empty — so an empty box means "leave it", not "clear it".
        foreach (['mother_ssn', 'father_ssn'] as $ssn) {
            if (! \App\Services\SensitiveAccess::allowed() || ($child !== null && blank($data[$ssn] ?? null))) {
                unset($data[$ssn]);
            }
        }

        // The alerts arrive as the form drew them, which includes any row
        // somebody opened and then left empty. A chip reading "Allergy:" with
        // nothing after it says a question was answered when it was not, so
        // the blank ones are dropped here rather than filtered on every read.
        //
        // The key is only absent when the form did not carry the field at all;
        // an empty list is somebody clearing the last one, and has to be kept
        // apart from that.
        if (array_key_exists('alerts', $data)) {
            $data['alerts'] = array_values(array_filter(
                array_map(fn ($alert) => array_filter([
                    'type' => $alert['type'],
                    'text' => trim((string) ($alert['text'] ?? '')),
                    'severity' => $alert['severity'] ?? null,
                    'reaction' => $alert['reaction'] ?? null,
                    'action' => $alert['action'] ?? null,
                    'plan_on' => $alert['plan_on'] ?? null,
                ], fn ($value, $key) => in_array($key, ['type', 'text'], true) || filled($value), ARRAY_FILTER_USE_BOTH), $data['alerts'] ?? []),
                fn ($alert) => $alert['text'] !== ''
            ));
        }

        // The LAN is issued here, not accepted from the form. On an existing
        // record it is the one it already has: a LAN is how the paper file
        // names this child, so changing it silently renames them everywhere
        // off-screen. The field is read-only, and this is what makes that true
        // rather than merely apparent — a read-only input is a hint to a
        // browser, not a rule.
        $data['lan'] = $child?->lan ?? Child::nextLan();

        // One date, two columns behind it: `dob` from the original roster and
        // `birth_date` from the enrolment form. The form edits one field, so
        // both are written from it — otherwise the reader that happens to look
        // at the other column keeps showing the date that was corrected.
        //
        // Nobody types an age any more either; every screen reads the date off
        // the date of birth. The column is still written so the spreadsheet
        // import and the records that came in through it agree with what is
        // displayed.
        if (array_key_exists('birth_date', $data)) {
            $data['dob'] = $data['birth_date'];
            $data['age'] = Child::ageLabelFor(
                filled($data['birth_date']) ? Carbon::parse($data['birth_date']) : null
            );
        }

        // Clearing the room hands the child back to the age rule, so the date it
        // started from goes with it.
        $data['classroom_override'] = $data['classroom_override'] ?? null;
        $data['classroom_override_from'] = blank($data['classroom_override'])
            ? null
            : ($data['classroom_override_from'] ?? today()->toDateString());

        return $data;
    }

    /**
     * The duplicate warning for a save, or null. Run beside the field rules
     * and the Active check rather than after them, so it is shown even when
     * those refuse the save too. $data is null when the field rules failed;
     * the name and birthday are then read off the request, as long as those
     * fields themselves passed.
     *
     * An edit is checked too, but only when it changes the name or the
     * birthday — so a record that already shares a name is not warned on
     * every save, and never matches itself (QA retest, 16 Nov 2026).
     */
    private function duplicateError(Request $request, ?array $data, ?Child $child, array $errors): ?string
    {
        if ($request->boolean('confirm_duplicate') || $request->boolean('allow_duplicate')) {
            return null;
        }

        if ($data === null) {
            if (array_intersect(['first_name', 'last_name', 'birth_date'], array_keys($errors)) !== []) {
                return null;
            }

            $data = $request->only(['first_name', 'last_name', 'birth_date']);
        }

        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));

        $identityChanged = $child === null
            || mb_strtolower(trim((string) $child->first_name)) !== mb_strtolower($first)
            || mb_strtolower(trim((string) $child->last_name)) !== mb_strtolower($last)
            || (array_key_exists('birth_date', $data) && (string) $child->birthDate()?->toDateString() !== (string) ($data['birth_date'] ?? ''));

        if (! $identityChanged) {
            return null;
        }

        $born = filled($data['birth_date'] ?? null) ? (string) $data['birth_date'] : null;
        $same = Child::findDuplicate($first, $last, $born, $child?->getKey());

        if (! $same) {
            return null;
        }

        session()->flash('duplicate_of', $same->getKey());

        return $same->duplicateSummary($born).' Open that record, or press "Continue anyway" if this is a different child.';
    }

    /**
     * The Add and Edit forms' live check (QA, Nov 2026): the children on
     * file with this name and birthday, in every status, as the form types
     * them. The same match the save refuses on — Child::findDuplicates — so
     * what the form warns about is what the save would hold back. At most
     * five are listed; `count` says how many there are in all.
     */
    public function duplicateCheck(Request $request)
    {
        $text = fn (string $key) => is_string($value = $request->query($key)) ? $value : null;
        $born = filled($text('birth_date')) ? $this->normalizeDate($text('birth_date')) : null;

        // Only a whole, real date is matched on: half a date typed so far
        // would otherwise match every child of that name.
        if ($born !== null && ! (($date = \DateTime::createFromFormat('!Y-m-d', $born)) && $date->format('Y-m-d') === $born)) {
            return response()->json(['count' => 0, 'matches' => []]);
        }

        $except = $request->integer('except') ?: null;
        $query = Child::duplicatesQuery($text('first_name'), $text('last_name'), $born, $except);

        if ($query === null) {
            return response()->json(['count' => 0, 'matches' => []]);
        }

        $count = (clone $query)->count();

        return response()->json([
            'count' => $count,
            'matches' => $query->limit(5)->get()->map(fn (Child $match) => [
                'id' => $match->getKey(),
                'lan' => $match->lan,
                'name' => trim($match->first_name.' '.$match->last_name),
                'status' => Child::statusLabel($match->status),
                'room' => $match->classroom ?: null,
                'born' => $match->birthDate()?->format('m/d/Y'),
                'url' => route('children.show', $match),
            ])->values(),
        ]);
    }

    /**
     * Active needs a date of birth, a guardian and an emergency contact
     * (tester report, Nov 2026): a child on the roll with nobody to ring is
     * not a record the room can work from. Asked only when a save makes the
     * child Active — added as Active, or moved to it from another status —
     * so an ordinary edit to a child already Active but incomplete still
     * saves; "Records to finish" chases those. Pending saves with less.
     *
     * A new child has no people yet: its guardians and emergency contacts
     * are the names on the Add form, which become people once it is saved.
     */
    private function ensureCanBeActive(array $data, ?Child $child): void
    {
        if (($data['status'] ?? null) !== 'Active' || $child?->status === 'Active') {
            return;
        }

        $missing = $child
            ? $child->loadMissing('personLinks')->missingForActive(array_key_exists('birth_date', $data) ? ['birth_date' => filled($data['birth_date'])] : [])
            : Child::missingForActiveOnAdd($data);

        if ($missing === []) {
            return;
        }

        $message = Child::activeRequirementMessage($missing, $data['first_name'] ?? $child?->first_name)
            .($child ? '' : ' Or save as Pending until then.');

        // The status error is the one the save is refused for; the field
        // errors mark the tabs that hold what is missing.
        $fieldFor = ['birth_date' => 'birth_date', 'guardian' => 'mother_name', 'emergency' => 'emergency_contact'];
        $fieldMessage = ['birth_date' => 'Add a date of birth — needed to mark this child Active.', 'guardian' => 'Add a parent or guardian — needed to mark this child Active.', 'emergency' => 'Add an emergency contact — needed to mark this child Active.'];

        $messages = ['status' => $message, 'activation' => $message];
        foreach (array_keys($missing) as $key) {
            if ($child === null || $key === 'birth_date') {
                $messages[$fieldFor[$key]] = $fieldMessage[$key];
            }
        }

        throw \Illuminate\Validation\ValidationException::withMessages($messages);
    }

    private function normalizeDate(?string $value): ?string
    {
        if (blank($value)) return $value;

        /*
         * Strictly (QA #27, 12 Nov 2026). PHP rolls an impossible date over —
         * month 13, day 45 of 2023 became 02/14/2024 — and a last-resort
         * free parse took anything at all. So a format only counts when the
         * date it reads is a real one, US order is tried before day-first
         * (06/07/2023 is June 7 here), and text no format reads is handed
         * back as it was for the date_format rule to refuse with a message.
         */
        foreach (['Y-m-d', 'm/d/Y', 'm-d-Y', 'd/m/Y', 'd-m-Y', 'F j Y', 'F j, Y', 'M j Y', 'M j, Y', 'j F Y', 'j M Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, trim($value));
            $problems = \DateTime::getLastErrors();
            if ($date !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return $value;
    }

    /**
     * A posted time as H:i. Anything unparseable is handed back untouched so
     * the date_format rule rejects it and the field reports its own error,
     * rather than being quietly turned into a time nobody typed.
     */
    private function normalizeTime(?string $value): ?string
    {
        if (blank($value)) return $value;
        try {
            return Carbon::parse(trim($value))->format('H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function enrollmentFields(): array
    {
        $fields = ['dss_case_no','dss_cin','nickname','address','city','state','zip','telephone','mother_name','mother_address','mother_home_phone','mother_employer','mother_work_phone','mother_fax','mother_cell','mother_email','mother_title','mother_ssn','father_name','father_address','father_home_phone','father_employer','father_work_phone','father_fax','father_cell','father_email','father_title','father_ssn','email_address','parents_status','responsible_for_payment','emergency_contact','secondary_emergency_contact','emergency_telephone','emergency_relationship','emergency_license_number'];
        for ($number = 1; $number <= 3; $number++) foreach (['name','address','telephone','alternate','relationship','license_number'] as $field) $fields[] = "pickup_{$number}_{$field}";
        return $fields;
    }

}
