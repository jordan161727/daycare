<?php
use App\Http\Controllers\ChildImportController;
use App\Http\Controllers\ChildrenController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ChildController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ChildDocumentController;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use App\Models\Attendance;
use App\Models\Child;

Route::redirect('/', '/dashboard');

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
Route::resource('teachers', TeacherController::class)->except('show')->parameters(['teachers' => 'teacher']);
Route::get('/children/create', [ChildController::class, 'create'])->name('children.create');
Route::post('/children', [ChildController::class, 'store'])->name('children.store');
Route::get('/children/{child}/edit', [ChildController::class, 'edit'])->name('children.edit');
Route::put('/children/{child}', [ChildController::class, 'update'])->name('children.update');

Route::get('/children/import',[ChildrenController::class,'showImport'])->name('children.import.form');

Route::post('/children/import',[ChildrenController::class,'import'])->name('children.import');
});

Route::middleware('role:admin,teacher')->group(function () {
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
});

Route::get('/attendance', [AttendanceController::class, 'index'])
    ->name('attendance.index');

Route::post('/attendance/sign-in', [AttendanceController::class, 'signIn'])
    ->name('attendance.signin');

Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
