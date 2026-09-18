<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Services\PeopleDirectory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The People step and the person drawer, from the server's side.
 *
 * Everything here answers JSON: the step is one screen that links, unlinks and
 * edits without leaving the child, and a page reload between each of those
 * would lose whatever else the form has open.
 *
 * Who may do any of it is the same rule as the child's record itself — a
 * teacher keeps the record of the children in their own rooms, and that
 * includes who may collect them. Checked per call against the child, not
 * assumed from the route.
 */
class PersonController extends Controller
{
    /** The relationships the dropdowns offer, in the order they are listed. */
    public const RELATIONSHIPS = [
        'Mother', 'Father', 'Stepparent', 'Grandmother', 'Grandfather',
        'Aunt/Uncle', 'Nanny', 'Family friend', 'Other',
    ];

    public function __construct(private PeopleDirectory $directory) {}

    /** Adults matching a name or a number, for the search box on the step. */
    public function search(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);

        $child = $this->childFor($request);

        $people = $this->directory->search($data['q'] ?? '');

        return response()->json([
            'people' => $people->map(fn (Person $person) => $this->asSearchResult($person, $child))->values(),
        ]);
    }

    /**
     * One child's list, as the server has it.
     *
     * Read back after a write that can renumber the call order: ticking a
     * second emergency contact moves the third, and a list that only updated
     * the row somebody touched would show two number twos.
     */
    public function forChild(Request $request, Child $child)
    {
        abort_unless(Child::whereKey($child->getKey())->visibleTo($request->user())->exists(), 403);

        return response()->json([
            'people' => $this->directory->forChild($child)
                ->map(fn (ChildPerson $link) => [
                    'id' => $link->person_id,
                    'name' => $link->person?->name,
                    'cell' => $link->person?->cell,
                    'relationship' => $link->relationship,
                    'is_guardian' => $link->is_guardian,
                    'can_pickup' => $link->can_pickup,
                    'is_emergency' => $link->is_emergency,
                    'priority' => $link->priority,
                    'restriction' => $link->restriction,
                    'linked_count' => $link->person?->links_count ?? 1,
                ])->values(),
        ]);
    }

    /** Create a person and link them to the child in one press. */
    public function store(Request $request)
    {
        $child = $this->childFor($request, required: true);

        $data = $request->validate($this->personRules() + $this->linkRules());

        ['person' => $person, 'existed' => $existed] = $this->directory->create($data);

        // Somebody who already existed is linked rather than refused — the
        // press said "this person belongs to this child", and that is true
        // whether or not the record had to be made.
        $link = $this->directory->link($child, $person, $data);

        return response()->json([
            'status' => $existed ? 'linked_existing' : 'created',
            'person' => $this->asRow($link->fresh('person'), $child),
        ]);
    }

    /** Change a person's own fields — for every child they are linked to. */
    public function update(Request $request, Person $person)
    {
        $this->authoriseForAnyLinkedChild($request, $person);

        $data = $request->validate($this->personRules());

        $this->directory->update($person, $data);

        return response()->json(['status' => 'ok', 'person' => $this->asPerson($person->fresh())]);
    }

    /** The drawer: the person, and every child they belong to. */
    public function show(Request $request, Person $person)
    {
        $this->authoriseForAnyLinkedChild($request, $person);

        $links = $person->links()->with('child:id,first_name,last_name,lan,classroom,address,city,zip')->get();

        return response()->json([
            'person' => $this->asPerson($person),
            'children' => $links->map(fn (ChildPerson $link) => [
                'id' => $link->child_id,
                'name' => trim(($link->child->first_name ?? '').' '.($link->child->last_name ?? '')),
                'lan' => $link->child->lan ?? null,
                'classroom' => $link->child->classroom ?? null,
                'relationship' => $link->relationship,
                'is_guardian' => $link->is_guardian,
                'can_pickup' => $link->can_pickup,
                'is_emergency' => $link->is_emergency,
                'priority' => $link->priority,
                'restriction' => $link->restriction,
            ])->values(),
            'relationships' => self::RELATIONSHIPS,
        ]);
    }

    /** Rule 6: only once nothing points at them. */
    public function destroy(Request $request, Person $person)
    {
        $this->authoriseForAnyLinkedChild($request, $person);

        if (! $this->directory->delete($person)) {
            return response()->json([
                'status' => 'linked',
                'message' => 'This person is still on a child\'s record. Unlink them first.',
            ], 422);
        }

        return response()->json(['status' => 'deleted']);
    }

    /** Set what an existing person is for this child. */
    public function link(Request $request, Person $person)
    {
        $child = $this->childFor($request, required: true);

        $data = $request->validate($this->linkRules());

        $link = $this->directory->link($child, $person, $data);

        return response()->json([
            'status' => 'ok',
            'person' => $this->asRow($link->fresh('person'), $child),
            'lacks_guardian' => $this->directory->lacksGuardian($child),
        ]);
    }

    /** Take them off this child, leaving the record and their other children. */
    public function unlink(Request $request, Person $person)
    {
        $child = $this->childFor($request, required: true);

        $this->directory->unlink($child, $person);

        return response()->json([
            'status' => 'ok',
            'lacks_guardian' => $this->directory->lacksGuardian($child),
        ]);
    }

    private function personRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'home_phone' => ['nullable', 'string', 'max:40'],
            'work_phone' => ['nullable', 'string', 'max:40'],
            // Required by hand, though the column is not: a person typed in on
            // this screen is somebody the centre will have to ring. A record
            // carried over from a paper form may legitimately have no number.
            'cell' => ['required', 'string', 'max:40'],
            'alternate_phone' => ['nullable', 'string', 'max:40'],
            'fax' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'employer' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:120'],
            'ssn' => ['nullable', 'string', 'max:40'],
            'drivers_license' => ['nullable', 'string', 'max:60'],
        ];
    }

    private function linkRules(): array
    {
        return [
            'relationship' => ['nullable', Rule::in(self::RELATIONSHIPS)],
            'is_guardian' => ['nullable', 'boolean'],
            'can_pickup' => ['nullable', 'boolean'],
            'is_emergency' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:99'],
            'restriction' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** The child this call is about, checked against who is asking. */
    private function childFor(Request $request, bool $required = false): ?Child
    {
        $id = $request->input('child_id');

        if (blank($id)) {
            abort_if($required, 422, 'No child given.');

            return null;
        }

        $child = Child::findOrFail($id);

        abort_unless(
            Child::whereKey($child->getKey())->visibleTo($request->user())->exists(),
            403
        );

        return $child;
    }

    /**
     * A person is readable by whoever may see any child they belong to.
     *
     * The drawer shows every linked child, which is the point of it — a teacher
     * opening Kaylynn sees that she is also the mother of a child in another
     * room. That is the record being one record; hiding half of it would put
     * them back to wondering whether it is the same woman.
     */
    private function authoriseForAnyLinkedChild(Request $request, Person $person): void
    {
        $user = $request->user();

        if ($user?->isAdmin()) {
            return;
        }

        $visible = $person->links()
            ->whereHas('child', fn ($query) => $query->visibleTo($user))
            ->exists();

        // A person nobody is linked to yet is fair game: they have just been
        // created, or they are about to be deleted.
        abort_unless($visible || ! $person->links()->exists(), 403);
    }

    private function asPerson(Person $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'address' => $person->address,
            'home_phone' => $person->home_phone,
            'work_phone' => $person->work_phone,
            'cell' => $person->cell,
            'alternate_phone' => $person->alternate_phone,
            'fax' => $person->fax,
            'email' => $person->email,
            'employer' => $person->employer,
            'title' => $person->title,
            // Never the whole number to a screen: the last four is what anybody
            // checking an identity actually reads off it.
            'ssn_last4' => $person->ssnLast4(),
            'drivers_license' => $person->drivers_license,
            'linked_count' => $person->links()->count(),
        ];
    }

    /** One row of the Linked people table. */
    private function asRow(ChildPerson $link, Child $child): array
    {
        return $this->asPerson($link->person) + [
            'relationship' => $link->relationship,
            'is_guardian' => $link->is_guardian,
            'can_pickup' => $link->can_pickup,
            'is_emergency' => $link->is_emergency,
            'priority' => $link->priority,
            'restriction' => $link->restriction,
            // What the row shows as the address when the person has none of
            // their own — resolved here rather than copied onto the person.
            'address_shown' => $link->person->address ?: $child->address,
        ];
    }

    /** One row of the search results, which says whether they are already on. */
    private function asSearchResult(Person $person, ?Child $child): array
    {
        $families = $person->links
            ->map(fn (ChildPerson $link) => trim(($link->relationship ? $link->relationship.' of ' : '')
                .trim(($link->child->first_name ?? '').' '.($link->child->last_name ?? ''))))
            ->filter()
            ->values();

        return [
            'id' => $person->id,
            'name' => $person->name,
            'cell' => $person->cell,
            'families' => $families,
            'already_linked' => $child !== null && $person->links->contains('child_id', $child->id),
        ];
    }
}
