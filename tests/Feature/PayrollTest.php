<?php

namespace Tests\Feature;

use App\Mail\PayslipMail;
use App\Models\PayrollBatch;
use App\Models\User;
use App\Services\PayrollPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::create([
            'name' => 'Director', 'email' => 'director@example.com',
            'password' => 'password', 'role' => 'admin',
        ]);
    }

    public function test_each_page_is_matched_to_the_staff_member_named_on_it(): void
    {
        $maria = $this->teacher('Maria Santos', 'Maria G. Santos');
        $grace = $this->teacher('Grace Lee', 'Grace Y. Lee');

        $slips = app(PayrollPdf::class)->analyse(
            $this->payrollPdf(['Employee: Maria G. Santos', 'Employee: Grace Y. Lee']),
            User::teachers()->get()
        );

        $this->assertCount(2, $slips);
        $this->assertSame($maria->id, $slips[0]['user_id']);
        $this->assertSame($grace->id, $slips[1]['user_id']);
    }

    public function test_a_middle_initial_on_only_one_side_still_matches(): void
    {
        // Payroll prints "Maria G. Santos"; the record may hold either form.
        $maria = $this->teacher('Maria Santos', 'Maria Santos');

        $slips = app(PayrollPdf::class)->analyse(
            $this->payrollPdf(['Employee: Maria G. Santos']),
            User::teachers()->get()
        );

        $this->assertSame($maria->id, $slips[0]['user_id']);
    }

    public function test_consecutive_pages_for_one_person_become_a_single_payslip(): void
    {
        $this->teacher('Maria Santos', 'Maria G. Santos');

        $slips = app(PayrollPdf::class)->analyse(
            $this->payrollPdf([
                'Employee: Maria G. Santos page one',
                'Employee: Maria G. Santos page two',
            ]),
            User::teachers()->get()
        );

        $this->assertCount(1, $slips);
        $this->assertSame([0, 1], $slips[0]['pages']);
    }

    public function test_the_same_name_reappearing_later_is_kept_separate(): void
    {
        // Far more likely a bad match than a two-part payslip, and merging it
        // would attach a stranger's page to somebody's email.
        $this->teacher('Maria Santos', 'Maria G. Santos');
        $this->teacher('Grace Lee', 'Grace Y. Lee');

        $slips = app(PayrollPdf::class)->analyse(
            $this->payrollPdf([
                'Employee: Maria G. Santos',
                'Employee: Grace Y. Lee',
                'Employee: Maria G. Santos',
            ]),
            User::teachers()->get()
        );

        $this->assertCount(3, $slips);
    }

    public function test_a_page_naming_nobody_on_staff_is_left_unassigned(): void
    {
        $this->teacher('Maria Santos', 'Maria G. Santos');

        $slips = app(PayrollPdf::class)->analyse(
            $this->payrollPdf(['Employee: Somebody Else Entirely']),
            User::teachers()->get()
        );

        $this->assertNull($slips[0]['user_id']);
    }

    public function test_an_ambiguous_page_is_left_unassigned_rather_than_guessed(): void
    {
        // A wrong match sends a teacher somebody else's pay. Unmatched asks a
        // human instead, which is the only safe way to be wrong here.
        $this->teacher('Grace Lee', 'Grace Lee');
        $this->teacher('Paul Lee', 'Paul Lee');

        $slips = app(PayrollPdf::class)->analyse(
            $this->payrollPdf(['Grace Lee and Paul Lee both appear here']),
            User::teachers()->get()
        );

        $this->assertNull($slips[0]['user_id']);
    }

    public function test_the_pay_period_and_check_date_are_read_off_the_page(): void
    {
        $dates = app(PayrollPdf::class)->dates('Pay Period: 07/09/2026 - 07/22/2026   Check Date: 07/26/2026');

        $this->assertSame('2026-07-09', $dates['period_start']);
        $this->assertSame('2026-07-22', $dates['period_end']);
        $this->assertSame('2026-07-26', $dates['check_date']);
    }

    public function test_a_page_with_no_dates_reports_none_rather_than_guessing(): void
    {
        $dates = app(PayrollPdf::class)->dates('Employee: Maria G. Santos');

        $this->assertNull($dates['period_start']);
        $this->assertNull($dates['check_date']);
    }

    public function test_extracting_a_payslip_returns_only_that_persons_pages(): void
    {
        $path = $this->payrollPdf(['Page one', 'Page two', 'Page three']);
        $pdf = app(PayrollPdf::class);

        $extracted = $pdf->extract($path, [1]);

        $this->assertStringStartsWith('%PDF', $extracted);

        $out = tempnam(sys_get_temp_dir(), 'slip').'.pdf';
        file_put_contents($out, $extracted);

        $this->assertSame(1, $pdf->pageCount($out));
        $this->assertStringContainsString('Page two', implode(' ', $pdf->pageText($out)));

        @unlink($out);
    }

    public function test_uploading_a_payroll_run_creates_a_slip_per_employee(): void
    {
        $this->teacher('Maria Santos', 'Maria G. Santos');
        $this->teacher('Grace Lee', 'Grace Y. Lee');

        $this->actingAs($this->admin)
            ->post(route('payroll.store'), [
                'file' => $this->uploadedPdf(['Employee: Maria G. Santos', 'Employee: Grace Y. Lee']),
                'period_label' => 'Jul 9 - Jul 22, 2026',
            ])
            ->assertRedirect();

        $batch = PayrollBatch::sole();

        $this->assertSame(2, $batch->slips()->count());
        $this->assertSame(2, $batch->page_count);
        Storage::disk('local')->assertExists($batch->path);
    }

    public function test_a_file_that_is_not_a_pdf_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('payroll.store'), ['file' => UploadedFile::fake()->create('payroll.xlsx', 10)])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, PayrollBatch::count());
    }

    public function test_sending_a_payslip_mails_only_that_persons_pages(): void
    {
        Mail::fake();

        $maria = $this->teacher('Maria Santos', 'Maria G. Santos');
        $this->teacher('Grace Lee', 'Grace Y. Lee');

        $batch = $this->uploadBatch(['Employee: Maria G. Santos', 'Employee: Grace Y. Lee']);
        $slip = $batch->slips()->where('user_id', $maria->id)->sole();

        $this->actingAs($this->admin)
            ->post(route('payroll.send', [$batch, $slip]), [
                'subject' => 'Your payslip{period_dash}',
                'body' => 'Hi {first_name}, here is your payslip{period_for}.',
            ])
            ->assertRedirect();

        Mail::assertSent(PayslipMail::class, function (PayslipMail $mail) use ($maria) {
            return $mail->hasTo($maria->email)
                && str_contains($mail->bodyText, 'Hi Maria,')
                && str_contains($mail->subjectLine, 'Jul 9');
        });

        $this->assertTrue($slip->fresh()->isSent());
    }

    public function test_a_copy_to_the_director_does_not_mark_the_payslip_sent(): void
    {
        Mail::fake();

        $maria = $this->teacher('Maria Santos', 'Maria G. Santos');
        $batch = $this->uploadBatch(['Employee: Maria G. Santos']);
        $slip = $batch->slips()->sole();

        $this->actingAs($this->admin)
            ->post(route('payroll.send', [$batch, $slip]), [
                'subject' => 'Payslip',
                'body' => 'Body',
                'to_self' => '1',
            ])
            ->assertRedirect();

        Mail::assertSent(PayslipMail::class, fn (PayslipMail $mail) => $mail->hasTo($this->admin->email));

        // The teacher still has not been paid a payslip; marking it sent would
        // hide that.
        $this->assertFalse($slip->fresh()->isSent());
    }

    public function test_an_unassigned_payslip_cannot_be_sent_to_a_teacher(): void
    {
        Mail::fake();

        $batch = $this->uploadBatch(['Employee: Nobody On Staff']);
        $slip = $batch->slips()->sole();

        $this->actingAs($this->admin)
            ->post(route('payroll.send', [$batch, $slip]), ['subject' => 'Payslip', 'body' => 'Body'])
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_a_matched_teacher_with_no_email_cannot_be_sent_to(): void
    {
        Mail::fake();

        $maria = $this->teacher('Maria Santos', 'Maria G. Santos');
        $batch = $this->uploadBatch(['Employee: Maria G. Santos']);
        $slip = $batch->slips()->sole();

        // Blanking it after the match, which is how it happens in practice —
        // the record was created for the roster, not for payroll.
        $maria->forceFill(['email' => ''])->saveQuietly();

        $this->actingAs($this->admin)
            ->post(route('payroll.send', [$batch, $slip]), ['subject' => 'Payslip', 'body' => 'Body'])
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_reassigning_a_sent_payslip_puts_it_back_to_pending(): void
    {
        $maria = $this->teacher('Maria Santos', 'Maria G. Santos');
        $grace = $this->teacher('Grace Lee', 'Grace Y. Lee');

        $batch = $this->uploadBatch(['Employee: Maria G. Santos']);
        $slip = $batch->slips()->sole();
        $slip->update(['status' => 'sent', 'sent_to' => $maria->email, 'sent_at' => now()]);

        $this->actingAs($this->admin)
            ->put(route('payroll.reassign', [$batch, $slip]), ['user_id' => $grace->id])
            ->assertRedirect();

        $slip->refresh();

        $this->assertSame($grace->id, $slip->user_id);
        $this->assertSame('pending', $slip->status);
        $this->assertNull($slip->sent_at);
    }

    public function test_deleting_a_batch_removes_the_stored_pdf(): void
    {
        $this->teacher('Maria Santos', 'Maria G. Santos');
        $batch = $this->uploadBatch(['Employee: Maria G. Santos']);
        $path = $batch->path;

        $this->actingAs($this->admin)->delete(route('payroll.destroy', $batch))->assertRedirect();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, PayrollBatch::count());
    }

    public function test_teachers_cannot_reach_payroll(): void
    {
        $teacher = $this->teacher('Maria Santos', 'Maria G. Santos');

        $this->actingAs($teacher)->get(route('payroll.index'))->assertForbidden();
    }

    public function test_a_slip_cannot_be_reached_through_another_batchs_url(): void
    {
        $this->teacher('Maria Santos', 'Maria G. Santos');
        $mine = $this->uploadBatch(['Employee: Maria G. Santos']);
        $other = $this->uploadBatch(['Employee: Maria G. Santos']);

        $this->actingAs($this->admin)
            ->get(route('payroll.preview', [$other, $mine->slips()->sole()]))
            ->assertNotFound();
    }

    public function test_the_payroll_screen_lists_previous_runs(): void
    {
        $this->teacher('Maria Santos', 'Maria G. Santos');
        $this->uploadBatch(['Employee: Maria G. Santos']);

        $this->actingAs($this->admin)
            ->get(route('payroll.index'))
            ->assertOk()
            ->assertSee('Payslip Mailer')
            ->assertSee('Jul 9 - Jul 22, 2026');
    }

    public function test_the_review_screen_shows_every_payslip_and_flags_the_unmatched(): void
    {
        $this->teacher('Maria Santos', 'Maria G. Santos');
        $batch = $this->uploadBatch(['Employee: Maria G. Santos', 'Employee: Nobody On Staff']);

        $this->actingAs($this->admin)
            ->get(route('payroll.show', $batch))
            ->assertOk()
            ->assertSee('Maria Santos')
            ->assertSee('could not be matched to a staff member');
    }

    public function test_the_preview_serves_the_payslip_inline(): void
    {
        $this->teacher('Maria Santos', 'Maria G. Santos');
        $batch = $this->uploadBatch(['Employee: Maria G. Santos']);

        $response = $this->actingAs($this->admin)
            ->get(route('payroll.preview', [$batch, $batch->slips()->sole()]));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_an_unknown_placeholder_is_left_visible_rather_than_blanked(): void
    {
        $this->teacher('Maria Santos', 'Maria G. Santos');
        $batch = $this->uploadBatch(['Employee: Maria G. Santos']);

        $filled = PayslipMail::fill('Hi {frist_name}', $batch->slips()->sole(), null);

        $this->assertSame('Hi {frist_name}', $filled);
    }

    // ---------------------------------------------------------------- helpers

    private function teacher(string $name, string $legalName): User
    {
        return User::create([
            'name' => $name,
            'email' => str($name)->slug().'@example.com',
            'password' => 'password',
            'role' => 'teacher',
            'legal_name' => $legalName,
        ]);
    }

    /** A real multi-page PDF on disk, one line of text per page. */
    private function payrollPdf(array $pages): string
    {
        $pdf = new \setasign\Fpdi\Fpdi;
        $pdf->SetFont('Helvetica', '', 12);

        foreach ($pages as $text) {
            $pdf->AddPage();
            $pdf->Cell(0, 10, $text);
            $pdf->Ln();
            $pdf->Cell(0, 10, 'Pay Period: 07/09/2026 - 07/22/2026');
            $pdf->Ln();
            $pdf->Cell(0, 10, 'Check Date: 07/26/2026');
        }

        $path = tempnam(sys_get_temp_dir(), 'payroll').'.pdf';
        file_put_contents($path, $pdf->Output('S'));

        return $path;
    }

    private function uploadedPdf(array $pages): UploadedFile
    {
        return new UploadedFile($this->payrollPdf($pages), 'payroll.pdf', 'application/pdf', null, true);
    }

    private function uploadBatch(array $pages): PayrollBatch
    {
        $this->actingAs($this->admin)->post(route('payroll.store'), [
            'file' => $this->uploadedPdf($pages),
            'period_label' => 'Jul 9 - Jul 22, 2026',
        ]);

        return PayrollBatch::latest('id')->first();
    }
}
