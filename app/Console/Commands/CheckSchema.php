<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Does this database have every table and column the migrations make?
 *
 * The migrations table can say a migration ran while its columns are not
 * there — a table restored from an older backup, a copy brought over from
 * another server — and then `migrate` says "nothing to migrate" while pages
 * fail with "Unknown column". This builds the database the migrations
 * describe in a throwaway in-memory SQLite, and lists every table and column
 * the real database is missing. It only reads the real database.
 */
class CheckSchema extends Command
{
    protected $signature = 'schema:check';

    protected $description = 'List tables and columns the migrations make that this database is missing (read only)';

    public function handle(): int
    {
        $real = config('database.default');

        if (! extension_loaded('pdo_sqlite')) {
            $this->error('This PHP has no pdo_sqlite, which the check needs for its throwaway copy.');

            return self::FAILURE;
        }

        // The reference: every migration run into memory. The default
        // connection is switched for the duration, so a migration that
        // writes with DB::table() writes into the copy, never the real one.
        config(['database.connections.schema_probe' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        $this->line('Building the reference copy from the migrations…');
        try {
            config(['database.default' => 'schema_probe']);
            DB::purge('schema_probe');
            DB::setDefaultConnection('schema_probe');
            Artisan::call('migrate', ['--database' => 'schema_probe', '--force' => true]);
            $expected = $this->columns('schema_probe');
        } finally {
            config(['database.default' => $real]);
            DB::setDefaultConnection($real);
        }

        $actual = $this->columns($real);
        unset($expected['migrations']);

        $missingTables = [];
        $missingColumns = [];
        foreach ($expected as $table => $cols) {
            if (! isset($actual[$table])) {
                $missingTables[] = $table;

                continue;
            }
            $gone = array_values(array_diff($cols, $actual[$table]));
            if ($gone !== []) {
                $missingColumns[$table] = $gone;
            }
        }

        $this->info('Checked '.count($expected).' tables on the "'.$real.'" connection ('.DB::connection($real)->getDatabaseName().').');

        if ($missingTables === [] && $missingColumns === []) {
            $this->info('Nothing missing: every table and column the migrations make is here.');

            return self::SUCCESS;
        }

        if ($missingTables !== []) {
            $this->newLine();
            $this->warn('Missing tables ('.count($missingTables).'):');
            foreach ($missingTables as $t) {
                $this->line('  '.$t);
            }
        }
        if ($missingColumns !== []) {
            $this->newLine();
            $this->warn('Missing columns:');
            foreach ($missingColumns as $t => $cols) {
                $this->line('  '.$t.': '.implode(', ', $cols));
            }
        }

        // Which migration makes each missing thing, so the fix can follow it.
        $this->newLine();
        $this->line('Made by:');
        foreach ($this->sources(array_merge($missingTables, array_keys($missingColumns)), $missingColumns) as $line) {
            $this->line('  '.$line);
        }

        return self::FAILURE;
    }

    /** @return array<string, list<string>> table => columns */
    private function columns(string $connection): array
    {
        $schema = Schema::connection($connection);
        $out = [];
        // Only this connection's own database. Without it, MySQL lists the
        // tables of every database the user can see, and a table missing
        // here but present next door would read as an empty one.
        foreach ($schema->getTableListing($schema->getCurrentSchemaListing(), false) as $table) {
            if (str_starts_with($table, 'sqlite_')) {
                continue;
            }
            $out[$table] = $schema->getColumnListing($table);
        }
        ksort($out);

        return $out;
    }

    /** The migration files that mention each missing table, with the missing columns they mention. */
    private function sources(array $tables, array $missingColumns): array
    {
        $lines = [];
        foreach (glob(database_path('migrations/*.php')) as $file) {
            $text = file_get_contents($file);
            foreach (array_unique($tables) as $table) {
                if (! preg_match("/Schema::(create|table)\\(\\s*'".preg_quote($table, '/')."'/", $text)) {
                    continue;
                }
                $cols = array_values(array_filter($missingColumns[$table] ?? [], function ($c) use ($text) {
                    return str_contains($text, "'".$c."'") || ($c === 'deleted_at' && str_contains($text, 'softDeletes'))
                        || (in_array($c, ['created_at', 'updated_at'], true) && str_contains($text, 'timestamps'));
                }));
                if (isset($missingColumns[$table]) && $cols === []) {
                    continue;
                }
                $lines[] = basename($file).' → '.$table.($cols !== [] ? ' ('.implode(', ', $cols).')' : '');
            }
        }

        return $lines;
    }
}
