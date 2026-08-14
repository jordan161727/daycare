<?php

namespace App\Http\Controllers;

use App\Mail\PayslipMail;
use App\Models\PayrollBatch;
use App\Models\PayrollSlip;
use App\Models\User;
use App\Services\PayrollPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Splitting a combined payroll PDF and mailing each employee their own pages.
 *
 * The whole flow is built around one risk: sending a teacher somebody else's
 * pay. So nothing is sent in bulk, every slip is looked at on screen before it
 * goes, and a page the matcher was not sure about arrives unassigned rather
 * than guessed.
 */
class PayrollController extends Controller
{
    public function __construct(private PayrollPdf $pdf) {}

    public function index()
    {
        return view('payroll.index', [
            'batches' => PayrollBatch::withCount('slips')->latest()->paginate(10),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:25600'],
            'period_label' => ['nullable', 'string', 'max:120'],
        ]);

        $file = $request->file('file');

        // Private disk, hashed name. This one file holds every employee's pay,
        // so a guessable path under public/ would be the whole payroll leaked.
        $path = $file->store(config('daycare.payroll.directory'), config('daycare.payroll.disk'));

        $batch = new PayrollBatch([
            'period_label' => $request->input('period_label'),
            'original_filename' => $file->getClientOriginalName(),
            'path' => $path,
            'uploaded_by' => $request->user()->id,
        ]);

        try {
            $absolute = $batch->disk()->path($path);
            $slips = $this->pdf->analyse($absolute, User::teachers()->get());
            $batch->page_count = $this->pdf->pageCount($absolute);
        } catch (\Throwable $e) {
            $batch->disk()->delete($path);

            return back()->withInput()->with('error', $e->getMessage());
        }

        DB::transaction(function () use ($batch, $slips) {
            $batch->save();

            foreach ($slips as $position => $slip) {
                $batch->slips()->create($slip + ['position' => $position]);
            }
        });

        return redirect()->route('payroll.show', $batch)
            ->with('success', 'Payroll split into '.count($slips).' payslip'.(count($slips) === 1 ? '' : 's').'. Check each one before sending.');
    }

    public function show(Request $request, PayrollBatch $batch)
    {
        $batch->load('slips.user');

        $current = $request->query('slip')
            ? $batch->slips->firstWhere('id', (int) $request->query('slip'))
            : null;

        return view('payroll.show', [
            'batch' => $batch,
            'slips' => $batch->slips,
            'current' => $current ?? $batch->slips->first(),
            'staff' => User::teachers()->get(['id', 'name', 'email', 'legal_name']),
            'subject' => old('subject', config('daycare.payroll.default_subject')),
            'body' => old('body', config('daycare.payroll.default_body')),
        ]);
    }

    public function destroy(PayrollBatch $batch)
    {
        $batch->delete();

        return redirect()->route('payroll.index')->with('success', 'Payroll batch and its PDF deleted.');
    }

    /** Correct a bad match, or assign a page the matcher left unclaimed. */
    public function reassign(Request $request, PayrollBatch $batch, PayrollSlip $slip)
    {
        abort_unless($slip->payroll_batch_id === $batch->id, 404);

        $data = $request->validate([
            'user_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'teacher')],
        ]);

        // Reassigning after a send would leave sent_at pointing at the wrong
        // person, so the slip drops back to pending and has to be sent again.
        $slip->update([
            'user_id' => $data['user_id'] ?: null,
            'status' => 'pending',
            'sent_to' => null,
            'sent_at' => null,
            'error' => null,
        ]);

        return back()->with('success', 'Payslip reassigned.');
    }

    /** The slip's own pages, inline, for the review pane. */
    public function preview(PayrollBatch $batch, PayrollSlip $slip)
    {
        abort_unless($slip->payroll_batch_id === $batch->id, 404);

        return response($this->pdf->extract($batch->absolutePath(), $slip->pages), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="payslip.pdf"',
            // The URL is behind auth, but a shared browser cache on the office
            // machine would outlive the session.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Send one payslip — to the employee, or to the director to check first.
     *
     * One at a time on purpose. A "send all" button is one misclick away from
     * mailing a whole payroll run built on an unreviewed split.
     */
    public function send(Request $request, PayrollBatch $batch, PayrollSlip $slip)
    {
        abort_unless($slip->payroll_batch_id === $batch->id, 404);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'to_self' => ['nullable', 'boolean'],
        ]);

        $toSelf = $request->boolean('to_self');

        if (! $toSelf && ! $slip->isSendable()) {
            return back()->with('error', $slip->user
                ? $slip->user->name.' has no email address on their staff record.'
                : 'This payslip is not assigned to anybody yet.');
        }

        $recipient = $toSelf ? $request->user()->email : $slip->user->email;

        try {
            $pdf = $this->pdf->extract($batch->absolutePath(), $slip->pages);

            Mail::to($recipient)->send(new PayslipMail(
                slip: $slip,
                subjectLine: PayslipMail::fill($data['subject'], $slip, $batch->period_label),
                bodyText: PayslipMail::fill($data['body'], $slip, $batch->period_label),
                pdf: $pdf,
            ));
        } catch (\Throwable $e) {
            // The message can name a mail host and credentials, so it is logged
            // rather than shown, and the row keeps enough to retry from.
            Log::error('Payslip send failed', ['slip' => $slip->id, 'exception' => $e]);

            $slip->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return back()->with('error', 'That payslip could not be sent. The details are in the log.');
        }

        // A copy to the director is a check, not a delivery — marking it sent
        // would hide the fact that the teacher still has not been paid a slip.
        if (! $toSelf) {
            $slip->update([
                'status' => 'sent',
                'sent_to' => $recipient,
                'sent_at' => now(),
                'error' => null,
            ]);
        }

        $next = $batch->slips()->where('position', '>', $slip->position)->first();

        return redirect()
            ->route('payroll.show', ['batch' => $batch, 'slip' => $next?->id ?? $slip->id])
            ->with('success', $toSelf
                ? 'Copy sent to '.$recipient.'.'
                : 'Payslip sent to '.$recipient.'.');
    }
}
