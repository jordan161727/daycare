<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\ChildDocument;
use App\Models\ChildNote;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Services\ClassroomAssignment;
use App\Services\PeopleDirectory;
use App\Services\WeekSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The cards on the child record that save in place — Phase 2.3.
 *
 * Each one writes a few columns and comes back to the record, so a
 * correction told at the door is one press rather than a trip through the
 * whole enrolment form. Who may: whoever may read the record (the director,
 * and the teacher whose room the child is in), except the room, which moves
 * a child between rooms and so between teachers — the director's alone.
 */
class ChildRecordController extends Controller
{
    /** Overview: days, drop-off and pick-up, the DSS numbers, the nickname. */
    public function overview(Request $request, Child $child, WeekSchedule $weeks)
    {
        $this->authoriseWrite($request, $child);

        if (is_array($request->input('schedule_days'))) {
            $request->merge(['schedule_days' => array_values(array_filter($request->input('schedule_days'), fn ($d) => $d !== '' && $d !== null))]);
        }

        // A time read back as H:i:s is the same time (the full form does the same).
        foreach (['drop_off_time', 'pick_up_time'] as $field) {
            if (is_string($request->input($field)) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $request->input($field))) {
                $request->merge([$field => substr($request->input($field), 0, 5)]);
            }
        }

        $data = $request->validate([
            'schedule_days' => ['nullable', 'array'],
            'schedule_days.*' => [Rule::in(array_keys(Child::WEEKDAYS))],
            // The centre's opening hours, as the full edit form enforces them (QA C2).
            ...Child::scheduleTimeRules(),
            // The shapes the state issues them in (QA C9).
            // Only a number being typed: one already on file in another shape still saves.
            'dss_case_no' => ['nullable', 'string', 'max:40', ...(trim((string) $request->input('dss_case_no')) === (string) $child->dss_case_no ? [] : ['regex:'.Child::DSS_CASE_NO_PATTERN])],
            'dss_cin' => ['nullable', 'string', 'max:40', ...(trim((string) $request->input('dss_cin')) === (string) $child->dss_cin ? [] : ['regex:'.Child::DSS_CIN_PATTERN])],
            'nickname' => ['nullable', 'string', 'max:60'],
        ], Child::scheduleTimeMessages() + Child::dssMessages());

        // A save from a copy of the page drawn before somebody else's save is
        // refused rather than written over theirs (QA J2).
        if (\App\Services\RecordLock::isStale($request, $child)) {
            return redirect()->route('children.show', $child)->with('error', \App\Services\RecordLock::message($child));
        }

        foreach (['dss_case_no', 'dss_cin'] as $field) {
            if (array_key_exists($field, $data) && filled($data[$field])) {
                $data[$field] = strtoupper(trim($data[$field]));
            }
        }

        $before = $child->scheduleDays();

        // "N/A", "None", "-" and the like are stored as no nickname by the
        // model's nickname mutator (Child::cleanNickname, QA #20).
        $child->fill($data + ['schedule_days' => $data['schedule_days'] ?? []])->save();
        \App\Services\RecordLock::seen($child);

        $message = 'Overview saved.';

        if ($child->scheduleDays() !== $before) {
            $moved = $weeks->resyncRegisteredDays($child);
            if ($moved > 0) {
                $message .= ' '.$moved.' day'.($moved === 1 ? '' : 's').' on the attendance sheet updated to match.';
            }
        }

        // Inside the hard bounds but outside when the centre or room is open:
        // saved, and said — the same words the full form uses.
        return redirect()->route('children.show', $child)->with('success', $message)
            ->with('hours_warning', Child::hoursOutsideOpening($child->drop_off_time, $child->pick_up_time, $child->classroom));
    }

    /**
     * Move to another room, or back to automatic.
     *
     * A room picked by hand stays until "Back to automatic"; the age rule
     * keeps working underneath and the record says what it would have chosen.
     */
    public function room(Request $request, Child $child)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $this->authoriseWrite($request, $child);

        if ($request->boolean('automatic')) {
            $child->classroom_override = null;
            $child->classroom_override_from = null;
            $child->roomChangeNote = 'Back to automatic by age';
            $child->save();

            if (! $child->wasChanged('classroom')) {
                $child->recordEvent('room', 'Room back to automatic · '.$child->classroom);
            }

            return redirect()->route('children.show', $child)->with('success', $child->first_name.'\'s room follows their age again: '.$child->classroom.'.');
        }

        $data = $request->validate([
            'room' => ['required', Rule::in(ClassroomAssignment::rooms())],
            'from' => ['nullable', 'date'],
            'why' => ['nullable', 'string', 'max:160'],
        ], [
            // The picker opens on "Choose a room…" (QA #38, 12 Nov 2026).
            'room.required' => 'Choose the room to move '.$child->first_name.' to.',
        ]);

        $from = filled($data['from'] ?? null) ? Carbon::parse($data['from']) : Carbon::today();

        $child->classroom_override = $data['room'];
        $child->classroom_override_from = $from->toDateString();
        $child->roomChangeNote = trim('From '.$from->format('M j, Y').(filled($data['why'] ?? null) ? ' · '.$data['why'] : ''));
        $child->roomChangeAt = $from->copy();
        $child->save();

        // A move dated ahead changes nothing today; the history still says it is coming.
        if (! $child->wasChanged('classroom')) {
            $child->recordEvent('room', 'Room set by hand · '.$data['room'].' from '.$from->format('M j, Y'), $data['why'] ?? null);
        }

        return redirect()->route('children.show', $child)->with('success', $child->first_name.' moves to '.$data['room'].' from '.$from->format('M j').'.');
    }

    /** The one enrollment note, saved or cleared. */
    public function enrollmentNote(Request $request, Child $child)
    {
        $this->authoriseWrite($request, $child);

        $data = $request->validate(['enrollment_note' => ['nullable', 'string', 'max:300']]);

        $note = trim((string) ($data['enrollment_note'] ?? ''));

        $child->enrollment_note = $note === '' ? null : $note;
        $child->enrollment_note_at = $note === '' ? null : now();
        $child->save();

        if ($note !== '') {
            $child->recordEvent('note', 'Enrollment note updated', $note);
        }

        return redirect()->route('children.show', $child)->with('success', $note === '' ? 'Enrollment note cleared.' : 'Enrollment note saved.');
    }

    /** Health: doctor, immunizations, medication, custody. The alerts are below. */
    public function health(Request $request, Child $child)
    {
        $this->authoriseWrite($request, $child);

        $data = $request->validate([
            'doctor' => ['nullable', 'string', 'max:160'],
            'immunizations_due' => ['nullable', 'date'],
            'medication' => ['nullable', 'string', 'max:160'],
            'custody_note' => ['nullable', 'string', 'max:160'],
        ]);

        $child->health = array_filter([
            'doctor' => $data['doctor'] ?? null,
            'immunizations_due' => filled($data['immunizations_due'] ?? null) ? Carbon::parse($data['immunizations_due'])->toDateString() : null,
            'medication' => $data['medication'] ?? null,
        ], fn ($v) => filled($v));
        $child->custody_note = $data['custody_note'] ?? null;
        $child->save();

        return redirect()->route('children.show', $child)->with('success', 'Health details saved.');
    }

    /** One alert added, as its own entry with what to do about it. */
    public function storeAlert(Request $request, Child $child)
    {
        $this->authoriseWrite($request, $child);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Child::ALERT_TYPES))],
            'text' => ['required', 'string', 'max:120'],
            'severity' => ['nullable', Rule::in(['mild', 'moderate', 'severe'])],
            'reaction' => ['nullable', 'string', 'max:200'],
            'action' => ['nullable', 'string', 'max:200'],
            'plan_on' => ['nullable', 'date'],
        ], [
            // The Type box opens on "Choose…" rather than Court order (QA #37, 12 Nov 2026).
            'type.required' => 'Choose what kind of alert this is.',
        ]);

        $alerts = $child->alerts ?? [];
        $alerts[] = array_filter($data, fn ($v) => filled($v));
        $child->alerts = array_values($alerts);
        $child->save();
        \App\Services\RecordLock::seen($child);

        return redirect()->route('children.show', $child)->with('success', 'Alert added. It shows on the roll, check-in and the kitchen list.');
    }

    /** One alert removed: the one that was clicked, found by what it says. */
    public function destroyAlert(Request $request, Child $child, int $index)
    {
        $this->authoriseWrite($request, $child);

        // Taking an allergy or a court order off the record is the office's
        // (spec §4: a teacher reads basic info). A teacher may still add one.
        abort_unless($request->user()?->isAdmin(), 403, 'Only the office can remove an alert.');

        $alerts = array_values($child->alerts ?? []);
        $found = $this->alertIndex($request, $child, $alerts, $index);

        if ($found === null) {
            return redirect()->route('children.show', $child)->with('error', 'That alert was changed or removed by someone else after you opened this page, so nothing was removed. The alerts below are as they are now.');
        }

        unset($alerts[$found]);
        $child->alerts = array_values($alerts);
        $child->save();
        \App\Services\RecordLock::seen($child);

        return redirect()->route('children.show', $child)->with('success', 'Alert removed.');
    }

    /** One alert corrected in place, from its row on the Health card (QA E2). */
    public function updateAlert(Request $request, Child $child, int $index)
    {
        $this->authoriseWrite($request, $child);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Child::ALERT_TYPES))],
            'text' => ['required', 'string', 'max:120'],
            'severity' => ['nullable', Rule::in(['mild', 'moderate', 'severe'])],
            'reaction' => ['nullable', 'string', 'max:200'],
            'action' => ['nullable', 'string', 'max:200'],
            'plan_on' => ['nullable', 'date'],
        ], [
            'type.required' => 'Choose what kind of alert this is.',
        ]);

        $alerts = array_values($child->alerts ?? []);
        $found = $this->alertIndex($request, $child, $alerts, $index);

        if ($found === null) {
            return redirect()->route('children.show', $child)->with('error', 'That alert was changed or removed by someone else after you opened this page, so your edit was not saved. The alerts below are as they are now.');
        }

        $alerts[$found] = array_filter($data, fn ($v) => filled($v));
        $child->alerts = array_values($alerts);
        $child->save();
        \App\Services\RecordLock::seen($child);

        return redirect()->route('children.show', $child)->with('success', 'Alert updated.');
    }

    /**
     * Which stored alert a Remove or Edit means.
     *
     * The page posts the alert's fingerprint (Child::alertKey) beside its
     * place in the list, so a row that moved because somebody else removed
     * the one above it is still found, and one that was changed or removed
     * meanwhile is not — rather than the action landing on whatever alert
     * now sits at that place (QA E4). A post with no fingerprint is trusted
     * only when the record has not been changed by someone else since this
     * user last saw it (RecordLock).
     *
     * @param  array<int, mixed>  $alerts
     */
    private function alertIndex(Request $request, Child $child, array $alerts, int $index): ?int
    {
        $key = $request->input('key');

        if (is_string($key) && $key !== '') {
            if (isset($alerts[$index]) && Child::alertKey($alerts[$index]) === $key) {
                return $index;
            }

            foreach ($alerts as $i => $alert) {
                if (Child::alertKey($alert) === $key) {
                    return $i;
                }
            }

            return null;
        }

        if (! array_key_exists($index, $alerts)) {
            abort(404);
        }

        return \App\Services\RecordLock::alertsStale($child) ? null : $index;
    }

    /** An office or health note on the child. */
    public function storeNote(Request $request, Child $child)
    {
        $this->authoriseWrite($request, $child);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
            'kind' => ['nullable', Rule::in(array_keys(ChildNote::KINDS))],
        ]);

        $child->notes()->create([
            'user_id' => $request->user()->id,
            'kind' => $data['kind'] ?? 'office',
            'body' => trim($data['body']),
        ]);

        return redirect()->route('children.show', $child)->with('success', 'Note added.');
    }

    /** A document uploaded, or recorded as in the cabinet. */
    public function storeDocument(Request $request, Child $child)
    {
        $this->authoriseWrite($request, $child);

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Child::DOCUMENT_KINDS))],
            // A paper with no file is fine — it is in the cabinet — but then
            // the date it was signed is what says so. Neither is an empty
            // form, and saving that used to mark the paper on file (and
            // clear the photo-consent gap) with nothing behind it.
            'file' => ['nullable', 'required_without:signed_on', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'signed_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:'.($request->filled('signed_on') ? 'signed_on' : 'today')],
        ], [
            'file.required_without' => 'Choose the file, or enter the date it was signed if the paper is in the cabinet.',
            'expires_on.after_or_equal' => $request->filled('signed_on')
                ? 'The expiry date cannot be before the date it was signed.'
                : 'The expiry date cannot be before today.',
        ]);

        $path = $request->hasFile('file') ? $request->file('file')->store('children/documents', 'local') : null;

        $expires = $data['expires_on'] ?? null;

        // The medical statement renews yearly from the day it was signed.
        if ($expires === null && $data['kind'] === 'medical_statement') {
            $expires = Carbon::parse($data['signed_on'] ?? today())->addYear()->toDateString();
        }

        $child->documents()->create([
            'kind' => $data['kind'],
            'name' => Child::DOCUMENT_KINDS[$data['kind']],
            'path' => $path,
            'signed_on' => $data['signed_on'] ?? today()->toDateString(),
            'expires_on' => $expires,
            'uploaded_by' => $request->user()->id,
        ]);

        return redirect()->route('children.show', $child)->with('success', Child::DOCUMENT_KINDS[$data['kind']].' is on file.');
    }

    /** The file itself, to whoever may read the record. */
    public function document(Request $request, Child $child, ChildDocument $document)
    {
        $this->authorise($request, $child);

        abort_unless($document->child_id === $child->id, 404);
        abort_if(blank($document->path) || ! Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, null, ['Cache-Control' => 'private, no-store']);
    }

    /** What one person is for this child: the ticks, the relationship. */
    public function personFlags(Request $request, Child $child, Person $person, PeopleDirectory $directory)
    {
        $this->authoriseWrite($request, $child);

        $data = $request->validate([
            'relationship' => ['nullable', 'string', 'max:60'],
            'is_guardian' => ['nullable', 'boolean'],
            'can_pickup' => ['nullable', 'boolean'],
            'is_emergency' => ['nullable', 'boolean'],
            'pays_fees' => ['nullable', 'boolean'],
            'gets_texts' => ['nullable', 'boolean'],
            'restriction' => ['nullable', 'string', 'max:255'],
            // The person's own name and mobile, from the same row (QA #29).
            // Optional in the request, so an older form without them still saves.
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'cell' => ['sometimes', 'nullable', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail) {
                if (filled($value) && Person::e164((string) $value) === null) {
                    $fail('Enter a 10-digit US mobile number, like (716) 555-0134.');
                }
            }],
        ]);

        $link = ChildPerson::where('child_id', $child->id)->where('person_id', $person->id)->firstOrFail();

        $own = array_intersect_key($data, array_flip(['name', 'cell']));
        if ($own !== []) {
            if (array_key_exists('cell', $own)) {
                $own['cell'] = filled($own['cell']) ? Person::e164($own['cell']) : null;
            }
            $person->fill($own)->save();
        }
        unset($data['name'], $data['cell']);

        // A restriction on file (a court order, a no-contact note) is the
        // office's to lift or reword. A teacher may add one where there is
        // none; one already there stays as it is.
        if (! $request->user()?->isAdmin() && filled($link->restriction)) {
            $data['restriction'] = $link->restriction;
        }

        $directory->link($child, $person, ['priority' => $link->priority] + $data);

        return redirect()->route('children.show', $child)->with('success', $person->name.' updated.');
    }

    /** Call order: ring this person earlier or later. */
    public function personOrder(Request $request, Child $child, Person $person, PeopleDirectory $directory)
    {
        $this->authoriseWrite($request, $child);

        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        $links = ChildPerson::where('child_id', $child->id)->where('is_emergency', true)
            ->orderByRaw('priority is null, priority')->orderBy('id')->get()->values();

        $index = $links->search(fn (ChildPerson $link) => $link->person_id === $person->id);
        abort_if($index === false, 404);

        $other = $data['direction'] === 'up' ? $index - 1 : $index + 1;

        if (isset($links[$other])) {
            $a = $links[$index]->priority ?? $index + 1;
            $b = $links[$other]->priority ?? $other + 1;
            $links[$index]->forceFill(['priority' => $b])->save();
            $links[$other]->forceFill(['priority' => $a])->save();
            $directory->renumber($child);
        }

        return redirect()->route('children.show', $child)->with('success', 'Call order updated.');
    }

    /** The same door as the record itself. */
    private function authorise(Request $request, Child $child): void
    {
        $user = $request->user();

        abort_unless($user && Child::whereKey($child->getKey())->visibleTo($user)->exists(), 403);
    }

    /**
     * The door for a save: the record's own, and not while it is archived.
     *
     * The archived record's banner says it is read-only until restored, and
     * these cards are the only way to write to it from the record — so they
     * have to mean it. Back to the record with the reason rather than a bare
     * 403, since the reader is looking at the banner that explains it.
     */
    private function authoriseWrite(Request $request, Child $child): void
    {
        $this->authorise($request, $child);

        if ($child->isArchived()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                $request->expectsJson()
                    ? response()->json(['message' => 'This record is archived and read-only until restored.'], 423)
                    : redirect()->route('children.show', $child)->withErrors(['archived' => 'This record is archived and read-only until restored.'])
            );
        }
    }
}
