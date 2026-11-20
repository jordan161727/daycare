<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\ParentAccount;
use App\Models\Person;
use App\Services\Texter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A parent's own account (docs/parent-app-api.md §4.18): the first sign-in is
 * a texted code, then a username and password, a photo and a name; after
 * that, the username (or mobile) and password sign in, and the code is how a
 * forgotten password is replaced.
 */
class ParentAccountTest extends TestCase
{
    use RefreshDatabase;

    private array $texts = [];

    private Child $mia;

    private Person $kate;

    protected function setUp(): void
    {
        parent::setUp();

        $texts = &$this->texts;
        $this->app->instance(Texter::class, new class($texts) extends Texter
        {
            public function __construct(private array &$sent) {}

            public function send(string $from, string $to, string $body, array $mediaUrls = []): bool
            {
                $this->sent[] = compact('from', 'to', 'body');

                return true;
            }
        });

        Storage::fake('local');

        $this->mia = Child::create(['lan' => '10001', 'status' => 'Active', 'first_name' => 'Mia', 'last_name' => 'Testwell', 'classroom' => 'Toddler']);
        $this->kate = Person::create(['name' => 'Kate Testwell', 'cell' => '(585) 555-0134']);
        ChildPerson::create(['child_id' => $this->mia->id, 'person_id' => $this->kate->id, 'relationship' => 'Mother', 'gets_texts' => true, 'is_guardian' => true]);
    }

    public function test_after_the_first_code_a_parent_sets_up_an_account_and_then_signs_in_with_it(): void
    {
        $verify = $this->codeSignIn();
        $verify->assertJsonPath('parent.account', null)->assertJsonPath('parent.can_reset_password', true);
        $token = $verify->json('token');

        $this->as($token)->postJson('/api/parent/account', ['username' => 'Kate.T', 'password' => 'sunshine22', 'password_confirmation' => 'sunshine22'])
            ->assertCreated()
            ->assertJsonPath('account.username', 'kate.t')
            ->assertJsonPath('account.profile_done', false);

        $this->as($token)->patchJson('/api/parent/account', ['display_name' => 'Katie', 'email' => 'kate@example.test', 'profile_done' => true])
            ->assertOk()
            ->assertJsonPath('account.display_name', 'Katie')
            ->assertJsonPath('account.profile_done', true);

        // Signed out; back in with the username, any case, or the mobile number.
        foreach (['KATE.T', '(585) 555-0134'] as $login) {
            $this->postJson('/api/parent/login/password', ['login' => $login, 'password' => 'sunshine22', 'device_name' => 'Pixel'])
                ->assertOk()
                ->assertJsonPath('parent.name', 'Kate Testwell')
                ->assertJsonPath('parent.account.username', 'kate.t')
                ->assertJsonPath('parent.can_reset_password', false)
                ->assertJsonPath('parent.children.0.first_name', 'Mia');
        }
    }

    public function test_a_wrong_password_or_unknown_username_says_the_same_thing(): void
    {
        $this->accountFor($this->kate);

        foreach ([['kate.t', 'wrong-pass1'], ['nobody', 'sunshine22'], ['585-555-0199', 'sunshine22']] as [$login, $password]) {
            $this->postJson('/api/parent/login/password', ['login' => $login, 'password' => $password])
                ->assertStatus(422)
                ->assertJsonPath('errors.password.0', 'That username or password is wrong.');
        }
    }

    public function test_a_parent_who_may_no_longer_use_the_app_cannot_sign_in_with_a_password(): void
    {
        $this->accountFor($this->kate);
        ChildPerson::where('person_id', $this->kate->id)->update(['gets_texts' => false]);

        $this->postJson('/api/parent/login/password', ['login' => 'kate.t', 'password' => 'sunshine22'])->assertStatus(422);
    }

    public function test_setup_checks_the_username_and_password_and_happens_once(): void
    {
        $other = Person::create(['name' => 'Dan Testwell', 'cell' => '(585) 555-0135']);
        $this->accountFor($other, 'dan');
        $token = $this->codeSignIn()->json('token');

        $this->as($token)->postJson('/api/parent/account', ['username' => 'DAN', 'password' => 'sunshine22', 'password_confirmation' => 'sunshine22'])
            ->assertStatus(422)->assertJsonPath('errors.username.0', 'That username is taken. Try another.');
        $this->as($token)->postJson('/api/parent/account', ['username' => 'kate t', 'password' => 'sunshine22', 'password_confirmation' => 'sunshine22'])
            ->assertStatus(422)->assertJsonValidationErrors('username');
        $this->as($token)->postJson('/api/parent/account', ['username' => 'kate', 'password' => 'short1', 'password_confirmation' => 'short1'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->as($token)->postJson('/api/parent/account', ['username' => 'kate', 'password' => 'sunshine22', 'password_confirmation' => 'sunshine23'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'The two passwords don’t match.');

        $this->as($token)->postJson('/api/parent/account', ['username' => 'kate', 'password' => 'sunshine22', 'password_confirmation' => 'sunshine22'])->assertCreated();
        $this->as($token)->postJson('/api/parent/account', ['username' => 'kate2', 'password' => 'sunshine22', 'password_confirmation' => 'sunshine22'])->assertStatus(409);
    }

    public function test_a_parent_adds_and_removes_a_profile_photo(): void
    {
        $this->accountFor($this->kate);
        $token = $this->passwordSignIn();

        $url = $this->as($token)->post('/api/parent/account/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 400, 400)], ['Accept' => 'application/json'])
            ->assertOk()->json('account.photo_url');
        $this->assertStringContainsString('/messages/media?', $url);
        $path = ParentAccount::first()->photo_path;
        $this->assertStringStartsWith('messages/media/parent-'.$this->kate->id.'/profile/', $path);
        Storage::disk('local')->assertExists($path);

        $this->as($token)->post('/api/parent/account/photo', ['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->as($token)->deleteJson('/api/parent/account/photo')->assertOk()->assertJsonPath('account.photo_url', null);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_changing_the_password_needs_the_old_one_and_signs_out_other_phones(): void
    {
        $this->accountFor($this->kate);
        $phone1 = $this->passwordSignIn();
        $phone2 = $this->passwordSignIn();

        $this->as($phone1)->putJson('/api/parent/account/password', ['password' => 'moonlight33', 'password_confirmation' => 'moonlight33'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->as($phone1)->putJson('/api/parent/account/password', ['current_password' => 'nope', 'password' => 'moonlight33', 'password_confirmation' => 'moonlight33'])
            ->assertStatus(422)->assertJsonPath('errors.current_password.0', 'Your current password is wrong.');
        $this->as($phone1)->putJson('/api/parent/account/password', ['current_password' => 'sunshine22', 'password' => 'moonlight33', 'password_confirmation' => 'moonlight33'])
            ->assertOk();

        $this->as($phone1)->getJson('/api/parent/me')->assertOk();
        $this->as($phone2)->getJson('/api/parent/me')->assertUnauthorized();
        $this->postJson('/api/parent/login/password', ['login' => 'kate.t', 'password' => 'moonlight33'])->assertOk();
    }

    public function test_forgot_password_a_fresh_code_sign_in_sets_a_new_one_without_the_old(): void
    {
        $this->accountFor($this->kate);
        $token = $this->codeSignIn()->assertJsonPath('parent.can_reset_password', true)->json('token');

        $this->as($token)->putJson('/api/parent/account/password', ['password' => 'moonlight33', 'password_confirmation' => 'moonlight33'])->assertOk();
        $this->postJson('/api/parent/login/password', ['login' => 'kate.t', 'password' => 'moonlight33'])->assertOk();

        // Not for long: twenty minutes on, the old password is needed again.
        $this->travel(20)->minutes();
        $this->as($token)->getJson('/api/parent/me')->assertJsonPath('can_reset_password', false);
        $this->as($token)->putJson('/api/parent/account/password', ['password' => 'starlight44', 'password_confirmation' => 'starlight44'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_profile_changes_need_an_account_first(): void
    {
        $token = $this->codeSignIn()->json('token');

        $this->as($token)->patchJson('/api/parent/account', ['display_name' => 'Katie'])->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->postJson('/api/parent/account', ['username' => 'kate', 'password' => 'sunshine22', 'password_confirmation' => 'sunshine22'])->assertUnauthorized();
    }

    /* ---------------------------------------------------------------- */

    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function codeSignIn(): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->postJson('/api/parent/login/start', ['phone' => '5855550134'])->assertOk();
        preg_match('/code is (\d{6})/', (string) end($this->texts)['body'], $m);

        return $this->postJson('/api/parent/login/verify', ['phone' => '5855550134', 'code' => $m[1] ?? ''])->assertOk();
    }

    private function passwordSignIn(): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->postJson('/api/parent/login/password', ['login' => 'kate.t', 'password' => 'sunshine22'])->assertOk()->json('token');
    }

    private function accountFor(Person $person, string $username = 'kate.t'): void
    {
        ParentAccount::create(['person_id' => $person->id, 'username' => $username, 'password' => 'sunshine22']);
    }
}
