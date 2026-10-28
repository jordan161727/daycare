<?php
use App\Http\Controllers\AssistanceController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\ChildImportController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ChildrenController;
use App\Http\Controllers\AttendanceCommentController;
use App\Http\Controllers\ChildCommentController;
use App\Http\Controllers\CommentLogController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ChildController;
use App\Http\Controllers\ChildArchiveController;
use App\Http\Controllers\ChildRecordController;
use App\Http\Controllers\ChildStatusController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ChildDocumentController;
use App\Http\Controllers\FloorPlanController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\RoomTabletController;
use App\Http\Controllers\StaffPresenceController;
use App\Http\Controllers\DssController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MonthSheetController;
use App\Http\Controllers\LeaveBalanceController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\TimeClockController;
use App\Http\Controllers\TimePunchController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\StaffCardController;
use App\Http\Controllers\StaffClockController;
use App\Http\Controllers\StaffDeviceController;
use App\Http\Controllers\StaffReportController;
use App\Http\Controllers\StaffTimesheetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoomScheduleController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffRuleController;
use App\Http\Controllers\StaffScheduleController;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;

Route::redirect('/', '/dashboard');

// Screens that stay open all day (the attendance board, the import form) top up
// their CSRF token from here so a submit hours later is not met with "Page
// Expired". Reachable while signed out too, so the login form can do the same.
Route::get('/csrf-token', fn () => response()->json(['token' => csrf_token()]))->name('csrf.token');

/*
 * Help paying for day care, for parents.
 *
 * Signed out, and not because there is nobody to log in — because the people
 * these are for do not have accounts here at all. A family finds them from the
 * centre's website or a link a teacher texted them, reads a page and leaves.
 * Nothing is submitted, nothing is stored, so there is no limiter: these are
 * two static pages, and the only thing an unlimited GET costs is the render.
 */
Route::get('/child-care-assistance', [AssistanceController::class, 'index'])->name('assistance.index');
Route::get('/child-care-assistance/apply', [AssistanceController::class, 'apply'])->name('assistance.apply');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});


/*
 * The staff time clock on the wall, beside the door kiosk and signed out for
 * the same reason: there is nobody to log in at a screen twelve people share.
 * The card or the PIN is what authenticates, checked on every press, and the
 * device has to have been paired before it takes any.
 *
 * Same rate limiter as the door — these are the two signed-out endpoints in
 * the app, and four digits is four digits.
 */
Route::middleware('throttle:kiosk')->group(function () {
    Route::get('/clock', [StaffClockController::class, 'index'])->name('clock.kiosk');
    Route::post('/clock/punch', [StaffClockController::class, 'punch'])->name('clock.kiosk.punch');

    // The one press here that checks a secret, and so the one that can be
    // guessed at — held to its own tight budget. See the kiosk limiters.
    Route::post('/clock/identify', [StaffClockController::class, 'identify'])
        ->middleware('throttle:kiosk-secret')->name('clock.kiosk.identify');
});

/*
 * The room tablets (HANDOFF §5.3). Paired like the clock, keyed with the
 * same PIN, and signed out for the same reason. What they record is which
 * room somebody is in, for the ratios; they never clock anyone out.
 *
 * Two presses check a secret — the PIN, and the four-digit room code behind
 * the gear — and both sit on the tight limiter.
 */
Route::middleware('throttle:kiosk')->group(function () {
    Route::get('/room', [RoomTabletController::class, 'index'])->name('room.kiosk');
    Route::post('/room/punch', [RoomTabletController::class, 'punch'])->name('room.kiosk.punch');
    Route::post('/room/identify', [RoomTabletController::class, 'identify'])
        ->middleware('throttle:kiosk-secret')->name('room.kiosk.identify');
    Route::post('/room/setup', [RoomTabletController::class, 'setup'])
        ->middleware('throttle:kiosk-secret')->name('room.kiosk.setup');
});

/*
 * Messages (Phase 9), the two ends that face the outside world. A photo or
 * video a parent was sent: the link in the text, signed and temporary, so
 * it needs no login and does not live for ever. And the provider's webhook
 * for replies, checked by the provider's signature when a real driver is
 * on; CSRF is excused for it in bootstrap/app.php.
 */
Route::middleware('throttle:kiosk')->group(function () {
    Route::get('/messages/media', [MessageController::class, 'media'])->name('messages.media');
    Route::post('/sms/inbound', [MessageController::class, 'inbound'])->name('sms.inbound');
});

/*
 * The door kiosk. Outside the auth group on purpose — there is nobody to log in
 * at a door, and a staff login left open on a lobby tablet would be worse than
 * none. The guardian's PIN is what authenticates, checked on every press.
 *
 * Rate limited for the same reason as the clock above: the row lockout stops
 * somebody working through one family's PIN, and this stops them working
 * through every six-digit number.
 */
Route::middleware('throttle:kiosk')->group(function () {
    Route::get('/kiosk', [KioskController::class, 'index'])->name('kiosk.index');
    Route::post('/kiosk/punch', [KioskController::class, 'punch'])->name('kiosk.punch');

    // The guardian's PIN — the guessable one. Its own budget, so a busy door
    // cannot spend the staff clock's and neither can spend the screens'.
    Route::post('/kiosk/unlock', [KioskController::class, 'unlock'])
        ->middleware('throttle:kiosk-secret')->name('kiosk.unlock');
    Route::post('/kiosk/lock', [KioskController::class, 'lock'])->name('kiosk.lock');
});

Route::middleware(['auth', 'password.change'])->group(function () {

// Choosing your own password. Exempt from the middleware above (see its
// allow-list), because it is the one screen a brand-new account can reach.
Route::get('/password/change', [PasswordController::class, 'edit'])->name('password.change');
Route::put('/password/change', [PasswordController::class, 'update'])->name('password.change.update');

Route::get('/dashboard', function () {
    // The next few days the centre is shut. A teacher cannot open the holidays
    // page — that is the director's — so the dashboard is where they find out,
    // which is the whole reason it is on the screen everybody opens each
    // morning. Guarded like the counts below: a database mid-migration has no
    // closures table, and a dashboard is a poor place to discover that.
    $upcomingClosures = rescue(fn () => ClosureDay::where('closed_on', '>=', today()->toDateString())
        ->orderBy('closed_on')
        ->take(4)
        ->get(), collect(), false);

    if (! Schema::hasTable('children') || ! Schema::hasTable('attendances')) {
        return view('dashboard.index', [
            'snapshot' => ['now' => now(), 'enrolled' => 0, 'scheduled' => 0, 'in' => 0, 'returning' => 0, 'out' => 0, 'expected' => 0, 'absent' => 0, 'arrived' => 0, 'staffOnFloor' => 0, 'ratios' => [], 'states' => []],
            'recentAttendance' => collect(),
            'upcomingClosures' => $upcomingClosures,
        ]);
    }

    $user = request()->user();

    // One roll for both counts below: Active, and enrolled as of today — a
    // child who starts next Monday or left last Friday is on the books but
    // not on today's floor. Scoped to the reader's rooms, as the rest of the
    // dashboard is; for the director that is the whole floor.
    $today = today()->toDateString();
    $roll = Child::visibleTo($user)->where('status', 'Active')->get()
        ->filter(fn (Child $child) => $child->isEnrolledOn($today));
    $totalChildren = $roll->count();

    // The day as it stands — every number read from the screens that wrote
    // it. See App\Services\DashboardSnapshot.
    $snapshot = app(\App\Services\DashboardSnapshot::class)->for($user);

    $recentAttendance = Attendance::with('child')->whereDate('attendance_date', $today)
        ->whereIn('child_id', $roll->pluck('id'))
        ->whereNotNull('signed_in_at')
        ->latest('signed_in_at')->take(5)->get();

    return view('dashboard.index', compact('snapshot', 'recentAttendance', 'upcomingClosures'));
})->name('dashboard');


Route::get('/children', [ChildController::class, 'index'])->name('children.index');

// The roll as a spreadsheet. Same door as the roll itself — whoever may read
// the list on screen may take it away — and the same filter, sort and
// visibility, because it is built from the same query.
Route::get('/children/export', [ChildController::class, 'export'])->middleware('never-cached')->name('children.export');

// A child's record, readable by whoever may see the child on the roster — the
// director, and the teacher whose room they are in. Numbers only, or this would
// swallow /children/create and the import routes below it.
Route::get('/children/{child}', [ChildController::class, 'show'])->name('children.show')->whereNumber('child');

// The child's photograph. On the private disk and served through here, so the
// same people who may open the record are the only ones who may see the face.
Route::get('/children/{child}/photo', [ChildController::class, 'photo'])->name('children.photo')->whereNumber('child');
// A staff member's photograph, streamed the same way. Through a route rather
// than public/storage, which is a symlink the host has to allow and the
// staging server did not.
Route::get('/avatars/{user}', [AvatarController::class, 'show'])->name('avatars.show')->whereNumber('user');

Route::middleware('role:admin')->group(function () {
Route::get('/children/import-document', [ChildDocumentController::class, 'create'])->name('children.document-import.create');
Route::post('/children/import-document', [ChildDocumentController::class, 'store'])->name('children.document-import.store');
Route::get('/children/import-document/{token}/review', [ChildDocumentController::class, 'review'])->name('children.document-import.review');
Route::get('/children/import-document/{token}/file', [ChildDocumentController::class, 'file'])->name('children.document-import.file');
Route::post('/children/import-document/{token}/skip', [ChildDocumentController::class, 'skip'])->name('children.document-import.skip');
Route::post('/children/import-document/clear', [ChildDocumentController::class, 'clear'])->name('children.document-import.clear');
Route::resource('teachers', TeacherController::class)->parameters(['teachers' => 'teacher']);

// The standing hours of each room and who has it. Read by the children's
// records, which is why it is set here rather than per week.
Route::get('/room-schedule', [RoomScheduleController::class, 'index'])->name('room-schedule.index');
Route::put('/room-schedule', [RoomScheduleController::class, 'update'])->name('room-schedule.update');

// The days the centre is shut, set once and ahead of time. A closure is true of
// the whole centre, so every room's attendance schedule reads it rather than
// being greyed out one at a time — including weeks not built yet, which pick it
// up when they are opened.
Route::get('/holidays', [HolidayController::class, 'index'])->name('holidays.index');
Route::post('/holidays', [HolidayController::class, 'store'])->name('holidays.store');
Route::put('/holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');

// Holidays that come back every year. The rule is entered once; it writes
// itself out as ordinary closures years ahead, so nothing downstream needs to
// know the difference.
Route::post('/holidays/rules', [HolidayController::class, 'storeRule'])->name('holidays.rules.store');
Route::put('/holidays/rules/{rule}', [HolidayController::class, 'updateRule'])->name('holidays.rules.update');
Route::delete('/holidays/rules/{rule}', [HolidayController::class, 'destroyRule'])->name('holidays.rules.destroy');

// The Staff page (Phase 5): everyone on the team down the left, one person
// on the right under Details, Rules, Attendance and Time off. The older
// roster at /teachers stays where it is; this is the page the sidebar opens.
Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
Route::get('/staff/export', [StaffController::class, 'export'])->middleware('never-cached')->name('staff.export');
Route::get('/staff/{staff}', [StaffController::class, 'show'])->name('staff.show')->whereNumber('staff');
Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update')->whereNumber('staff');
Route::post('/staff/{staff}/password', [StaffController::class, 'sendPassword'])->name('staff.password')->whereNumber('staff');
Route::get('/staff/{staff}/attendance.csv', [StaffController::class, 'attendanceExport'])->middleware('never-cached')->name('staff.attendance.export')->whereNumber('staff');
Route::post('/staff/{staff}/time-off', [StaffController::class, 'storeTimeOff'])->name('staff.time-off.store')->whereNumber('staff');
Route::put('/staff/{staff}/time-off/{timeOff}', [StaffController::class, 'updateTimeOff'])->name('staff.time-off.update')->whereNumber('staff');
Route::delete('/staff/{staff}/time-off/{timeOff}', [StaffController::class, 'destroyTimeOff'])->name('staff.time-off.destroy')->whereNumber('staff');

/*
 * Editing the Schedule (Phase 7). Reading it is open to teachers below;
 * changing a shift, publishing the week and marking somebody off from a bar
 * are the director's — a roster anyone could drag about is not a plan.
 */
Route::post('/staff-schedule/publish', [StaffScheduleController::class, 'publish'])->name('staff-schedule.publish');
Route::get('/staff-schedule/day', [StaffScheduleController::class, 'day'])->name('staff-schedule.day');
Route::post('/staff-schedule/preview', [StaffScheduleController::class, 'preview'])->name('staff-schedule.preview');
Route::post('/staff-schedule/apply', [StaffScheduleController::class, 'apply'])->name('staff-schedule.apply');
Route::post('/staff-schedule/time-off', [StaffScheduleController::class, 'timeOff'])->name('staff-schedule.time-off');
Route::get('/staff-schedule/print', [StaffScheduleController::class, 'print'])->name('staff-schedule.print');

// Scheduling rules only ever make sense against the person they constrain, so
// they are nested rather than given a table of their own.
Route::post('/teachers/{teacher}/rules', [StaffRuleController::class, 'store'])->name('teachers.rules.store');
Route::put('/teachers/{teacher}/rules/{rule}', [StaffRuleController::class, 'update'])->name('teachers.rules.update');
Route::delete('/teachers/{teacher}/rules/{rule}', [StaffRuleController::class, 'destroy'])->name('teachers.rules.destroy');

// Deciding on leave, and the balances behind the decision. A teacher asks for
// time off (see the group below); only a director grants it, and only a
// director can move a balance by hand.
Route::get('/leave/requests', [LeaveRequestController::class, 'index'])->name('leave.requests');
Route::post('/leave/requests/{leave}/approve', [LeaveRequestController::class, 'approve'])->name('leave.approve');
Route::post('/leave/requests/{leave}/deny', [LeaveRequestController::class, 'deny'])->name('leave.deny');
Route::post('/leave/requests/{leave}/revoke', [LeaveRequestController::class, 'revoke'])->name('leave.revoke');

Route::get('/leave/balances', [LeaveBalanceController::class, 'index'])->name('leave.balances');
Route::post('/leave/balances/{user}/adjust', [LeaveBalanceController::class, 'adjust'])->name('leave.adjust');
Route::post('/leave/accrue', [LeaveBalanceController::class, 'accrue'])->name('leave.accrue');

// Payroll preparation: everybody's hours before they become everybody's pay.
// Director only, for the same reason payroll itself is.
Route::get('/timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
Route::post('/timesheets/{period}/seed', [TimesheetController::class, 'seed'])->name('timesheets.seed');
Route::get('/timesheets/{period}/staff/{user}', [TimesheetController::class, 'edit'])->name('timesheets.edit');
Route::put('/timesheets/{period}/staff/{user}', [TimesheetController::class, 'update'])->name('timesheets.update');
Route::post('/timesheets/{period}/approve', [TimesheetController::class, 'approve'])->name('timesheets.approve');
Route::post('/timesheets/{period}/reopen', [TimesheetController::class, 'reopen'])->name('timesheets.reopen');
Route::get('/timesheets/{period}/export', [TimesheetController::class, 'export'])->middleware('never-cached')->name('timesheets.export');

// Correcting the clock. A supervisor's act, never the employee's own — a
// punch somebody can quietly amend is not a record of anything.
/*
 * The correction screen, reached from the week grid — which knows the
 * person and the day but nothing about which fortnight they fall in.
 */
Route::get('/timesheets/fix/{user}/{date}', [TimePunchController::class, 'find'])
    ->name('timesheets.fix')->whereNumber('user');

// The edit panel on the week grid: the day as data, and one save for every
// change made in it. The same primitives as the day screen — a void and a
// replacement, each stamped with who and why — applied together under one
// reason, and refused whole if the day they would produce does not add up.
Route::get('/timesheets/panel/{user}/{date}', [TimePunchController::class, 'panel'])
    ->name('timesheets.panel')->whereNumber('user')->where('date', '\d{4}-\d{2}-\d{2}');
Route::post('/timesheets/panel/{user}/{date}', [TimePunchController::class, 'savePanel'])
    ->name('timesheets.panel.save')->whereNumber('user')->where('date', '\d{4}-\d{2}-\d{2}');
// What the panel's changes would pay, before they are saved: the break and the
// paid hours, worked out by the clock rather than by the browser.
Route::post('/timesheets/panel/{user}/{date}/preview', [TimePunchController::class, 'previewPanel'])
    ->name('timesheets.panel.preview')->whereNumber('user')->where('date', '\d{4}-\d{2}-\d{2}');

// One address that lands on the grid with a person's day already open in the
// edit panel — what a report row, or anything else that names a day, links
// to. The grid reads ?open= on arrival. Distinct from timesheets.fix, which
// stays the no-script way in.
Route::get('/timesheets/open/{user}/{date}', [StaffTimesheetController::class, 'openDay'])
    ->name('timesheets.open')->whereNumber('user')->where('date', '\d{4}-\d{2}-\d{2}');

Route::get('/timesheets/{period}/staff/{user}/day/{date}', [TimePunchController::class, 'show'])->name('timesheets.day')->where('date', '\d{4}-\d{2}-\d{2}');
Route::post('/timesheets/{period}/staff/{user}/day/{date}', [TimePunchController::class, 'store'])->name('timesheets.day.punch')->where('date', '\d{4}-\d{2}-\d{2}');
Route::post('/timesheets/{period}/staff/{user}/punches/{punch}', [TimePunchController::class, 'amend'])->name('timesheets.punch.amend');

// Payroll is every employee's pay in one file. Director only, and never on the
// teacher-visible side of the app.
Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
Route::post('/payroll', [PayrollController::class, 'store'])->name('payroll.store');
Route::get('/payroll/{batch}', [PayrollController::class, 'show'])->name('payroll.show');
Route::delete('/payroll/{batch}', [PayrollController::class, 'destroy'])->name('payroll.destroy');
Route::get('/payroll/{batch}/slips/{slip}/preview', [PayrollController::class, 'preview'])->name('payroll.preview');
Route::put('/payroll/{batch}/slips/{slip}', [PayrollController::class, 'reassign'])->name('payroll.reassign');
Route::post('/payroll/{batch}/slips/{slip}/send', [PayrollController::class, 'send'])->name('payroll.send');

/*
 * The screens staff punch at, and the cards they punch with.
 *
 * Director only, both of them. A device can record hours somebody is paid for,
 * and a card is somebody's identity at that device — issuing either is the
 * same kind of act as setting a pay rate.
 */
/*
 * The week, read every morning: who was in, when, and what does not add up.
 *
 * Separate from /timesheets, which is the fortnight that gets approved. The
 * two answer different questions and are opened on different days — merging
 * them would mean one screen doing neither job well.
 */
Route::get('/staff-timesheets', [StaffTimesheetController::class, 'index'])->name('staff.timesheets');

// Before nothing in particular, but kept next to the screen it mirrors:
// the file carries pay rates, so it stays inside the director-only group.
Route::get('/staff-timesheets/export', [StaffTimesheetController::class, 'export'])->middleware('never-cached')->name('staff.timesheets.export');

/*
 * The reports somebody else asks for: a fortnight of hours per person, chosen
 * from a list and handed on. Director-only for the same reason as the grid it
 * sits beside — it can be asked to print pay.
 */
// The monthly UPK-4 email to the school district (Phase 8.2): the
// director's settings, a test to themselves, and the month sent by hand.
Route::post('/reports/upk-email', [ReportController::class, 'saveEmail'])->name('reports.upk-email.save');
Route::post('/reports/upk-email/test', [ReportController::class, 'testEmail'])->name('reports.upk-email.test');
Route::post('/reports/upk-email/send', [ReportController::class, 'sendEmail'])->name('reports.upk-email.send');
// A day pressed on the attendance sheet: ✓ → A → blank → ✓, written as the DSS page writes it.
Route::post('/reports/sheet/cell', [ReportController::class, 'sheetCell'])->name('reports.sheet.cell');

Route::get('/staff-reports', [StaffReportController::class, 'index'])->name('staff.reports');
Route::get('/staff-reports/export', [StaffReportController::class, 'export'])->middleware('never-cached')->name('staff.reports.export');

/*
 * The parts of the centre. A department is a line on the org chart and the
 * grouping every hours report totals by, so creating one belongs here with the
 * rest of the director-only staff screens.
 */
Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
Route::get('/departments/create', [DepartmentController::class, 'create'])->name('departments.create');
Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
Route::get('/departments/{department}/edit', [DepartmentController::class, 'edit'])->name('departments.edit');
Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');

/*
 * Settings. Director only, and not only because of what is on the page: the
 * attendance mode changes how hours are recorded for everybody, which is a
 * bigger act than editing any one person's record.
 */
// Settings opens on Children (16 Oct 2026): the company page is off the tabs
// but keeps its address, so nothing that saved to it has moved.
Route::get('/settings', fn () => redirect()->route('settings.children'))->name('settings.index');
Route::get('/settings/company', [SettingController::class, 'company'])->name('settings.company');
Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
Route::get('/settings/children', [SettingController::class, 'children'])->name('settings.children');
// Rooms & hours — Phase 1.2: ratios, capacities, room hours, the centre's day and shift limits.
Route::get('/settings/rooms', [SettingController::class, 'rooms'])->name('settings.rooms');
Route::put('/settings/rooms', [SettingController::class, 'updateRooms'])->name('settings.rooms.update');
Route::put('/settings/children', [SettingController::class, 'updateChildren'])->name('settings.children.update');
// Settings → Children → Names: how a child's name reads, for the whole centre (27 Oct 2026).
Route::put('/settings/names', [SettingController::class, 'updateNames'])->name('settings.names.update');
Route::get('/settings/administrators', [SettingController::class, 'administrators'])->name('settings.administrators');

// Taking a child off every list and putting them back. Director only, like
// the settings page they are restored from: a teacher may mark a child in
// their room as Inactive, not hide one from the whole centre.
Route::post('/children/{child}/archive', [ChildArchiveController::class, 'archive'])->name('children.archive')->whereNumber('child');
Route::post('/children/{child}/unarchive', [ChildArchiveController::class, 'unarchive'])->name('children.unarchive')->whereNumber('child');

// The rest of the lifecycle (Phase 2.2): a place on the roll is the
// office's to give and take. Each writes a line to the enrollment history.
Route::post('/children/{child}/start', [ChildStatusController::class, 'start'])->name('children.start')->whereNumber('child');
Route::post('/children/{child}/cancel', [ChildStatusController::class, 'cancel'])->name('children.cancel')->whereNumber('child');
Route::post('/children/{child}/withdraw', [ChildStatusController::class, 'withdraw'])->name('children.withdraw')->whereNumber('child');
Route::post('/children/{child}/reenroll', [ChildStatusController::class, 'reenroll'])->name('children.reenroll')->whereNumber('child');
// Moving a child between rooms moves them between teachers: director only.
Route::post('/children/{child}/room', [ChildRecordController::class, 'room'])->name('children.room')->whereNumber('child');
Route::get('/settings/devices', [SettingController::class, 'devices'])->name('settings.devices');
Route::get('/settings/login-history', [SettingController::class, 'history'])->name('settings.history');

Route::get('/devices', [StaffDeviceController::class, 'index'])->name('devices.index');
Route::post('/devices', [StaffDeviceController::class, 'store'])->name('devices.store');
Route::put('/devices/{device}', [StaffDeviceController::class, 'update'])->name('devices.update');
Route::get('/devices/{device}/link', [StaffDeviceController::class, 'link'])->name('devices.link');
Route::get('/devices/{device}/link.svg', [StaffDeviceController::class, 'qr'])->name('devices.link.qr');
Route::post('/devices/{device}/repair', [StaffDeviceController::class, 'repair'])->name('devices.repair');
Route::delete('/devices/{device}', [StaffDeviceController::class, 'destroy'])->name('devices.destroy');

// Who's where: the staff presence board the kiosks feed (HANDOFF §5.3.1),
// and the flags the office has to answer. Director only, like the devices.
Route::get('/staff/whos-where', [StaffPresenceController::class, 'board'])->middleware('never-cached')->name('staff.presence');
Route::post('/staff/whos-where/flags/{event}', [StaffPresenceController::class, 'resolve'])->name('staff.presence.resolve');


Route::post('/teachers/{staff}/card', [StaffCardController::class, 'issue'])->name('staff.card.issue');
Route::get('/teachers/{staff}/card.svg', [StaffCardController::class, 'show'])->name('staff.card.show');
Route::get('/teachers/{staff}/card.png', [StaffCardController::class, 'download'])->name('staff.card.download');
Route::post('/teachers/{staff}/clock-pin', [StaffCardController::class, 'pin'])->name('staff.card.pin');
Route::get('/children/create', [ChildController::class, 'create'])->name('children.create');
Route::post('/children', [ChildController::class, 'store'])->name('children.store');

Route::get('/children/import',[ChildrenController::class,'showImport'])->name('children.import.form');

// The spreadsheet the importer expects, empty. It is the documentation:
// ninety columns are no use if nobody can find out what they are called.
Route::get('/children/import/template', [ChildrenController::class, 'importTemplate'])
    ->name('children.import.template');

Route::post('/children/import',[ChildrenController::class,'import'])->name('children.import');
});

Route::middleware('role:admin,teacher')->group(function () {
    /*
     * Editing a child's record.
     *
     * A teacher keeps the record of the children in their own rooms — they are
     * the one who is told a new mobile number at the door, and the one who
     * hears that grandma is collecting on Thursdays. Waiting on the director to
     * type it in is how a record goes stale.
     *
     * Which child is checked in the controller, against the same rule the
     * roster is filtered by. What may be changed is checked there too: the
     * facts that decide rooms, enrolment and billing stay the director's, and
     * `classroom_override` especially — a teacher who could set it could move a
     * child into their own room and read a record that was never theirs.
     */
    Route::get('/children/{child}/edit', [ChildController::class, 'edit'])->name('children.edit')->whereNumber('child');
    Route::put('/children/{child}', [ChildController::class, 'update'])->name('children.update')->whereNumber('child');

    /*
     * The People step, and the drawer behind it.
     *
     * Same door as editing the child: a teacher keeps the record of the
     * children in their own rooms, and who may collect one of them is part of
     * that record rather than something to wait on the office for. Which child
     * is checked per call in the controller, against the rule the roster is
     * filtered by — the route only says that somebody may edit some child.
     *
     * POST throughout rather than PUT/DELETE, for the reason the attendance
     * routes give: the app talks to the server through postJson, and Laravel
     * reads _method spoofing out of form parameters that a JSON body does not
     * populate.
     */
    Route::get('/children/{child}/people', [PersonController::class, 'forChild'])->name('children.people')->whereNumber('child');

    /*
     * The cards on the record that save in place (Phase 2.3): the overview,
     * the enrollment note, the health facts and alerts, notes, documents,
     * and what each adult is for the child. Same door as editing the record.
     */
    Route::put('/children/{child}/overview', [ChildRecordController::class, 'overview'])->name('children.overview')->whereNumber('child');
    Route::put('/children/{child}/enrollment-note', [ChildRecordController::class, 'enrollmentNote'])->name('children.enrollment-note')->whereNumber('child');
    Route::put('/children/{child}/health', [ChildRecordController::class, 'health'])->name('children.health')->whereNumber('child');
    Route::post('/children/{child}/alerts', [ChildRecordController::class, 'storeAlert'])->name('children.alerts.store')->whereNumber('child');
    Route::delete('/children/{child}/alerts/{index}', [ChildRecordController::class, 'destroyAlert'])->name('children.alerts.destroy')->whereNumber('child')->whereNumber('index');
    Route::post('/children/{child}/notes', [ChildRecordController::class, 'storeNote'])->name('children.notes.store')->whereNumber('child');
    Route::post('/children/{child}/documents', [ChildRecordController::class, 'storeDocument'])->name('children.documents.store')->whereNumber('child');
    Route::get('/children/{child}/documents/{document}', [ChildRecordController::class, 'document'])->name('children.documents.show')->whereNumber('child')->whereNumber('document');
    Route::post('/children/{child}/people/{person}/flags', [ChildRecordController::class, 'personFlags'])->name('children.people.flags')->whereNumber('child')->whereNumber('person');
    Route::post('/children/{child}/people/{person}/order', [ChildRecordController::class, 'personOrder'])->name('children.people.order')->whereNumber('child')->whereNumber('person');
    Route::get('/people/search', [PersonController::class, 'search'])->name('people.search');
    Route::post('/people', [PersonController::class, 'store'])->name('people.store');
    Route::get('/people/{person}', [PersonController::class, 'show'])->name('people.show')->whereNumber('person');
    Route::post('/people/{person}', [PersonController::class, 'update'])->name('people.update')->whereNumber('person');
    Route::post('/people/{person}/delete', [PersonController::class, 'destroy'])->name('people.destroy')->whereNumber('person');
    Route::post('/people/{person}/link', [PersonController::class, 'link'])->name('people.link')->whereNumber('person');
    Route::post('/people/{person}/unlink', [PersonController::class, 'unlink'])->name('people.unlink')->whereNumber('person');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->middleware('never-cached')->name('reports.export');

    // Read-only for teachers: knowing who else is on the floor at 3pm is the
    // reason the roster exists, and hiding it would send them back to asking.
    Route::get('/staff-schedule', [StaffScheduleController::class, 'index'])->name('staff-schedule.index');

    // Generating it is open to teachers as well as the director: the lead
    // teacher on the floor is often the one who knows the week has changed and
    // needs the roster rebuilt. The rules it is built from stay director-only.
    Route::post('/staff-schedule/generate', [StaffScheduleController::class, 'generate'])->name('staff-schedule.generate');

    // The same week, narrowed to the person asking. Their own row is the one
    // they came for, and a phone in a corridor is no place to find it in a
    // fourteen-hundred-pixel chart.
    Route::get('/my-schedule', [StaffScheduleController::class, 'mine'])->name('staff-schedule.mine');

    // The time clock, punched as yourself. A teacher sees their own punches and
    // their own hours and nothing else — no rate, no colleague, no correction.
    Route::get('/time-clock', [TimeClockController::class, 'index'])->name('clock.index');
    Route::post('/time-clock', [TimeClockController::class, 'punch'])->name('clock.punch');

    // Your own leave: what you have earned, what you have asked for, and what
    // was decided. Directors get this too — they hold balances like anybody
    // else, they just cannot sign off their own.
    Route::get('/leave', [LeaveController::class, 'index'])->name('leave.index');
    Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store');
    Route::delete('/leave/{leave}', [LeaveController::class, 'destroy'])->name('leave.destroy');
});

/*
 * Messages (Phase 9): texts to families. Teachers and the office both read
 * and write; what each may see is decided per conversation — see
 * MessageThread::visibleTo(). A parent's reply arrives at /sms/inbound from
 * the provider, with no session, below.
 */
Route::middleware(['auth', 'password.change', 'role:admin,teacher'])->group(function () {
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/search', [MessageController::class, 'search'])->name('messages.search');
    Route::get('/messages/recipients', [MessageController::class, 'recipients'])->name('messages.recipients');
    Route::post('/messages', [MessageController::class, 'store'])->name('messages.store');
    Route::post('/messages/polish', [MessageController::class, 'polish'])->name('messages.polish');
    Route::post('/messages/upload', [MessageController::class, 'upload'])->name('messages.upload');
    Route::get('/messages/{thread}', [MessageController::class, 'show'])->name('messages.show')->whereNumber('thread');
    Route::post('/messages/{thread}/reply', [MessageController::class, 'reply'])->name('messages.reply')->whereNumber('thread');
});
// The DSS attendance page (Phase 8.1): the county's month, for the one
// person who reconciles it — see the see-dss gate in AppServiceProvider.
Route::middleware(['auth', 'password.change', 'can:see-dss'])->group(function () {
    Route::get('/dss', [DssController::class, 'index'])->name('dss.index');
    Route::get('/dss/grid', [DssController::class, 'grid'])->name('dss.grid');
    Route::post('/dss/cells', [DssController::class, 'apply'])->name('dss.apply');
    Route::get('/dss/export/preview', [DssController::class, 'preview'])->name('dss.preview');
    Route::get('/dss/export', [DssController::class, 'export'])->middleware('never-cached')->name('dss.export');
});

// The building, room by room, live — Phase 4.2. Read over what the door,
// the register, the clock and the roster already say; the one write is the
// short room's "Move X to Y until Z arrives", a cover on today's roster.
Route::get('/floor-plan', [FloorPlanController::class, 'index'])->middleware(['auth', 'password.change', 'role:admin,teacher', 'never-cached'])->name('floor-plan.index');
Route::post('/floor-plan/move', [FloorPlanController::class, 'move'])->middleware(['auth', 'password.change', 'role:admin'])->name('floor-plan.move');

// Director Attendance is the register again (Jordan, 28 Oct 2026: "back to
// old design"), always in edit, with no Live switch and no ⋯ menu. The
// week grid drawn from DirectorAttendance.dc.html is kept whole at
// /attendance/week; /attendance/classic still answers, as the same register.
Route::get('/attendance', [AttendanceController::class, 'index'])
    ->name('attendance.index');
Route::get('/attendance/classic', [AttendanceController::class, 'index'])
    ->name('attendance.classic');
Route::get('/attendance/week', [AttendanceController::class, 'week'])
    ->name('attendance.week');
Route::post('/attendance/week/cell', [AttendanceController::class, 'cell'])
    ->name('attendance.week.cell');

// The week on paper. Same audience as the sheet above: whoever may see a
// child's row on screen may print it.
Route::get('/attendance/print', [AttendanceController::class, 'print'])
    ->name('attendance.print');

Route::post('/attendance/sign-in', [AttendanceController::class, 'signIn'])
    ->name('attendance.signin');

/*
 * The health check taken at the door.
 *
 * Open to whoever may work the sheet, because the person who saw the child is
 * the person who should write it down — a teacher who has to find a director
 * before they can record "rash" records nothing at all. Which days each may
 * write is decided inside, where a stale tab cannot get around it.
 *
 * POST rather than PATCH for the reason the rest of the board's routes give:
 * the page talks through postJson, and method spoofing is read from form
 * parameters that a JSON body does not populate.
 */
/*
 * The door screen. Today and nothing else, on purpose: a screen that can
 * also show last Tuesday is one somebody eventually checks a child in on
 * last Tuesday. Corrections belong on the week sheet, where they are
 * written to attendance_amendments.
 */
/*
 * The month on one page: the paper sheet the centre already keeps, with the
 * health check on it. Same audience as the register — whoever may see a
 * child's row on screen may print it.
 */
Route::get('/attendance/month-sheet', [MonthSheetController::class, 'index'])->name('attendance.month-sheet');

// The month as the paper form, as a workbook: a sheet per room, four lines a
// child, a column a day, the totals along the foot. Built from the same rows
// the page above draws, so the file and the screen cannot disagree.
Route::get('/attendance/month-sheet/export', [MonthSheetController::class, 'export'])->middleware('never-cached')->name('attendance.month-sheet.export');

Route::get('/check-in', [CheckInController::class, 'index'])->name('check-in.index');
Route::post('/check-in', [CheckInController::class, 'store'])->name('check-in.store');
Route::post('/check-in/{attendance}/in', [CheckInController::class, 'in'])
    ->name('check-in.in')->whereNumber('attendance');
Route::post('/check-in/{attendance}/out', [CheckInController::class, 'out'])
    ->name('check-in.out')->whereNumber('attendance');
// Back in after a clock-out. The out it closes is kept as a return, so every
// in and out of the day stays on the record.
Route::post('/check-in/{attendance}/back', [CheckInController::class, 'back'])
    ->name('check-in.back')->whereNumber('attendance');
// Edit on the door: a typed arrival, the exact hours, a day taken off, a
// check corrected — all on the door's own record, never the register's.
Route::post('/check-in/day', [CheckInController::class, 'signInDay'])->name('check-in.day');
Route::post('/check-in/day/retime', [CheckInController::class, 'retime'])->name('check-in.day.retime');
Route::post('/check-in/day/remove', [CheckInController::class, 'remove'])->name('check-in.day.remove');
Route::post('/check-in/{attendance}/health', [CheckInController::class, 'health'])
    ->name('check-in.health')->whereNumber('attendance');

Route::get('/symptom-codes', [HealthController::class, 'codes'])->name('health.codes');
// The notes on a child's day, read and written from the door screen's dialog.
Route::get('/attendances/{attendance}/comments', [AttendanceCommentController::class, 'index'])->name('attendances.comments.index')->whereNumber('attendance');
Route::post('/attendances/{attendance}/comments', [AttendanceCommentController::class, 'store'])->name('attendances.comments.store')->whereNumber('attendance');
Route::patch('/attendances/{attendance}/comments/{comment}', [AttendanceCommentController::class, 'update'])->name('attendances.comments.update')->whereNumber('attendance')->whereNumber('comment')->scopeBindings();
// A child's earlier comments, by day, for the dialog's Past comments tab; and
// the log of a day's comments across every child, with its export.
// By row id, not LAN: the dialog holds the id, as every other door request does.
Route::get('/children/{child:id}/comments', [ChildCommentController::class, 'index'])->name('children.comments')->whereNumber('child');
Route::get('/comments', [CommentLogController::class, 'index'])->name('comments.index');
Route::get('/comments/export', [CommentLogController::class, 'export'])->middleware('never-cached')->name('comments.export');
Route::post('/attendance/{attendance}/health', [HealthController::class, 'update'])
    ->name('attendance.health')->whereNumber('attendance');

Route::middleware('role:admin,teacher')->group(function () {
    // Taking an arrival back off the register: the other half of a correction,
    // and the more consequential half, so it sits behind the same door the
    // schedule controls do rather than beside the open sign-in route.
    // POST rather than DELETE: the whole app talks to the server through
    // postJson, which always sends POST with a JSON body — and Laravel reads
    // _method spoofing out of form parameters, which a JSON body does not
    // populate. A DELETE route here would 405 every time.
    Route::post('/attendance/sign-in/remove', [AttendanceController::class, 'removeSignIn'])->name('attendance.signin.remove');
    // Director Attendance's tap: time → dot → Absent → the same time back.
    Route::post('/attendance/step', [AttendanceController::class, 'step'])->name('attendance.step');
    // Moving the hour on an arrival. Same door as removing one: it rewrites
    // a figure the centre bills from, and the same people may do it.
    Route::post('/attendance/sign-in/retime', [AttendanceController::class, 'retime'])->name('attendance.signin.retime');
    // A child clocked out who came back: the departure it closes goes on a
    // return, and the row is open again. Same door: it moves a billed hour.
    Route::post('/attendance/sign-in/return', [AttendanceController::class, 'returnIn'])->name('attendance.signin.return');

    // Building a week is a decision, so it has its own door. Merely viewing one
    // never creates it.
    Route::post('/attendance/week/open', [AttendanceController::class, 'openWeek'])->name('attendance.week.open');
    Route::post('/attendance/schedule', [ScheduleController::class, 'update'])->name('attendance.schedule.update');

    // The hour a booked day is booked for. Beside the tick it completes,
    // and nowhere near the sign-in routes — it writes no attendance.
    Route::post('/attendance/schedule/time', [ScheduleController::class, 'plannedTime'])->name('attendance.schedule.time');
    Route::post('/attendance/schedule/closure', [ScheduleController::class, 'closure'])->name('attendance.schedule.closure');
});

// Moving a child between rooms changes who can see them, so it is the director's
// call — a teacher cannot hand a child to another room, or take one from it.
Route::middleware('role:admin')->group(function () {
    Route::post('/attendance/schedule/classroom', [ScheduleController::class, 'classroom'])->name('attendance.schedule.classroom');
});

// Everyone gets to keep their own account details current, whatever their role.
Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
Route::delete('/profile/photo', [ProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');
// Set from whichever roster the reader happens to be on, and changes only
// their own screens.
Route::post('/profile/name-format', [ProfileController::class, 'nameFormat'])->name('profile.name-format');

Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
