<?php

use App\Models\Child;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

/**
 * Every LAN becomes a five-digit number, counting from 10001.
 *
 * The centre asked for five digits. The roll was issued from 1001, so the
 * numbers are renumbered rather than padded: 01001 is five characters but it
 * is still the old number wearing a zero, and a register with a leading zero
 * in it invites somebody to drop it back off.
 *
 * Order is preserved. The child with the lowest LAN today gets 10001, so the
 * roll reads in the same sequence it always did and "the older number is the
 * older child" stays true. Records with a non-numeric LAN — there are some
 * from before the sequence existed — come after the numbered ones, oldest
 * record first, because there is no number in them to sort by.
 *
 * Nothing in the database points at a LAN: attendance, slots and documents all
 * reference the child's id. What this breaks is paper — a LAN is how a file in
 * the cabinet names a child — so the old and new numbers are written to
 * storage/app/lan-renumber-<timestamp>.csv for whoever has to re-key them, and
 * that same file is what makes this migration reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $children = Child::query()
            ->get(['id', 'lan'])
            // Numbered first and in their own order, the rest behind them and
            // oldest first. One composite key rather than two sorts: sortBy is
            // stable, but reading the order off a single string is one thing to
            // check rather than two to reason about the interaction of.
            ->sortBy(fn ($child) => ctype_digit((string) $child->lan)
                ? '0:'.str_pad((string) (int) $child->lan, 12, '0', STR_PAD_LEFT)
                : '1:'.str_pad((string) $child->id, 12, '0', STR_PAD_LEFT))
            ->values();

        if ($children->isEmpty()) {
            return;
        }

        $mapping = [];
        // The same constant the sequence issues from, so the renumbering and
        // everything after it cannot start from two different places.
        $next = Child::LAN_STARTS_AT;

        foreach ($children as $child) {
            $mapping[] = [$child->id, $child->lan, (string) $next];
            $next++;
        }

        // Written before the change, so a failure part-way through still
        // leaves a record of what the numbers were.
        $csv = "child_id,old_lan,new_lan\n";
        foreach ($mapping as [$id, $old, $new]) {
            $csv .= $id.','.$old.','.$new."\n";
        }
        Storage::disk('local')->put($this->ledger(), $csv);

        // The two ranges do not overlap — 1001-9999 against 10001 up — so each
        // row can be written directly without the unique index tripping over a
        // number that is about to be freed.
        foreach ($mapping as [$id, , $new]) {
            Child::whereKey($id)->update(['lan' => $new]);
        }
    }

    public function down(): void
    {
        $path = $this->ledger();

        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException(
                "The old LANs were written to storage/app/{$path} and that file is gone, "
                .'so there is nothing to put back. Restore the file or the database.'
            );
        }

        $rows = array_filter(explode("\n", Storage::disk('local')->get($path)));
        array_shift($rows);   // the header

        foreach ($rows as $row) {
            [$id, $old] = explode(',', $row);
            Child::whereKey($id)->update(['lan' => $old]);
        }
    }

    /** One file for this migration, named for it rather than for the moment it ran. */
    private function ledger(): string
    {
        return 'lan-renumber.csv';
    }
};
