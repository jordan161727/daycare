<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Whether an account is still on the password somebody else chose for it.
 *
 * The middleware that locks a teacher to the change form reads nothing but
 * this, so a flag that came back from the database as the string "0" and
 * tested truthy would quietly trap every account on the centre.
 */
class UserPasswordStateTest extends TestCase
{
    public function test_an_ordinary_account_is_not_asked_to_change_anything(): void
    {
        $this->assertFalse((new User())->mustChangePassword());
    }

    public function test_it_is_true_only_while_the_flag_is_set(): void
    {
        $this->assertTrue($this->userWith(true)->mustChangePassword());
        $this->assertFalse($this->userWith(false)->mustChangePassword());
    }

    /**
     * SQLite hands booleans back as 0 and 1, MySQL as "0" and "1". Both have
     * to mean the same thing, and "0" is the one that is truthy in PHP.
     */
    public function test_the_shapes_a_database_hands_back_all_mean_the_same_thing(): void
    {
        foreach ([1, '1', true] as $set) {
            $this->assertTrue($this->userWith($set)->mustChangePassword(), 'Failed for '.var_export($set, true));
        }

        foreach ([0, '0', false, null] as $clear) {
            $this->assertFalse($this->userWith($clear)->mustChangePassword(), 'Failed for '.var_export($clear, true));
        }
    }

    public function test_the_moment_they_chose_their_own_is_a_date(): void
    {
        $user = new User();
        $user->password_changed_at = '2026-08-18 07:02:00';

        $this->assertInstanceOf(Carbon::class, $user->password_changed_at);
        $this->assertSame('2026-08-18 07:02', $user->password_changed_at->format('Y-m-d H:i'));
    }

    public function test_an_account_that_never_changed_its_password_has_no_date(): void
    {
        $this->assertNull((new User())->password_changed_at);
    }

    private function userWith(mixed $flag): User
    {
        $user = new User();
        $user->must_change_password = $flag;

        return $user;
    }
}
