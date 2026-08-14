<?php
use App\Http\Controllers\ChildImportController;
use App\Http\Controllers\ChildrenController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ChildController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ChildDocumentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\TimeClockController;
use App\Http\Controllers\TimePunchController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\StaffRuleController;
use App\Http\Controllers\StaffScheduleController;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use App\Models\Attendance;
use App\Models\Child;

Route::redirect('/', '/dashboard');

// Screens that stay open all day (the attendance board, the import form) top up
// their CSRF token from here so a submit hours later is not met with "Page
// Expired". Reachable while signed out too, so the login form can do the same.
Route::get('/csrf-token', fn () => response()->json(['token' => csrf_token()]))->name('csrf.token');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
Route::get('/dashboard', function () {
    if (! Schema::hasTable('children') || ! Schema::hasTable('attendances')) {
        return view('dashboard.index', [
            'totalChildren' => 0,
            'presentToday' => 0,
            'totalRooms' => 0,
            'recentAttendance' => collect(),
        ]);
    }

    $user = request()->user();
    $totalChildren = Child::visibleTo($user)->where('status', 'Active')->count();
    $presentToday = Attendance::whereDate('attendance_date', today())->whereHas('child', fn ($query) => $query->visibleTo($user))->count();
    $totalRooms = Child::visibleTo($user)->where('status', 'Active')->distinct('classroom')->count('classroom');
    $recentAttendance = Attendance::with('child')->whereDate('attendance_date', today())
        ->whereHas('child', fn ($query) => $query->visibleTo($user))
        ->latest('signed_in_at')->take(5)->get();

    return view('dashboard.index', compact('totalChildren', 'presentToday', 'totalRooms', 'recentAttendance'));
})->name('dashboard');


Route::get('/children', [ChildController::class, 'index'])->name('children.index');

Route::middleware('role:admin')->group(function () {
Route::get('/children/import-document', [ChildDocumentController::class, 'create'])->name('children.document-import.create');
Route::post('/children/import-document', [ChildDocumentController::class, 'store'])->name('children.document-import.store');
Route::get('/children/import-document/{token}/review', [ChildDocumentController::class, 'review'])->name('children.document-import.review');
Route::get('/children/import-document/{token}/file', [ChildDocumentController::class, 'file'])->name('children.document-import.file');
Route::resource('teachers', TeacherController::class)->parameters(['teachers' => 'teacher']);

// Scheduling rules only ever make sense against the person they constrain, so
// they are nested rather than given a table of their own.
Route::post('/teachers/{teacher}/rules', [StaffRuleController::class, 'store'])->name('teachers.rules.store');
Route::put('/teachers/{teacher}/rules/{rule}', [StaffRuleController::class, 'update'])->name('teachers.rules.update');
Route::delete('/teachers/{teacher}/rules/{rule}', [StaffRuleController::class, 'destroy'])->name('teachers.rules.destroy');

// Payroll preparation: everybody's hours before they become everybody's pay.
// Director only, for the same reason payroll itself is.
Route::get('/timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
Route::post('/timesheets/{period}/seed', [TimesheetController::class, 'seed'])->name('timesheets.seed');
Route::get('/timesheets/{period}/staff/{user}', [TimesheetController::class, 'edit'])->name('timesheets.edit');
Route::put('/timesheets/{period}/staff/{user}', [TimesheetController::class, 'update'])->name('timesheets.update');
Route::post('/timesheets/{period}/approve', [TimesheetController::class, 'approve'])->name('timesheets.approve');
Route::post('/timesheets/{period}/reopen', [TimesheetController::class, 'reopen'])->name('timesheets.reopen');
Route::get('/timesheets/{period}/export', [TimesheetController::class, 'export'])->name('timesheets.export');

// Correcting the clock. A supervisor's act, never the employee's own — a
// punch somebody can quietly amend is not a record of anything.
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

// The staff roster is built from pay-affecting rules, so it stays director-only
// too — but every teacher can read the generated week, which is the point of
// generating it.
Route::post('/staff-schedule/generate', [StaffScheduleController::class, 'generate'])->name('staff-schedule.generate');
Route::get('/children/create', [ChildController::class, 'create'])->name('children.create');
Route::post('/children', [ChildController::class, 'store'])->name('children.store');
Route::get('/children/{child}/edit', [ChildController::class, 'edit'])->name('children.edit');
Route::put('/children/{child}', [ChildController::class, 'update'])->name('children.update');

Route::get('/children/import',[ChildrenController::class,'showImport'])->name('children.import.form');

Route::post('/children/import',[ChildrenController::class,'import'])->name('children.import');
});

Route::middleware('role:admin,teacher')->group(function () {
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

    // Read-only for teachers: knowing who else is on the floor at 3pm is the
    // reason the roster exists, and hiding it would send them back to asking.
    Route::get('/staff-schedule', [StaffScheduleController::class, 'index'])->name('staff-schedule.index');

    // The time clock, punched as yourself. A teacher sees their own punches and
    // their own hours and nothing else — no rate, no colleague, no correction.
    Route::get('/time-clock', [TimeClockController::class, 'index'])->name('clock.index');
    Route::post('/time-clock', [TimeClockController::class, 'punch'])->name('clock.punch');
});

Route::get('/attendance', [AttendanceController::class, 'index'])
    ->name('attendance.index');

Route::post('/attendance/sign-in', [AttendanceController::class, 'signIn'])
    ->name('attendance.signin');

Route::middleware('role:admin,teacher')->group(function () {
    // Building a week is a decision, so it has its own door. Merely viewing one
    // never creates it.
    Route::post('/attendance/week/open', [AttendanceController::class, 'openWeek'])->name('attendance.week.open');
    Route::post('/attendance/schedule', [ScheduleController::class, 'update'])->name('attendance.schedule.update');
    Route::post('/attendance/schedule/copy', [ScheduleController::class, 'copy'])->name('attendance.schedule.copy');
    Route::post('/attendance/schedule/closure', [ScheduleController::class, 'closure'])->name('attendance.schedule.closure');
});

// Moving a child between rooms changes who can see them, so it is the director's
// call — a teacher cannot hand a child to another room, or take one from it.
Route::middleware('role:admin')->group(function () {
    Route::post('/attendance/schedule/classroom', [ScheduleController::class, 'classroom'])->name('attendance.schedule.classroom');
});

Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
