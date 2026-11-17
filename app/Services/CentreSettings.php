<?php

namespace App\Services;

use App\Models\RoomSchedule;
use App\Models\RoomTabletCode;
use App\Models\Setting;
use App\Models\StaffDevice;

/**
 * The centre's rooms and hours, as Settings → Rooms & hours has them.
 *
 * Phase 1.2 of docs/work.md. Everything the floor plan, the dashboard and
 * the schedule need to know about a room — its ratio, its capacity, New
 * York's largest group for its age, its hours — and about the day — when
 * the centre opens and closes, the earliest anybody but a keyholder starts,
 * the early drop-off room, when School Age leaves and returns, the longest
 * shift and the most hours in a week — read from one place.
 *
 * A value the director has saved (the settings table) wins; otherwise the
 * figure in config/daycare.php stands, so a fresh install reads sensibly
 * before anybody has opened the page.
 */
class CentreSettings
{
    public const KEYS = [
        'centre_opens' => ['label' => 'Center opens', 'default' => '07:00'],
        'centre_closes' => ['label' => 'Center closes', 'default' => '18:00'],
        'earliest_start' => ['label' => 'Earliest start for everyone else', 'default' => '07:30'],
        'early_dropoff_room' => ['label' => 'Early drop-off room', 'default' => 'Infant'],
        'school_age_leaves' => ['label' => 'School Age leaves for school', 'default' => '08:30'],
        'school_age_returns' => ['label' => 'School Age back from school', 'default' => '15:00'],
        'longest_shift_hours' => ['label' => 'Longest shift (hours)', 'default' => 10],
        'max_week_hours' => ['label' => 'Most hours in a week', 'default' => 40],
    ];

    public function ratio(string $room): int
    {
        return (int) Setting::get("rooms.$room.ratio", config("daycare.ratios.$room", 0));
    }

    public function capacity(string $room): int
    {
        return (int) Setting::get("rooms.$room.capacity", config("daycare.capacity.$room", 0));
    }

    public function nyMaxGroup(string $room): int
    {
        return (int) config("daycare.ny_max_group.$room", 0);
    }

    public function ages(string $room): string
    {
        // From the same numbers the automatic room and the ⚠ are checked by.
        return (string) ClassroomAssignment::ageLabel($room);
    }

    /**
     * The centre's timezone. Every "today" — the header, a calendar's
     * highlighted day, the first date a form allows — is the centre's, not
     * the browser's or the server's (QA retest, 18 Nov 2026).
     */
    public static function timezone(): string
    {
        return (string) config('app.timezone');
    }

    /** Today at the centre, as Y-m-d. */
    public static function today(): string
    {
        return now(self::timezone())->toDateString();
    }

    /**
     * The room whose physical space this room uses, or null for a room of
     * its own (QA retest, 16 Nov 2026: UPK-4 and School Age are one room of
     * 20, and were each warned as if they had 20 places to themselves).
     */
    public function sharesWith(string $room): ?string
    {
        $host = Setting::get("rooms.$room.shares_with", config("daycare.shared_rooms.$room"));

        return filled($host) && $host !== $room && in_array($host, ClassroomAssignment::rooms(), true)
            ? (string) $host
            : null;
    }

    /** Every room in $room's space, in room order, itself included. */
    public function space(string $room): array
    {
        $host = $this->sharesWith($room) ?? $room;

        return array_values(array_filter(
            ClassroomAssignment::rooms(),
            fn (string $r) => $r === $host || $this->sharesWith($r) === $host,
        ));
    }

    /** The places in $room's space: its host room's capacity. */
    public function spaceCapacity(string $room): int
    {
        return $this->capacity($this->sharesWith($room) ?? $room);
    }

    /** "UPK-4 + School Age (shared room)", or the room's own name. */
    public function spaceLabel(string $room): string
    {
        $rooms = $this->space($room);

        return count($rooms) > 1 ? implode(' + ', $rooms).' (shared room)' : $room;
    }

    /** A centre-wide value by key — see KEYS. */
    /**
     * The four digits that tell a room tablet which room it is in.
     *
     * Plain rather than hashed: it is read back on the Rooms & hours page for
     * the director to hand to whoever moves a tablet, and it chooses a room,
     * nothing more — see RoomTabletController::setup. In room_tablet_codes
     * since QA K4 (16 Nov 2026), where each code is unique.
     */
    public function tabletCode(string $room): ?string
    {
        $code = RoomTabletCode::codes()[$room] ?? null;

        return blank($code) ? null : (string) $code;
    }

    /** The room a code names, or null for a code nobody set. */
    public function roomForTabletCode(string $code): ?string
    {
        foreach (RoomTabletCode::codes() as $room => $held) {
            if (in_array($room, ClassroomAssignment::rooms(), true) && hash_equals((string) $held, $code)) {
                return $room;
            }
        }

        return null;
    }

    /** "1 tablet · seen 10:28a", or "No tablet yet", for the Rooms table. */
    public function tabletsLine(string $room): string
    {
        $tablets = StaffDevice::tabletsIn($room);

        if ($tablets->isEmpty()) {
            return 'No tablet yet';
        }

        $seen = $tablets->first()->last_seen_at;

        return $tablets->count().' tablet'.($tablets->count() === 1 ? '' : 's')
            .($seen ? ' · seen '.($seen->isToday() ? $seen->format('g:ia') : $seen->format('D g:ia')) : ' · never used');
    }

    public function get(string $key): mixed
    {
        $default = self::KEYS[$key]['default'] ?? null;
        $value = Setting::get("centre.$key", null);

        if ($value === null) {
            // Until the page is saved, School Age's return hour comes from config.
            return $key === 'school_age_returns' ? config('daycare.school_age_returns_at', $default) : $default;
        }

        return is_int($default) ? (int) $value : $value;
    }

    /** Every room as a row for the table: the figures, the hours, and the check. */
    public function rooms(): array
    {
        $hours = RoomSchedule::byRoom();
        // Today's enrolled and expected, room by room, by the one rule
        // (RoomCounts) — so a room booked past its places is said here too.
        $today = RoomCounts::byRoom(now(config('app.timezone'))->toDateString());
        // Checked by physical space: rooms that share one are added together.
        $capacityWarnings = RoomCounts::capacityWarnings($today, $this);
        $rows = [];

        foreach (ClassroomAssignment::rooms() as $room) {
            $capacity = $this->capacity($room);
            $max = $this->nyMaxGroup($room);
            $schedule = $hours[$room] ?? null;
            $overBooked = $capacityWarnings[$room] ?? null;
            $check = match (true) {
                $overBooked !== null => ['tone' => 'warn', 'text' => $overBooked],
                $max > 0 && $capacity > $max => ['tone' => 'warn', 'text' => "Above NY's group of {$max} — runs as two groups"],
                $schedule && $this->minutes($schedule->closes_at) > $this->minutes($this->get('centre_closes')) => ['tone' => 'warn', 'text' => 'Closes after the center does'],
                $schedule && $this->minutes($schedule->opens_at) < $this->minutes($this->get('centre_opens')) => ['tone' => 'warn', 'text' => 'Opens before the center does'],
                default => ['tone' => 'ok', 'text' => 'OK'],
            };

            $rows[] = [
                'room' => $room,
                'ages' => $this->ages($room),
                'ratio' => $this->ratio($room),
                'capacity' => $capacity,
                'enrolled' => $today[$room]['enrolled'] ?? 0,
                'expected' => $today[$room]['expected'] ?? 0,
                'ny_max' => $max,
                'opens_at' => $schedule ? substr((string) $schedule->opens_at, 0, 5) : null,
                'closes_at' => $schedule ? substr((string) $schedule->closes_at, 0, 5) : null,
                'check' => $check,
                // The room whose space this one uses, if any (config daycare.shared_rooms).
                'shares_with' => $this->sharesWith($room),
                // The room tablet's code and what is paired to it (HANDOFF §11).
                'tablet_code' => $this->tabletCode($room),
                'tablets' => $this->tabletsLine($room),
            ];
        }

        return $rows;
    }

    /**
     * What disagrees with what, across the saved values. Said on the page
     * after a save, so a close time that contradicts the rooms, or a return
     * before a departure, is seen rather than found on the floor.
     */
    public function warnings(): array
    {
        $out = [];
        $opens = $this->minutes($this->get('centre_opens'));
        $closes = $this->minutes($this->get('centre_closes'));

        if ($this->minutes($this->get('earliest_start')) < $opens) {
            $out[] = 'The earliest start for everyone else is before the center opens — only staff who may open the building can start then.';
        }
        if ($this->minutes($this->get('school_age_returns')) <= $this->minutes($this->get('school_age_leaves'))) {
            $out[] = 'School Age comes back before it leaves — check the two times.';
        }
        if ((int) $this->get('longest_shift_hours') * 60 > $closes - $opens) {
            $out[] = 'The longest shift is longer than the center is open.';
        }
        if ((int) $this->get('max_week_hours') < (int) $this->get('longest_shift_hours')) {
            $out[] = 'The most hours in a week is less than one longest shift.';
        }
        foreach ($this->rooms() as $row) {
            if ($row['check']['tone'] === 'warn') {
                $text = $row['check']['text'];
                // A shared room's warning already names the space; said once.
                $line = str_contains($text, '(shared room)') ? "{$text}." : "{$row['room']}: {$text}.";
                if (! in_array($line, $out, true)) {
                    $out[] = $line;
                }
            }
        }
        if (! in_array($this->get('early_dropoff_room'), ClassroomAssignment::rooms(), true)) {
            $out[] = 'The early drop-off room is not one of the rooms.';
        }

        return $out;
    }

    /**
     * Children on the roll whose agreed hours fall outside when their room
     * or the centre is open, by Child::hoursOutsideOpening — listed on Rooms
     * & hours so they can be put right (second QA brief, Nov 2026: children
     * agreed until 8:00 PM against a centre that closes at 6). Nothing is
     * changed; this only says who.
     *
     * @return \Illuminate\Support\Collection<int, array{child: \App\Models\Child, text: string}>
     */
    public function childrenOutsideHours(): \Illuminate\Support\Collection
    {
        return \App\Models\Child::query()
            ->whereIn('status', ['Active', 'Pending'])
            ->where(fn ($q) => $q->whereNotNull('drop_off_time')->orWhereNotNull('pick_up_time'))
            ->orderBy('lan')
            ->get()
            ->map(fn ($child) => ['child' => $child, 'text' => \App\Models\Child::hoursOutsideOpening($child->drop_off_time, $child->pick_up_time, $child->classroom)])
            ->filter(fn ($row) => $row['text'] !== null)
            ->values();
    }

    /** "07:30" (or "07:30:00") as minutes past midnight; 0 when blank. */
    public function minutes(mixed $time): int
    {
        if (blank($time)) {
            return 0;
        }
        [$h, $m] = array_map('intval', explode(':', substr((string) $time, 0, 5)));

        return $h * 60 + $m;
    }
}
