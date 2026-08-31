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

        $this->assertNotNull(Child::firstWhere('lan', '2002')->photo_path);
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
    public function test_a_girl_is_drawn_a_girls_face(): void
    {
        $this->assertDrawnFrom('Girl', ['bunches', 'topknot', 'long', 'curls']);
    }

    public function test_a_boy_is_drawn_a_boys_face(): void
    {
        $this->assertDrawnFrom('Boy', ['bowl', 'sweep', 'crop', 'hat']);
    }

    public function test_a_child_whose_record_does_not_say_is_drawn_neutrally(): void
    {
        // Not a guess from the name — the record does not say, so the face does
        // not either.
        $this->assertDrawnFrom(null, ['bowl', 'curls', 'hat', 'sweep']);
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
    private function assertDrawnFrom(?string $gender, array $expected): void
    {
        $seen = collect(range(1, 24))->map(function (int $number) use ($gender) {
            $child = $this->child(['lan' => (string) (3000 + $number), 'first_name' => 'Child'.$number, 'gender' => $gender]);

            $html = view('components.child-avatar', [
                'child' => $child,
                'size' => 'h-9 w-9',
                'shape' => 'rounded-full',
                'attributes' => new \Illuminate\View\ComponentAttributeBag,
            ])->render();

            preg_match('/data-child-avatar="(\w+)"/', $html, $matches);

            return $matches[1] ?? null;
        })->unique()->values();

        $this->assertEmpty(
            $seen->diff($expected)->all(),
            'Drawn a face from outside the set: '.$seen->diff($expected)->join(', ')
        );
        $this->assertGreaterThan(1, $seen->count(), 'Only one face was ever drawn.');
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
