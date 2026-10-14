<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChildDocumentImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        Storage::fake('local');
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    }

    public function test_uploading_a_form_keeps_the_document_and_redirects_to_the_review_page(): void
    {
        $this->fakeExtraction(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $response = $this->actingAs($this->admin)->post(route('children.document-import.store'), [
            'document' => UploadedFile::fake()->create('enrollment.pdf', 40, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/review', $response->headers->get('Location'));
        $this->assertCount(1, Storage::disk('local')->files('child-imports'));
    }

    public function test_the_review_page_shows_the_form_and_the_document_side_by_side(): void
    {
        $token = $this->importDocument([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'mother_name' => 'Anne Byron',
            'birth_date' => '12/10/2021',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('children.document-import.review', $token))
            ->assertOk();

        // Extracted values are pre-filled and flagged.
        $response->assertSee('value="Ada"', false);
        $response->assertSee('value="Anne Byron"', false);
        $response->assertSee('From form');

        // Dates are normalised to the date input's format.
        $response->assertSee('value="2021-10-12"', false);

        // The document is embedded next to the form.
        $response->assertSee(route('children.document-import.file', $token), false);
        $response->assertSee('order-2 min-w-0 lg:order-1', false);  // form column, left
        $response->assertSee('order-1 min-w-0 lg:order-2', false);  // document column, right
        $response->assertSee('name="import_token" value="'.$token.'"', false);
    }

    public function test_the_review_page_prefills_the_next_lan(): void
    {
        Child::create(['lan' => '1070', 'status' => 'Active', 'first_name' => 'A', 'last_name' => 'B', 'classroom' => 'Infant']);

        $token = $this->importDocument(['first_name' => 'Ada']);

        $this->actingAs($this->admin)
            ->get(route('children.document-import.review', $token))
            ->assertOk()
            // Shown, not asked for: the LAN is issued on save.
            ->assertSee('value="10001" class="cs-input" readonly', false);
    }

    public function test_the_stored_document_is_streamed_inline(): void
    {
        $token = $this->importDocument(['first_name' => 'Ada']);

        $response = $this->actingAs($this->admin)
            ->get(route('children.document-import.file', $token))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->actingAs($this->admin)->get(route('children.document-import.review', 'nope'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('children.document-import.file', 'nope'))->assertNotFound();
    }

    public function test_saving_the_child_discards_the_pending_document(): void
    {
        $token = $this->importDocument(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->assertCount(1, Storage::disk('local')->files('child-imports'));

        $this->actingAs($this->admin)->post(route('children.store'), [
            'lan' => '1071',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Infant',
            'status' => 'Active',
            'import_token' => $token,
        ])->assertRedirect(route('children.index'));

        $this->assertDatabaseHas('children', ['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->assertCount(0, Storage::disk('local')->files('child-imports'));
    }

    public function test_saving_a_child_without_an_import_leaves_storage_alone(): void
    {
        $token = $this->importDocument(['first_name' => 'Ada']);

        $this->actingAs($this->admin)->post(route('children.store'), [
            'lan' => '2000',
            'first_name' => 'Someone',
            'last_name' => 'Else',
            'classroom' => 'Infant',
            'status' => 'Active',
        ])->assertRedirect(route('children.index'));

        $this->assertCount(1, Storage::disk('local')->files('child-imports'), 'the unrelated pending import survives');
        $this->assertNotNull(session('child_imports.'.$token));
    }

    public function test_a_failed_extraction_reports_an_error_and_stores_nothing(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('nope', 500)]);

        $this->actingAs($this->admin)
            ->post(route('children.document-import.store'), [
                'document' => UploadedFile::fake()->create('enrollment.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasErrors('document');

        $this->assertCount(0, Storage::disk('local')->files('child-imports'));
    }

    public function test_a_missing_api_key_is_reported_before_uploading(): void
    {
        config(['services.gemini.key' => null]);

        $this->actingAs($this->admin)
            ->post(route('children.document-import.store'), [
                'document' => UploadedFile::fake()->create('enrollment.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasErrors('document');
    }

    public function test_only_pdf_and_images_are_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('children.document-import.store'), [
                'document' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ])
            ->assertSessionHasErrors('document');
    }

    public function test_teachers_cannot_import_documents(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Infant']);

        $this->actingAs($teacher)->get(route('children.document-import.create'))->assertForbidden();
    }

    /** Runs the upload through the controller and returns the pending import token. */
    /**
     * A pile of forms goes in at once: each is read on its own, the first
     * opens for review, and saving (or skipping) a review moves to the next
     * in the order they were given. A scan that cannot be read is named and
     * set aside; the rest still go through.
     */
    public function test_several_forms_queue_for_review_in_order_and_saving_moves_to_the_next(): void
    {
        $this->fakeExtraction(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $response = $this->actingAs($this->admin)->post(route('children.document-import.store'), [
            'pages' => 'separate',
            'documents' => [
                UploadedFile::fake()->create('ada.pdf', 40, 'application/pdf'),
                UploadedFile::fake()->create('ben.pdf', 40, 'application/pdf'),
                UploadedFile::fake()->create('cleo.pdf', 40, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect();
        $this->assertCount(3, Storage::disk('local')->files('child-imports'));
        $first = basename(dirname($response->headers->get('Location')));

        // The review says where this form sits in the pile.
        $this->actingAs($this->admin)->get(route('children.document-import.review', $first))->assertOk()
            ->assertSee('Form 1 of 3')
            ->assertSee('2 more waiting')
            ->assertSee('ada.pdf')
            ->assertSee('Skip this form');

        // Saving the first opens the second.
        $save = $this->actingAs($this->admin)->post(route('children.store'), [
            'lan' => '1071', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'classroom' => 'Infant', 'status' => 'Active', 'import_token' => $first,
        ]);
        $save->assertRedirect();
        $this->assertStringContainsString('/review', $save->headers->get('Location'));
        $save->assertSessionHas('success', fn (string $m) => str_contains($m, '2 forms still to review'));
        $this->assertCount(2, Storage::disk('local')->files('child-imports'));

        $second = basename(dirname($save->headers->get('Location')));
        $this->actingAs($this->admin)->get(route('children.document-import.review', $second))->assertOk()
            ->assertSee('Form 1 of 2')
            ->assertSee('ben.pdf');

        // Skipping the second opens the third; skipping the third ends the pile.
        $skip = $this->actingAs($this->admin)->post(route('children.document-import.skip', $second));
        $skip->assertRedirect();
        $third = basename(dirname($skip->headers->get('Location')));
        $this->actingAs($this->admin)->get(route('children.document-import.review', $third))->assertOk()
            ->assertSee('cleo.pdf')
            ->assertDontSee('more waiting');

        $this->actingAs($this->admin)->post(route('children.document-import.skip', $third))
            ->assertRedirect(route('children.document-import.create'));
        $this->assertCount(0, Storage::disk('local')->files('child-imports'));

        // The last save with nothing waiting goes back to the roll, as it always did.
        $this->assertDatabaseHas('children', ['first_name' => 'Ada']);
    }

    public function test_a_form_that_cannot_be_read_is_set_aside_and_the_rest_go_through(): void
    {
        $good = ['candidates' => [['content' => ['parts' => [['text' => json_encode(['first_name' => 'Ada', 'last_name' => 'Lovelace'])]]]]]];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($good)
            ->push('nope', 500)
            ->push($good)]);

        $response = $this->actingAs($this->admin)->post(route('children.document-import.store'), [
            'pages' => 'separate',
            'documents' => [
                UploadedFile::fake()->create('one.pdf', 40, 'application/pdf'),
                UploadedFile::fake()->create('smudged.pdf', 40, 'application/pdf'),
                UploadedFile::fake()->create('three.pdf', 40, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect()->assertSessionHas('unread_documents', ['smudged.pdf']);
        $this->assertCount(2, Storage::disk('local')->files('child-imports'));

        $first = basename(dirname($response->headers->get('Location')));
        $this->actingAs($this->admin)->get(route('children.document-import.review', $first))->assertOk()
            ->assertSee('Form 1 of 2')
            ->assertSee('Could not be read and set aside: smudged.pdf');
    }

    /**
     * Two photos of a two-page form are one child: both pages go to the
     * reader in one request, the review shows both, and the page-two details
     * that have no field of their own — allergies, pediatrician, insurance,
     * the sunscreen brand — are written into the notes rather than lost.
     */
    public function test_the_pages_of_one_form_are_read_together_and_page_two_lands_in_the_notes(): void
    {
        $this->fakeExtraction([
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'other_notes' => 'Picked up by grandma on Fridays.',
            'known_allergies' => 'Eggs, Nuts, and dogs', 'special_needs' => '',
            'pediatrician' => 'Tonawanda Ped.', 'pediatrician_address' => '3950 E. Robinson Rd', 'pediatrician_telephone' => '7166913400',
            'insurance_carrier' => 'Highmark', 'insurance_membership' => '10977739', 'insurance_group' => 'BXF13291120100',
            'child_health_plus_info' => 'no', 'emergency_treatment_consent' => 'yes',
            'sunscreen_brand' => 'Banana Boat', 'diaper_ointment_brand' => 'aquaphor',
        ]);

        $response = $this->actingAs($this->admin)->post(route('children.document-import.store'), [
            'documents' => [
                UploadedFile::fake()->image('page-1.jpg', 800, 1000),
                UploadedFile::fake()->image('page-2.jpg', 800, 1000),
            ],
        ]);

        // One child, one request carrying both pages, two files kept.
        $response->assertRedirect();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => count(array_filter($request->data()['contents'][0]['parts'], fn ($part) => isset($part['inline_data']))) === 2
            && str_contains($request->data()['contents'][0]['parts'][0]['text'], 'pages of ONE form'));
        $this->assertCount(2, Storage::disk('local')->files('child-imports'));

        $token = basename(dirname($response->headers->get('Location')));
        $page = $this->actingAs($this->admin)->get(route('children.document-import.review', $token))->assertOk();

        // Both pages in the panel, each at its own address.
        $page->assertSee('Page 1 · page-1.jpg')->assertSee('Page 2 · page-2.jpg')
            ->assertSee(route('children.document-import.file', [$token, 'page' => 2]), false);
        $this->actingAs($this->admin)->get(route('children.document-import.file', [$token, 'page' => 2]))->assertOk();
        $page->assertDontSee('more waiting');

        // Page two, in the notes: medical under Important, the rest under Other, after what was already there.
        $html = $page->getContent();
        $this->assertStringContainsString("Known allergies: Eggs, Nuts, and dogs\nPediatrician: Tonawanda Ped., 3950 E. Robinson Rd, 7166913400", $html);
        $this->assertStringContainsString("Picked up by grandma on Fridays.\nInsurance: Highmark\nMembership #: 10977739\nGroup #: BXF13291120100\nEmergency treatment consent: yes\nChild Health Plus info: no\nSunscreen brand: Banana Boat\nDiaper rash ointment brand: aquaphor", $html);
        $this->assertStringNotContainsString('Special needs:', $html);

        // Saving the child keeps the notes and drops both pages.
        $this->actingAs($this->admin)->post(route('children.store'), [
            'lan' => '1071', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'classroom' => 'Infant', 'status' => 'Active', 'import_token' => $token,
            'important_notes' => "Known allergies: Eggs, Nuts, and dogs", 'other_notes' => 'Insurance: Highmark',
        ])->assertRedirect(route('children.index'));
        $this->assertDatabaseHas('children', ['first_name' => 'Ada', 'important_notes' => 'Known allergies: Eggs, Nuts, and dogs']);
        $this->assertCount(0, Storage::disk('local')->files('child-imports'));
    }

    /**
     * A pile left half-reviewed is not forgotten: the import page names what
     * is still waiting, offers to carry on, and offers to throw it away. A
     * save says which form comes next. A leftover used to sit unseen in the
     * session and surface after the next save as a form nobody remembered.
     */
    public function test_forms_left_waiting_are_named_on_the_import_page_and_can_be_discarded(): void
    {
        $this->fakeExtraction(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->actingAs($this->admin)->post(route('children.document-import.store'), [
            'pages' => 'separate',
            'documents' => [
                UploadedFile::fake()->create('ada.pdf', 40, 'application/pdf'),
                UploadedFile::fake()->create('ben.pdf', 40, 'application/pdf'),
            ],
        ]);
        $first = \App\Http\Controllers\ChildDocumentController::nextToken();

        // Walk away, come back: the pile is named.
        $this->actingAs($this->admin)->get(route('children.document-import.create'))->assertOk()
            ->assertSee('2 forms still waiting for review:')
            ->assertSee('ada.pdf, ben.pdf')
            ->assertSee('Continue reviewing')
            ->assertSee(route('children.document-import.review', $first), false);

        // A save names the next one.
        $this->actingAs($this->admin)->post(route('children.store'), [
            'lan' => '1071', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'classroom' => 'Infant', 'status' => 'Active', 'import_token' => $first,
        ])->assertSessionHas('success', 'Ada Lovelace added. 1 form still to review — next: ben.pdf.');

        // And the pile can be thrown away.
        $this->actingAs($this->admin)->post(route('children.document-import.clear'))
            ->assertRedirect(route('children.document-import.create'))
            ->assertSessionHas('success', '1 waiting form discarded.');
        $this->assertCount(0, Storage::disk('local')->files('child-imports'));
        $this->actingAs($this->admin)->get(route('children.document-import.create'))->assertOk()->assertDontSee('still waiting for review');
    }

    /**
     * The same form imported twice does not make the same child twice: the
     * save is refused by name, naming the record on file, unless the person
     * ticks "Save anyway" because this really is a different child. Two
     * children with the same name and different birth dates are not a
     * duplicate, and editing a record is never a duplicate of itself.
     */
    public function test_a_child_already_on_file_is_not_saved_again_from_an_import_unless_asked(): void
    {
        $existing = \App\Models\Child::create(['lan' => '1050', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'status' => 'Active', 'classroom' => 'Infant', 'dob' => '2024-03-02']);
        $token = $this->importDocument(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'birth_date' => '2024-03-02']);
        $form = ['lan' => '1071', 'first_name' => ' ada ', 'last_name' => 'LOVELACE', 'birth_date' => '2024-03-02', 'classroom' => 'Infant', 'status' => 'Active', 'import_token' => $token];

        // Refused, naming the record — and the form is still waiting.
        $this->actingAs($this->admin)->post(route('children.store'), $form)->assertSessionHasErrors('duplicate');
        $message = session('errors')->first('duplicate');
        $this->assertStringStartsWith('Ada Lovelace is already on file — LAN 1050, ', $message);
        $this->assertStringContainsString('born Mar 2, 2024. Open that record instead, or tick "Save anyway" if this is a different child.', $message);
        $this->assertSame(1, \App\Models\Child::count());
        $this->assertCount(1, Storage::disk('local')->files('child-imports'));
        $this->actingAs($this->admin)->get(route('children.document-import.review', $token))->assertOk();

        // A different birth date is a different child.
        $this->actingAs($this->admin)->post(route('children.store'), ['birth_date' => '2025-01-15'] + $form)->assertSessionDoesntHaveErrors('duplicate');
        $this->assertSame(2, \App\Models\Child::count());

        // So is "Save anyway".
        $this->actingAs($this->admin)->post(route('children.store'), ['allow_duplicate' => '1', 'import_token' => null] + $form)->assertSessionDoesntHaveErrors('duplicate');
        $this->assertSame(3, \App\Models\Child::count());

        // Editing the original is not a duplicate of itself.
        $this->actingAs($this->admin)->put(route('children.update', $existing), ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'birth_date' => '2024-03-02', 'classroom' => 'Infant', 'status' => 'Active'])
            ->assertSessionDoesntHaveErrors('duplicate');
    }

    private function importDocument(array $fields): string
    {
        $this->fakeExtraction($fields);

        $response = $this->actingAs($this->admin)->post(route('children.document-import.store'), [
            'document' => UploadedFile::fake()->create('enrollment.pdf', 40, 'application/pdf'),
        ]);

        $location = $response->headers->get('Location');

        return basename(dirname($location));
    }

    private function fakeExtraction(array $fields): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($fields)]]]]],
        ])]);
    }
}
