<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Puts back the columns two migrations make, where the migrations table says
 * they ran but the columns are not there (the staging database, 12 Nov 2026:
 * `migrate` said "nothing to migrate" while Check-in, Withdraw, Archive and
 * the rest failed with "Unknown column").
 *
 * It only adds what is missing — never drops, never changes a value that is
 * there — so running it twice is the same as running it once. Find what is
 * missing first with schema:check.
 */
class RepairSchema extends Command
{
    protected $signature = 'schema:repair {--dry-run : List what would be added and change nothing}';

    protected $description = 'Add the columns and tables of the phase-two child record and the comment day fields, where missing';

    private array $added = [];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // 2026_10_26_000001_phase_two_child_record
        $this->add('children', [
            'withdrawal_reason' => fn (Blueprint $t) => $t->string('withdrawal_reason')->nullable(),
            'withdrawal_note' => fn (Blueprint $t) => $t->text('withdrawal_note')->nullable(),
            'could_return' => fn (Blueprint $t) => $t->string('could_return', 20)->nullable(),
            'enrollment_note' => fn (Blueprint $t) => $t->text('enrollment_note')->nullable(),
            'enrollment_note_at' => fn (Blueprint $t) => $t->timestamp('enrollment_note_at')->nullable(),
            'health' => fn (Blueprint $t) => $t->json('health')->nullable(),
            'custody_note' => fn (Blueprint $t) => $t->string('custody_note')->nullable(),
            'updated_by' => fn (Blueprint $t) => $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete(),
        ], $dry);
        $this->add('child_people', [
            'pays_fees' => fn (Blueprint $t) => $t->boolean('pays_fees')->default(false),
            'gets_texts' => fn (Blueprint $t) => $t->boolean('gets_texts')->default(false),
        ], $dry);
        $this->add('child_status_changes', [
            'event' => fn (Blueprint $t) => $t->string('event', 30)->default('status'),
            'note' => fn (Blueprint $t) => $t->text('note')->nullable(),
        ], $dry);

        $this->create('child_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 40);
            $t->string('name');
            $t->string('path')->nullable();
            $t->date('signed_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['child_id', 'kind']);
        }, $dry);
        $this->create('child_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('kind', 20)->default('office');
            $t->text('body');
            $t->timestamps();
            $t->index(['child_id', 'created_at']);
        }, $dry);

        // 2026_10_20_000001_add_day_fields_to_attendance_comments_table
        $hadDate = Schema::hasColumn('attendance_comments', 'date');
        $this->add('attendance_comments', [
            'date' => fn (Blueprint $t) => $t->date('date')->nullable(),
            'author_role' => fn (Blueprint $t) => $t->string('author_role', 20)->nullable(),
            'edited_at' => fn (Blueprint $t) => $t->timestamp('edited_at')->nullable(),
            'deleted_at' => fn (Blueprint $t) => $t->softDeletes(),
        ], $dry);

        if ($dry) {
            $this->report('Would add');
            $this->line('Nothing was changed (--dry-run).');

            return self::SUCCESS;
        }

        if (! $hadDate) {
            Schema::table('attendance_comments', function (Blueprint $t) {
                $t->index(['child_id', 'date']);
                $t->index('date');
            });
        }

        // Each comment's day and its author's role, where not filled in yet.
        $dates = DB::table('attendance_comments as c')->join('door_attendances as d', 'd.id', '=', 'c.attendance_id')
            ->whereNull('c.date')->get(['c.id', 'd.attendance_date']);
        foreach ($dates as $r) {
            DB::table('attendance_comments')->where('id', $r->id)->update(['date' => substr((string) $r->attendance_date, 0, 10)]);
        }
        $roles = DB::table('attendance_comments as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->whereNull('c.author_role')->get(['c.id', 'u.role']);
        foreach ($roles as $r) {
            DB::table('attendance_comments')->where('id', $r->id)->update(['author_role' => $r->role]);
        }

        $this->report('Added');
        $this->line('Comment days filled: '.$dates->count().', roles filled: '.$roles->count());
        if (collect($this->added)->contains(fn ($l) => str_contains($l, 'gets_texts'))) {
            $this->warn('child_people.gets_texts is new, so no family link has Texts ticked yet: tick it under Family & pick-up.');
        }
        $this->line('Now run: php artisan schema:check');

        return self::SUCCESS;
    }

    private function add(string $table, array $columns, bool $dry): void
    {
        $todo = array_filter($columns, fn ($c) => ! Schema::hasColumn($table, $c), ARRAY_FILTER_USE_KEY);
        if ($todo === []) {
            return;
        }
        if (! $dry) {
            Schema::table($table, function (Blueprint $t) use ($todo) {
                foreach ($todo as $make) {
                    $make($t);
                }
            });
        }
        $this->added[] = $table.': '.implode(', ', array_keys($todo));
    }

    private function create(string $table, \Closure $definition, bool $dry): void
    {
        if (Schema::hasTable($table)) {
            return;
        }
        if (! $dry) {
            Schema::create($table, $definition);
        }
        $this->added[] = 'table '.$table;
    }

    private function report(string $verb): void
    {
        if ($this->added === []) {
            $this->info('Nothing was missing.');

            return;
        }
        $this->info($verb.':');
        foreach ($this->added as $line) {
            $this->line('  '.$line);
        }
    }
}
