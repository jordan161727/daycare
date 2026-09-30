<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A photograph of the child: uploaded on their record, shown wherever they are
 * listed, and — because it is a picture of somebody's four-year-old — kept off
 * the public web and served only to staff who may already see the child.
 */
class ChildPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_photo_uploaded_on_the_form_is_kept(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin())
            ->put(route('children.update', $child), $this->form($child, ['photo' => UploadedFile::fake()->image('ada.jpg')]))
            ->assertRedirect();

        $path = $child->fresh()->photo_path;

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        // Never the public disk: that one is reachable by URL alone.
        $this->assertStringStartsWith('children/', $path);
    }

    public function test_a_new_photo_replaces_the_old_file_rather_than_leaving_it(): void
    {
        $child = $this->child();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('children.update', $child), $this->form($child, ['photo' => UploadedFile::fake()->image('first.jpg')]));
        $first = $child->fresh()->photo_path;

        $this->actingAs($admin)->put(route('children.update', $child), $this->form($child, ['photo' => UploadedFile::fake()->image('second.jpg')]));
        $second = $child->fresh()->photo_path;

        $this->assertNotSame($first, $second);
        // A replaced photo left on disk is a picture of a child nothing points at.
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_the_photo_can_be_removed(): void
    {
        $child = $this->child();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('children.update', $child), $this->form($child, ['photo' => UploadedFile::fake()->image('ada.jpg')]));
        $path = $child->fresh()->photo_path;

        $this->actingAs($admin)->put(route('children.update', $child), $this->form($child, ['remove_photo' => '1']))->assertRedirect();

        $this->assertNull($child->fresh()->photo_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_photo_survives_an_edit_that_does_not_mention_it(): void
    {
        $child = $this->child();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('children.update', $child), $this->form($child, ['photo' => UploadedFile::fake()->image('ada.jpg')]));
        $path = $child->fresh()->photo_path;

        $this->actingAs($admin)->put(route('children.update', $child), $this->form($child, ['first_name' => 'Adelaide']))->assertRedirect();

        $this->assertSame($path, $child->fresh()->photo_path);
    }

    public function test_a_file_that_is_not_an_image_is_rejected(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin())
            ->put(route('children.update', $child), $this->form($child, ['photo' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')]))
            ->assertSessionHasErrors('photo');

        $this->assertNull($child->fresh()->photo_path);
    }

    public function test_a_new_child_can_be_added_with_a_photo(): void
    {
        $this->actingAs($this->admin())
            ->post(route('children.store'), [
                'lan' => '2002',
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
                'status' => 'Active',
                'photo' => UploadedFile::fake()->image('grace.jpg'),
            ])
            ->assertRedirect();

        // Found by name: the LAN posted above is ignored and one issued.
        $this->assertNotNull(Child::firstWhere('first_name', 'Grace')->photo_path);
    }

    public function test_the_photo_is_served_to_staff_who_may_see_the_child(): void
    {
        $child = $this->childWithPhoto();

        $this->actingAs($this->admin())->get(route('children.photo', $child))->assertOk();

        $this->actingAs(User::factory()->create(['role' => 'teacher', 'classroom' => 'Infant']))
            ->get(route('children.photo', $child))
            ->assertOk();
    }

    public function test_the_photo_is_not_served_to_anybody_else(): void
    {
        $child = $this->childWithPhoto();

        $this->actingAs(User::factory()->create(['role' => 'teacher', 'classroom' => 'PreK']))
            ->get(route('children.photo', $child))
            ->assertForbidden();

        auth()->logout();
        $this->get(route('children.photo', $child))->assertRedirect(route('login'));
    }

    public function test_a_child_with_no_photo_has_nothing_to_serve(): void
    {
        $this->actingAs($this->admin())->get(route('children.photo', $this->child()))->assertNotFound();
    }

    public function test_the_roster_shows_the_photo_in_place_of_the_drawn_face(): void
    {
        $child = $this->childWithPhoto();

        $this->actingAs($this->admin())
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee(route('children.photo', $child), escape: false)
            ->assertDontSee('data-child-avatar', escape: false);
    }

    public function test_the_attendance_sheet_carries_the_same_face(): void
    {
        // Drawn once on the server and handed to the sheet as markup, so the
        // roster, the record and the sheet cannot end up drawing it three ways.
        $child = $this->child(['gender' => 'Girl']);

        $html = $this->actingAs($this->admin())
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('x-html="child.avatar"', $html);
        $this->assertStringContainsString('data-child-avatar', $html);
        $this->assertStringNotContainsString('charAt(0)', $html, 'An initial is being worked out in the browser again.');
    }

    public function test_the_roster_draws_the_same_face_as_the_sheet(): void
    {
        $this->child(['gender' => 'Girl']);

        $this->actingAs($this->admin())
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('data-child-avatar', escape: false);
    }

    public function test_the_record_can_open_the_photo_full_size(): void
    {
        $child = $this->childWithPhoto();

        $html = $this->actingAs($this->admin())->get(route('children.show', $child))->assertOk()->getContent();

        // Twice: the tile in the header, and the one the popup opens.
        $this->assertSame(2, substr_count($html, route('children.photo', $child)));
    }

    public function test_there_is_nothing_to_open_when_there_is_no_photo(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin())
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertDontSee('max-h-[85vh]', escape: false);
    }

    public function test_a_child_with_no_photo_is_drawn_one(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin())
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertSee('data-child-avatar', escape: false)
            ->assertSee($child->first_name.' '.$child->last_name);
    }

    public function test_the_drawn_face_is_the_same_one_every_time(): void
    {
        // A face that changed on each render would be worse than the letter it
        // replaced: nobody could learn to recognise it.
        $child = $this->child();
        $admin = $this->admin();

        $first = $this->actingAs($admin)->get(route('children.show', $child))->getContent();
        $second = $this->actingAs($admin)->get(route('children.show', $child))->getContent();

        $this->assertSame(1, preg_match('/data-child-avatar="(\w+)"/', $first, $matches));
        $this->assertStringContainsString('data-child-avatar="'.$matches[1].'"', $second);
    }

    public function test_two_children_are_not_all_drawn_alike(): void
    {
        collect(['Ada', 'Grace', 'Katherine', 'Radia', 'Barbara', 'Frances', 'Jean', 'Margaret'])
            ->each(fn ($name, $index) => $this->child([
                'lan' => (string) (2000 + $index),
                'first_name' => $name,
                'last_name' => 'Example',
                'gender' => 'Girl',
            ]));

        $html = $this->actingAs($this->admin())->get(route('children.index'))->assertOk()->getContent();

        preg_match_all('/data-child-avatar="(\w+)"/', $html, $matches);

        $this->assertGreaterThan(1, count(array_unique($matches[1])), 'Every child was drawn the same face.');
    }

    /**
     * A girl is never drawn a boy's face, whatever her name happens to hash to.
     * Every LAN is tried, so this holds for the whole cycle of the styles
     * rather than for the one number the first child happened to get.
     */
    /*
     * The stand-in is one of twelve illustrated portraits on a sprite — six
     * that read as girls, six as boys — and data-child-avatar carries which.
     * The rule is the one the drawn face had: the group comes from the
     * record's gender column, never from the name.
     */
    public function test_a_girl_is_drawn_a_girls_face(): void
    {
        $this->assertDrawnFrom('Girl', ['1', '3', '4', '6', '9', '11']);
    }

    public function test_a_boy_is_drawn_a_boys_face(): void
    {
        $this->assertDrawnFrom('Boy', ['0', '2', '5', '7', '8', '10']);
    }

    public function test_a_child_whose_record_does_not_say_is_drawn_the_grey_silhouette(): void
    {
        // Not a guess from the name — the record does not say, so no face is
        // picked at all: the plain grey head-and-shoulders, every time.
        $this->assertDrawnFrom(null, ['none'], atLeastTwo: false);

        $html = $this->render($this->child());

        $this->assertStringContainsString('data-child-avatar="none"', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('portraits.webp', $html);
    }

    /*
     * The description the centre's sheet keeps beside the gender — "blonde
     * long girl", "brunet short hair boy" — picks the portrait that reads
     * closest to it, and says girl or boy where the column is blank.
     */
    public function test_the_description_picks_the_portrait_that_reads_like_it(): void
    {
        $cases = [
            ['Boy', 'short hair brown', ['0']],
            ['Girl', 'blonde long girl', ['3']],
            ['Boy', 'brunet short hair boy', ['0']],
            ['Girl', 'brown girl', ['9']],
            ['Boy', 'black boy', ['2', '10']],
            ['Girl', 'black girl', ['4']],
            ['Girl', 'red hair', ['6']],
            ['Girl', 'braids', ['4', '11']],
            ['Girl', 'pigtails', ['1']],
            ['Girl', 'hispanic short hair', ['11']],
            // The registration sheet's own shape: labelled fields, where
            // "Brown" under skin tone is the skin and never the hair.
            ['Boy', 'Race / Skin Tone: Brown; Hair Length: Short', ['7']],
            ['Girl', 'Race / Skin Tone: White; Hair Color: Blonde; Hair Length: Long', ['3']],
            ['Girl', 'Race / Skin Tone: White; Hair Color: Blonde; Hair Length: Very Short', ['3']],
            ['Boy', 'Race / Skin Tone: White; Hair Color: Brunette; Hair Length: Short', ['0']],
            ['Girl', 'Race / Skin Tone: White; Hair Color: Brunette', ['9']],
            ['Girl', 'Race / Skin Tone: Black; Other Features: Braids', ['4']],
            ['Boy', 'Race / Skin Tone: Black; Hair Length: Bald', ['2', '10']],
            ['Girl', 'Race / Skin Tone: Hispanic', ['11']],
            ['Boy', 'Race / Skin Tone: Hispanic', ['7']],
            ['Girl', 'Race / Skin Tone: Chinese', ['1']],
            ['Girl', 'Race / Skin Tone: Brown', ['11']],
        ];

        foreach ($cases as $number => [$gender, $description, $expected]) {
            $child = $this->child(['lan' => (string) (4000 + $number), 'gender' => $gender, 'description' => $description]);

            preg_match('/data-child-avatar="(\w+)"/', $this->render($child), $matches);

            $this->assertContains($matches[1] ?? null, $expected, "'$description' was drawn portrait ".($matches[1] ?? 'none'));
        }
    }

    public function test_the_description_says_girl_or_boy_when_the_column_is_blank(): void
    {
        $girl = $this->child(['lan' => '4101', 'description' => 'blonde long girl']);
        $boy = $this->child(['lan' => '4102', 'description' => 'black boy']);
        $both = $this->child(['lan' => '4103', 'description' => 'boy or girl, nobody said']);
        $neither = $this->child(['lan' => '4104', 'description' => 'blonde long hair']);

        preg_match('/data-child-avatar="(\w+)"/', $this->render($girl), $m);
        $this->assertSame('3', $m[1]);
        preg_match('/data-child-avatar="(\w+)"/', $this->render($boy), $m);
        $this->assertContains($m[1], ['2', '10']);
        // A description that says both, or neither, is a record that does not say.
        $this->assertStringContainsString('data-child-avatar="none"', $this->render($both));
        $this->assertStringContainsString('data-child-avatar="none"', $this->render($neither));
    }

    public function test_the_column_wins_over_the_description(): void
    {
        // The form is the record; the sheet's note is a hint. A girl whose
        // description says "boy" by mistake is still drawn a girl's face.
        $child = $this->child(['gender' => 'Girl', 'description' => 'brown short hair boy']);

        preg_match('/data-child-avatar="(\w+)"/', $this->render($child), $m);

        $this->assertSame('9', $m[1]);
    }

    public function test_the_form_records_which_it_is(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin())
            ->put(route('children.update', $child), $this->form($child, ['gender' => 'Girl']))
            ->assertRedirect();

        $this->assertSame('Girl', $child->fresh()->gender);
    }

    public function test_anything_that_is_not_one_of_the_two_is_rejected(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin())
            ->put(route('children.update', $child), $this->form($child, ['gender' => 'Wizard']))
            ->assertSessionHasErrors('gender');
    }

    /** Renders a run of children of one gender and checks every face drawn. */
    private function assertDrawnFrom(?string $gender, array $expected, bool $atLeastTwo = true): void
    {
        $seen = collect(range(1, 24))->map(function (int $number) use ($gender) {
            $child = $this->child(['lan' => (string) (3000 + $number), 'first_name' => 'Child'.$number, 'gender' => $gender]);

            preg_match('/data-child-avatar="(\w+)"/', $this->render($child), $matches);

            return $matches[1] ?? null;
        })->unique()->values();

        $this->assertEmpty(
            $seen->diff($expected)->all(),
            'Drawn a face from outside the set: '.$seen->diff($expected)->join(', ')
        );
        if ($atLeastTwo) {
            $this->assertGreaterThan(1, $seen->count(), 'Only one face was ever drawn.');
        }
    }

    /** The component alone, as the roster and the sheet both render it. */
    private function render(Child $child): string
    {
        return view('components.child-avatar', [
            'child' => $child,
            'size' => 'h-9 w-9',
            'shape' => 'rounded-full',
            'attributes' => new \Illuminate\View\ComponentAttributeBag,
        ])->render();
    }

    private function childWithPhoto(): Child
    {
        $child = $this->child();

        $this->actingAs($this->admin())
            ->put(route('children.update', $child), $this->form($child, ['photo' => UploadedFile::fake()->image('ada.jpg')]));

        return $child->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function child(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '1001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Infant',
            'classroom_override' => 'Infant',
        ]);
    }

    /** The record as the form posts it, with whatever this test is changing. */
    private function form(Child $child, array $overrides = []): array
    {
        return $overrides + [
            'lan' => $child->lan,
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'status' => $child->status,
            'classroom_override' => $child->classroom_override,
        ];
    }
}
