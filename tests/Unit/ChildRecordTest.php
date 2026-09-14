<?php

namespace Tests\Unit;

use App\Models\Child;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What a child record works out about itself.
 *
 * Every one of these is derived on read rather than stored, so none of them can
 * drift out of step with the columns behind it — and none of them needs a
 * database to be asked. The records here are never saved.
 */
class ChildRecordTest extends TestCase
{
    /* ---------------------------------------------------------------- names */

    public function test_a_name_is_written_the_way_the_reader_asked_for_it(): void
    {
        $child = $this->child(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->assertSame('Ada Lovelace', $child->displayName('first_last'));
        $this->assertSame('Lovelace, Ada', $child->displayName('last_first'));
    }

    public function test_a_format_nothing_defines_falls_back_rather_than_inventing_one(): void
    {
        // A stored preference that is no longer offered — a third format tried
        // and dropped — must not print a name in a shape nothing here defines.
        $this->assertSame('Ada Lovelace', $this->child()->displayName('initials'));
    }

    public function test_half_a_name_does_not_leave_its_punctuation_behind(): void
    {
        // Records exist with one half of the name blank. "Lovelace, " and
        // " Lovelace" are both wrong in a way that looks like a bug.
        $surnameOnly = $this->child(['first_name' => '', 'last_name' => 'Lovelace']);

        $this->assertSame('Lovelace', $surnameOnly->displayName('last_first'));
        $this->assertSame('Lovelace', $surnameOnly->displayName('first_last'));
    }

    /* ----------------------------------------------------------------- ages */

    public function test_the_date_of_birth_is_padded_so_a_column_of_them_lines_up(): void
    {
        // Unpadded, 2023/6/15 and 2023/12/5 are different widths and the
        // slashes stop lining up down a column of sixty.
        $this->assertSame('2023/06/15', Child::ageLabelFor(Carbon::parse('2023-06-15')));
        $this->assertSame('2023/12/05', Child::ageLabelFor(Carbon::parse('2023-12-05')));
        $this->assertNull(Child::ageLabelFor(null));
    }

    public function test_the_date_of_birth_comes_from_whichever_column_holds_it(): void
    {
        // `dob` came first and `birth_date` arrived with the enrolment form;
        // records exist with either, and reading only the newer one opened
        // those records blank — which then saved the date away.
        $this->assertSame('2023-06-15', $this->child(['dob' => '2023-06-15'])->birthDate()->toDateString());
        $this->assertSame('2024-01-02', $this->child(['birth_date' => '2024-01-02'])->birthDate()->toDateString());

        // The newer column wins where a record carries both.
        $both = $this->child(['dob' => '2023-06-15', 'birth_date' => '2024-01-02']);
        $this->assertSame('2024-01-02', $both->birthDate()->toDateString());

        $this->assertNull($this->child()->birthDate());
    }

    public function test_an_age_always_says_both_halves(): void
    {
        $this->travelTo(Carbon::parse('2026-08-28'));

        $this->assertSame('3y 2m', $this->child(['birth_date' => '2023-06-15'])->ageInWords());

        // A birthday keeps its "0m" and a baby keeps its "0y": dropping either
        // is what made two ages in a column impossible to compare.
        $this->assertSame('3y 0m', $this->child(['birth_date' => '2023-08-28'])->ageInWords());
        $this->assertSame('0y 7m', $this->child(['birth_date' => '2026-01-28'])->ageInWords());
    }

    public function test_a_baby_not_yet_a_month_old_is_counted_in_days(): void
    {
        $this->travelTo(Carbon::parse('2026-08-28'));

        // "0y 0m" is not an age, and the infant room takes babies at six weeks.
        $this->assertSame('12d', $this->child(['birth_date' => '2026-08-16'])->ageInWords());
        $this->assertSame('0d', $this->child(['birth_date' => '2026-08-28'])->ageInWords());
    }

    public function test_an_age_needs_a_birth_date_that_has_happened(): void
    {
        $this->travelTo(Carbon::parse('2026-08-28'));

        $this->assertNull($this->child()->ageInWords());
        // A date ahead of today is somebody's typo, not a negative age.
        $this->assertNull($this->child(['birth_date' => '2027-01-01'])->ageInWords());
    }

    /* ---------------------------------------------------------------- times */

    public function test_a_time_has_one_shape_for_prose_and_one_for_the_sheet(): void
    {
        // The grid is sixty rows by five columns, so "7:00 AM" costs four
        // characters three hundred times over; a record read in prose does not.
        $this->assertSame('7:00 AM', Child::timeLabel('07:00'));
        $this->assertSame('7:00a', Child::timeShort('07:00'));
        $this->assertSame('12:28p', Child::timeShort('12:28:00'));

        $this->assertNull(Child::timeLabel(null));
        $this->assertNull(Child::timeShort(null));
    }

    public function test_a_time_read_back_out_of_the_database_still_fits_the_field(): void
    {
        // MySQL hands these back as H:i:s, and <input type="time"> wants H:i.
        $this->assertSame('08:30', Child::timeInputValue('08:30:00'));
        $this->assertSame('08:30', Child::timeInputValue('08:30'));
    }

    public function test_a_contracted_day_needs_both_ends_agreed(): void
    {
        $this->assertSame(
            '8:30 AM – 5:30 PM',
            $this->child(['drop_off_time' => '08:30', 'pick_up_time' => '17:30'])->scheduleLabel()
        );

        // One end alone is not a day, so it reads as nothing agreed rather than
        // as half an answer.
        $this->assertNull($this->child(['drop_off_time' => '08:30'])->scheduleLabel());
        $this->assertNull($this->child()->scheduleLabel());
    }

    /* ------------------------------------------------------- days and rooms */

    public function test_the_registered_days_read_as_a_phrase(): void
    {
        $this->assertSame('Mon, Wed, Fri', $this->child(['schedule_days' => [1, 3, 5]])->scheduleDaysLabel());

        // The commonest arrangement gets the shortest label: it is the one read
        // most often down a column of sixty children.
        $this->assertSame('Every day', $this->child(['schedule_days' => [1, 2, 3, 4, 5]])->scheduleDaysLabel());
    }

    public function test_never_said_and_no_days_are_different_answers(): void
    {
        // Null is every record that predates the question. [] is somebody
        // saying this child is on the roll and not currently coming.
        $this->assertNull($this->child()->scheduleDaysLabel());
        $this->assertNull($this->child()->scheduleDays());

        $this->assertSame('No days', $this->child(['schedule_days' => []])->scheduleDaysLabel());
        $this->assertSame([], $this->child(['schedule_days' => []])->scheduleDays());
    }

    public function test_the_stored_days_are_cleaned_up_on_the_way_out(): void
    {
        // Out of order, duplicated, and carrying a weekend the centre never
        // opens for — none of which should reach the week builder.
        $child = $this->child(['schedule_days' => [5, 1, 1, 6, 0]]);

        $this->assertSame([1, 5], $child->scheduleDays());
    }

    public function test_the_registered_pattern_answers_for_a_given_date(): void
    {
        $child = $this->child(['schedule_days' => [1, 3]]);

        $this->assertTrue($child->attendsOn('2026-09-14'));   // Monday
        $this->assertFalse($child->attendsOn('2026-09-15'));  // Tuesday
        $this->assertTrue($child->attendsOn(Carbon::parse('2026-09-16')));

        // Nobody has said, so nothing is claimed — a day nobody asked for is
        // never silently scheduled.
        $this->assertFalse($this->child()->attendsOn('2026-09-14'));
    }

    public function test_only_school_age_is_signed_in_by_half_day(): void
    {
        $this->assertSame(['AM', 'PM'], $this->child(['classroom' => 'School Age'])->sessions());

        foreach (['Infant', 'Transition', 'Toddler', 'PreK', 'UPK-4'] as $room) {
            $this->assertSame(['FULL'], $this->child(['classroom' => $room])->sessions(), $room.' should be a whole day');
        }
    }

    /* ------------------------------------------------------------ enrolment */

    public function test_a_record_with_no_dates_has_always_been_here(): void
    {
        // Every record behaved this way before enrolment dates existed.
        $this->assertTrue($this->child()->isEnrolledOn('2020-01-01'));
        $this->assertTrue($this->child()->isEnrolledOn('2030-01-01'));
    }

    public function test_enrolment_starts_on_the_start_date_and_ends_on_the_last_day(): void
    {
        $child = $this->child(['enrolled_on' => '2026-09-15', 'withdrawn_on' => '2026-09-17']);

        $this->assertFalse($child->isEnrolledOn('2026-09-14'));
        // Both ends are theirs: the first day they attend and the last one.
        $this->assertTrue($child->isEnrolledOn('2026-09-15'));
        $this->assertTrue($child->isEnrolledOn('2026-09-17'));
        $this->assertFalse($child->isEnrolledOn('2026-09-18'));
    }

    /** An unsaved record. None of the above needs one that has been written. */
    private function child(array $attributes = []): Child
    {
        return new Child($attributes + [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
        ]);
    }
}
