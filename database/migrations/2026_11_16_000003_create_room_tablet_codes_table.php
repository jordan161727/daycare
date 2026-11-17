<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room tablet codes get a table of their own (QA K4, 16 Nov 2026).
 *
 * They lived in settings as one row per room ("rooms.PreK.tablet_code"),
 * where nothing but the Rooms & hours form could stop two rooms holding the
 * same code. Here each room has at most one code and each code names at most
 * one room, by unique indexes.
 *
 * The codes stay readable rather than hashed: the director reads them back
 * on Rooms & hours to hand to whoever moves a tablet, and a code only
 * chooses a room — the director's PIN in front of it is the secret.
 *
 * Moved across as they are. If two rooms already share a code it stops and
 * changes nothing, rather than choosing which room keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = Schema::hasTable('settings')
            ? DB::table('settings')->where('key', 'like', 'rooms.%.tablet_code')->get(['key', 'value'])
                ->map(fn ($row) => ['room' => substr($row->key, 6, -12), 'code' => trim((string) $row->value)])
                ->filter(fn (array $row) => $row['room'] !== '' && $row['code'] !== '')
                ->values()
            : collect();

        $shared = $rows->groupBy('code')->filter(fn ($group) => $group->count() > 1);

        if ($shared->isNotEmpty()) {
            throw new RuntimeException(
                'These rooms share a tablet code: '
                .$shared->map(fn ($group) => $group->pluck('room')->implode(' and '))->implode('; ')
                .'. Nothing has been changed. Give each room its own code in Settings → Rooms & hours, then run `php artisan migrate` again.'
            );
        }

        Schema::create('room_tablet_codes', function (Blueprint $table) {
            $table->id();
            $table->string('room')->unique();
            $table->string('code')->unique();
            $table->timestamps();
        });

        $now = now();
        foreach ($rows as $row) {
            DB::table('room_tablet_codes')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'like', 'rooms.%.tablet_code')->delete();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            $now = now();
            foreach (DB::table('room_tablet_codes')->get() as $row) {
                DB::table('settings')->updateOrInsert(
                    ['key' => "rooms.{$row->room}.tablet_code"],
                    ['value' => $row->code, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        Schema::dropIfExists('room_tablet_codes');
    }
};
