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
            ->assertSee('name="lan" value="1071"', false);
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

        $this->assertDatabaseHas('children', ['lan' => '1071', 'first_name' => 'Ada']);
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
