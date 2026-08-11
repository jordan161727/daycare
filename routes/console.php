<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A room follows an age, so it can only change at a date boundary — a birthday.
// Once a night, before anyone opens the sheet, is enough.
Schedule::command('classrooms:sync')->dailyAt('00:05');
