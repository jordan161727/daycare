<?php

namespace App\Console\Commands;

use RuntimeException;

/**
 * Raised to undo a dry run, and caught immediately.
 *
 * Throwing is what rolls a transaction back — returning from the closure
 * commits it — so a command that wants to do the work and keep none of it has
 * to leave by this door.
 *
 * A class of its own rather than a bare RuntimeException so the catch can name
 * exactly what it is swallowing. A catch broad enough to hide a real failure
 * in the middle of a migration is worse than no dry run at all.
 */
class DryRunComplete extends RuntimeException
{
}
