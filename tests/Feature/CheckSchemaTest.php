<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** schema:check — a column a migration makes but the database lacks is named. */
class CheckSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_complete_database_passes(): void
    {
        $this->artisan('schema:check')
            ->expectsOutputToContain('Nothing missing')
            ->assertExitCode(0);
    }

    public function test_a_missing_column_is_named_with_its_migration(): void
    {
        Schema::table('attendance_comments', function ($t) {
            $t->dropIndex(['child_id', 'date']);
            $t->dropIndex(['date']);
        });
        Schema::table('attendance_comments', fn ($t) => $t->dropColumn(['deleted_at', 'date']));

        $this->artisan('schema:check')
            ->expectsOutputToContain('attendance_comments: date, deleted_at')
            ->expectsOutputToContain('2026_10_20_000001_add_day_fields_to_attendance_comments_table.php')
            ->assertExitCode(1);

        // The check reads; it never puts the column back on the real connection.
        $this->assertFalse(Schema::hasColumn('attendance_comments', 'deleted_at'));
    }
}
