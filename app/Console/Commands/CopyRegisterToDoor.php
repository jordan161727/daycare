<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\DoorAttendance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Copy a day's register rows onto the door's record.
 *
 * The director's week sheet (attendances) and the door screen
 * (door_attendances) are separate records since 16 Oct 2026. A day that was
 * worked on the register before the split, or on a morning the door screen
 * was not used, is therefore missing from Teacher Attendance. This carries
 * it over: one door row per register row, with the times, the session and
 * the health checks, plus any out-and-back trips.
 *
 * Additive only. A child who already has a door row for that day and
 * session is left exactly as the door recorded them, unless --overwrite is
 * given, in which case the register's version replaces it. --dry-run says
 * what would happen and writes nothing.
 *
 *   php artisan attendance:copy-register-to-door            # today
 *   php artisan attendance:copy-register-to-door 2026-10-15
 *   php artisan attendance:copy-register-to-door 2026-10-15 --dry-run
 */
class CopyRegisterToDoor extends Command
{
    protected $signature = 'attendance:copy-register-to-door
                            {date? : The day to copy, Y-m-d; today when left out}
                            {--overwrite : Replace door rows that already exist for that day}
                            {--dry-run : Report what would be copied without writing}';

    protected $description = "Copy a day's rows from the director's register onto the door's record";

    public function handle(): int
    {
        // One record since 20 Oct 2026: the door reads the register's own
        // table, so there is nothing to copy and copying would double the
        // rows. Said plainly rather than done.
        $this->warn('The door and the register are one record now (since 20 Oct 2026): the door reads the attendances table directly. Nothing to copy.');

        return self::SUCCESS;

        // @phpstan-ignore-next-line — kept for the day the split is wanted again.
        $date = Carbon::parse($this->argument('date') ?: today())->toDateString();
        $dry = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');

        $rows = Attendance::with('returns')->whereDate('attendance_date', $date)->get();

        if ($rows->isEmpty()) {
            $this->warn("No register rows on {$date}. Nothing to copy.");

            return self::SUCCESS;
        }

        $created = $replaced = $kept = 0;

        DB::transaction(function () use ($rows, $date, $dry, $overwrite, &$created, &$replaced, &$kept) {
            foreach ($rows as $row) {
                $existing = DoorAttendance::where('child_id', $row->child_id)
                    ->whereDate('attendance_date', $date)
                    ->where('session', $row->session)
                    ->first();

                if ($existing && ! $overwrite) {
                    $kept++;

                    continue;
                }

                if ($dry) {
                    $existing ? $replaced++ : $created++;

                    continue;
                }

                if ($existing) {
                    $existing->returns()->delete();
                    $existing->delete();
                    $replaced++;
                } else {
                    $created++;
                }

                $door = DoorAttendance::create([
                    'child_id' => $row->child_id,
                    'attendance_date' => $date,
                    'session' => $row->session,
                    'signed_in_at' => $row->signed_in_at,
                    'signed_out_at' => $row->signed_out_at,
                    'health_in_code' => $row->health_in_code,
                    'health_in_note' => $row->health_in_note,
                    'health_out_code' => $row->health_out_code,
                    'health_out_note' => $row->health_out_note,
                ]);

                foreach ($row->returns as $trip) {
                    $door->returns()->create([
                        'left_at' => $trip->left_at,
                        'returned_at' => $trip->returned_at,
                        'performed_by' => $trip->performed_by,
                    ]);
                }
            }
        });

        $this->info(($dry ? '[dry run] ' : '')."{$date}: {$rows->count()} register rows — {$created} copied to the door, {$replaced} replaced, {$kept} already on the door and left alone.");

        if ($kept > 0 && ! $overwrite) {
            $this->line('Add --overwrite to replace the rows the door already has with the register\'s.');
        }

        return self::SUCCESS;
    }
}
