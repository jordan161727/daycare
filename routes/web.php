<?php
use App\Http\Controllers\ChildImportController;
use App\Http\Controllers\ChildrenController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ChildController;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use App\Models\Attendance;
use App\Models\Child;

Route::get('/', function () {
    if (! Schema::hasTable('children') || ! Schema::hasTable('attendances')) {
        return view('dashboard.index', [
            'totalChildren' => 0,
            'presentToday' => 0,
            'totalRooms' => 0,
            'recentAttendance' => collect(),
        ]);
    }

    $totalChildren = Child::where('status', 'Active')->count();
    $presentToday = Attendance::whereDate('attendance_date', today())->count();
    $totalRooms = Child::where('status', 'Active')->distinct('classroom')->count('classroom');
    $recentAttendance = Attendance::with('child')->whereDate('attendance_date', today())
        ->latest('signed_in_at')->take(5)->get();

    return view('dashboard.index', compact('totalChildren', 'presentToday', 'totalRooms', 'recentAttendance'));
})->name('dashboard');


Route::get('/children', [ChildController::class, 'index'])->name('children.index');

Route::get('/children/create', [ChildController::class, 'create'])->name('children.create');
Route::post('/children', [ChildController::class, 'store'])->name('children.store');
Route::get('/children/{child}/edit', [ChildController::class, 'edit'])->name('children.edit');
Route::put('/children/{child}', [ChildController::class, 'update'])->name('children.update');

Route::get('/children/import',[ChildrenController::class,'showImport'])->name('children.import.form');

Route::post('/children/import',[ChildrenController::class,'import'])->name('children.import');


Route::get('/attendance', [AttendanceController::class, 'index'])
    ->name('attendance.index');

Route::post('/attendance/sign-in', [AttendanceController::class, 'signIn'])
    ->name('attendance.signin');

Route::view('/reports', 'reports.index')->name('reports.index');
