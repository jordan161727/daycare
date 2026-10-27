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
        $this->authorise($request, $child);

        if (is_array($request->input('schedule_days'))) {
            $request->merge(['schedule_days' => array_values(array_filter($request->input('schedule_days'), fn ($d) => $d !== '' && $d !== null))]);
        }

        $data = $request->validate([
            'schedule_days' => ['nullable', 'array'],
            'schedule_days.*' => [Rule::in(array_keys(Child::WEEKDAYS))],
            'drop_off_time' => ['nullable', 'date_format:H:i'],
            'pick_up_time' => ['nullable', 'date_format:H:i', 'after:drop_off_time'],
            'dss_case_no' => ['nullable', 'string', 'max:40'],
            'dss_cin' => ['nullable', 'string', 'max:40'],
            'nickname' => ['nullable', 'string', 'max:60'],
        ]);

        $before = $child->scheduleDays();

        $child->fill($data + ['schedule_days' => $data['schedule_days'] ?? []])->save();

        $message = 'Overview saved.';

        if ($child->scheduleDays() !== $before) {
            $moved = $weeks->resyncRegisteredDays($child);
            if ($moved > 0) {
                $message .= ' '.$moved.' day'.($moved === 1 ? '' : 's').' on the attendance sheet updated to match.';
            }
        }

        return redirect()->route('children.show', $child)->with('success', $message);
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
        ]);

        $from = filled($data['from'] ?? null) ? Carbon::parse($data['from']) : Carbon::today();

        $child->classroom_override = $data['room'];
        $child->classroom_override_from = $from->toDateString();
        $child->roomChangeNote = trim('From '.$from->format('M j, Y').(filled($data['why'] ?? null) ? ' · '.$data['why'] : ''));
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
        $this->authorise($request, $child);

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
        $this->authorise($request, $child);

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
        $this->authorise($request, $child);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Child::ALERT_TYPES))],
            'text' => ['required', 'string', 'max:120'],
            'severity' => ['nullable', Rule::in(['mild', 'moderate', 'severe'])],
            'reaction' => ['nullable', 'string', 'max:200'],
            'action' => ['nullable', 'string', 'max:200'],
            'plan_on' => ['nullable', 'date'],
        ]);

        $alerts = $child->alerts ?? [];
        $alerts[] = array_filter($data, fn ($v) => filled($v));
        $child->alerts = array_values($alerts);
        $child->save();

        return redirect()->route('children.show', $child)->with('success', 'Alert added. It shows on the roll, check-in and the kitchen list.');
    }

    /** One alert removed, by its place in the list. */
    public function destroyAlert(Request $request, Child $child, int $index)
    {
        $this->authorise($request, $child);

        $alerts = array_values($child->alerts ?? []);
        abort_unless(array_key_exists($index, $alerts), 404);

        unset($alerts[$index]);
        $child->alerts = array_values($alerts);
        $child->save();

        return redirect()->route('children.show', $child)->with('success', 'Alert removed.');
    }

    /** An office or health note on the child. */
    public function storeNote(Request $request, Child $child)
    {
        $this->authorise($request, $child);

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
        $this->authorise($request, $child);

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Child::DOCUMENT_KINDS))],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'signed_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
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
        $this->authorise($request, $child);

        $data = $request->validate([
            'relationship' => ['nullable', 'string', 'max:60'],
            'is_guardian' => ['nullable', 'boolean'],
            'can_pickup' => ['nullable', 'boolean'],
            'is_emergency' => ['nullable', 'boolean'],
            'pays_fees' => ['nullable', 'boolean'],
            'gets_texts' => ['nullable', 'boolean'],
            'restriction' => ['nullable', 'string', 'max:255'],
        ]);

        $link = ChildPerson::where('child_id', $child->id)->where('person_id', $person->id)->firstOrFail();

        $directory->link($child, $person, ['priority' => $link->priority] + $data);

        return redirect()->route('children.show', $child)->with('success', $person->name.' updated.');
    }

    /** Call order: ring this person earlier or later. */
    public function personOrder(Request $request, Child $child, Person $person, PeopleDirectory $directory)
    {
        $this->authorise($request, $child);

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
}
