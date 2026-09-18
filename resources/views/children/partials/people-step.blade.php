{{--
    The People step: every adult connected to this child, in one list.

    It replaces the two steps that came before it — "Parents" and "Emergency &
    pickup" — which between them held sixty columns and asked for the same
    grandmother twice. What is left is one row per person and four ticks saying
    what they are, because that is the shape the question actually has: a
    mother who is also the first emergency contact is one woman, not two blocks
    of a form.

    Unlike every other step, this one saves as it goes. The rest of the form is
    fields on the child's own row and goes up with "Save changes"; a link is a
    row in another table and belongs to a person who may be on three other
    children, so linking, unlinking and ticking are their own small saves. The
    alternative — holding them until the form is submitted — would mean a
    half-made person sitting in a browser tab.

    $child Child  the child being edited; the step is not offered before one exists
--}}
@php($directory = app(\App\Services\PeopleDirectory::class))
@php($links = $child ? $directory->forChild($child) : collect())
@php($relationships = \App\Http\Controllers\PersonController::RELATIONSHIPS)

<h2 class="cs-title">People</h2>
<p class="cs-blurb">Parents, guardians, pick-up adults and emergency contacts, all in one list. Tick what each person is allowed to do for this child.</p>

@if(! $child)
    {{-- On the create form there is nothing to link to yet. Said plainly rather
         than shown as an empty table, which reads as "this child has nobody". --}}
    <section class="cs-card">
        <p class="text-sm text-slate-500 dark:text-slate-400">
            Save the child first. Once they have a record, this step is where their parents, guardians and pick-up adults are added &mdash; and where somebody already on file for another child is linked rather than typed in again.
        </p>
    </section>
@else
<div
    x-data="peopleStep({
        childId: @js($child->id),
        household: @js(trim(collect([$child->address, $child->city, $child->zip])->filter()->implode(', '))),
        childName: @js($child->first_name),
        rows: @js($links->map(fn ($link) => [
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
        ])->values()),
        relationships: @js($relationships),
    })"
    class="cs-cards"
>
    {{-- ---- the list ---- --}}
    <section class="cs-card" x-ref="peopleList">
        <div class="cs-card-head">
            <h3 class="cs-card-title">Linked people</h3>
            <span class="cs-pill" x-text="rows.length + ' linked'"></span>
        </div>

        {{-- Rule 4: a child with nobody legally responsible for them is a
             record somebody has not finished. Said here, and not enforced —
             the half-finished record is how a real afternoon goes. --}}
        <p x-show="! rows.some(row => row.is_guardian)" x-cloak
           class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-[12px] text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
            Nobody here is marked as a legal guardian yet.
        </p>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[54rem] text-left text-[12px]">
                <thead class="text-[10px] uppercase tracking-wide text-slate-400">
                    <tr>
                        <th class="pb-2 pr-3 font-semibold">Person</th>
                        <th class="pb-2 pr-3 font-semibold">Relationship</th>
                        <th class="pb-2 pr-2 text-center font-semibold">Guardian</th>
                        <th class="pb-2 pr-2 text-center font-semibold">Pick-up</th>
                        <th class="pb-2 pr-2 text-center font-semibold">Emergency</th>
                        <th class="pb-2 pr-2 text-center font-semibold">Call #</th>
                        <th class="pb-2 pr-3 font-semibold">Restriction / note</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    <template x-for="row in rows" :key="row.id">
                        {{-- A restricted row is tinted the whole way across, not
                             marked at one end: it is the first thing anybody
                             scanning this table needs to see. --}}
                        <tr :class="row.restriction ? 'bg-rose-50/70 dark:bg-rose-500/10' : ''">
                            <td class="py-2 pr-3 align-middle">
                                <span class="block font-semibold" :class="row.restriction ? 'text-rose-700 dark:text-rose-300' : ''" x-text="row.name"></span>
                                <span class="block text-[10.5px] text-slate-400">
                                    <span x-text="row.cell || 'no number'"></span>
                                    &middot;
                                    <button type="button" class="underline underline-offset-2 hover:text-indigo-600" @click="openDrawer(row.id)">edit person</button>
                                </span>
                            </td>
                            <td class="py-2 pr-3 align-middle">
                                <select class="cs-input h-8 py-0 text-[12px]" x-model="row.relationship" @change="save(row)">
                                    <option value="">&mdash;</option>
                                    <template x-for="name in relationships" :key="name">
                                        <option :value="name" x-text="name"></option>
                                    </template>
                                </select>
                            </td>
                            <td class="py-2 pr-2 text-center align-middle">
                                <input type="checkbox" class="h-4 w-4 rounded border-slate-300" x-model="row.is_guardian" @change="save(row)" :aria-label="'Legal guardian of ' + childName">
                            </td>
                            <td class="py-2 pr-2 text-center align-middle">
                                <input type="checkbox" class="h-4 w-4 rounded border-slate-300" x-model="row.can_pickup" @change="save(row)" :aria-label="'May collect ' + childName">
                            </td>
                            <td class="py-2 pr-2 text-center align-middle">
                                <input type="checkbox" class="h-4 w-4 rounded border-slate-300" x-model="row.is_emergency" @change="save(row)" :aria-label="'Emergency contact for ' + childName">
                            </td>
                            <td class="py-2 pr-2 text-center align-middle">
                                {{-- Rule 3: a call order only means anything on
                                     an emergency contact, so the box is dead
                                     until that tick is on. --}}
                                <input type="number" min="1" max="99"
                                       class="cs-input h-8 w-14 py-0 text-center text-[12px] disabled:opacity-40"
                                       :disabled="! row.is_emergency"
                                       x-model="row.priority" @change="save(row)"
                                       :aria-label="'Call order for ' + row.name">
                            </td>
                            <td class="py-2 pr-3 align-middle">
                                <input type="text" placeholder="e.g. court order — no contact"
                                       class="cs-input h-8 py-0 text-[12px]"
                                       :class="row.restriction ? 'border-rose-300 text-rose-700 dark:text-rose-300' : ''"
                                       x-model="row.restriction" @change="save(row)"
                                       :aria-label="'Restriction on ' + row.name">
                            </td>
                            <td class="py-2 text-right align-middle">
                                <button type="button" class="text-[11px] font-semibold text-rose-600 hover:underline" @click="unlink(row)">Unlink</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="rows.length === 0" x-cloak>
                        <td colspan="8" class="py-6 text-center text-slate-400">Nobody is linked to this child yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Rule 2, said where somebody is about to rely on the tick. --}}
        <p class="mt-2 text-[10.5px] text-slate-400">
            A restriction shows as a red chip at the top of the child&rsquo;s page and the person is never listed for pick-up, even if the box is ticked.
        </p>

        <p x-show="error" x-cloak class="mt-2 text-[11px] font-semibold text-rose-600" x-text="error"></p>
    </section>

    {{-- ---- adding somebody ---- --}}
    <section class="cs-card">
        <div class="cs-card-head">
            <h3 class="cs-card-title">Add a person to <span x-text="childName"></span></h3>
        </div>
        <p class="cs-blurb -mt-1">Search first &mdash; if they already exist for another child, link the same record so their details are never entered twice.</p>

        <label class="block">
            <span class="cs-label">Search existing people</span>
            <input type="text" class="cs-input" placeholder="Name or cell number"
                   x-model="query" @input.debounce.300ms="runSearch()">
        </label>

        <div x-show="results.length" x-cloak class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-white/5 dark:border-white/10">
            <template x-for="person in results" :key="person.id">
                <div class="flex items-center gap-3 px-3 py-2">
                    <div class="min-w-0 flex-1">
                        <span class="text-[12px] font-semibold" x-text="person.name"></span>
                        <span x-show="person.already_linked" class="ml-1.5 rounded bg-slate-100 px-1.5 py-0.5 text-[9.5px] font-semibold uppercase text-slate-500 dark:bg-white/10">already linked</span>
                        {{-- Which families they belong to, because that is how
                             somebody tells this Amy Crumb from another one. --}}
                        <span class="block truncate text-[10.5px] text-slate-400">
                            <span x-text="person.cell || 'no number'"></span>
                            <template x-for="family in person.families" :key="family">
                                <span> &middot; <span x-text="family"></span></span>
                            </template>
                        </span>
                    </div>
                    <span x-show="person.already_linked" class="text-[11px] text-slate-400">Linked</span>
                    <button x-show="! person.already_linked" type="button"
                            class="rounded-lg bg-indigo-600 px-3 py-1.5 text-[11px] font-semibold text-white hover:bg-indigo-700"
                            @click="linkExisting(person)">
                        Link to <span x-text="childName"></span>
                    </button>
                </div>
            </template>
        </div>

        <p x-show="query.length > 1 && ! searching && results.length === 0" x-cloak class="mt-2 text-[11px] text-slate-400">
            Nobody on file matches &ldquo;<span x-text="query"></span>&rdquo;.
        </p>

        <div class="mt-3 flex items-center justify-between gap-3">
            <span class="text-[11px] text-slate-400">Not one of these?</span>
            <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-[11px] font-semibold hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/10"
                    :aria-expanded="creating" aria-controls="new-person"
                    @click="toggleCreate()">
                <span x-text="creating ? '− Cancel new person' : '+ Create a new person'"></span>
            </button>
        </div>
    </section>

    {{-- ---- the ten fields, once ---- --}}
    <section class="cs-card" id="new-person" x-ref="newPersonCard" x-show="creating" x-cloak>
        <div class="cs-card-head">
            <h3 class="cs-card-title">New person</h3>
        </div>

        <div class="cs-grid">
            <label>
                <span class="cs-label">Name <span class="text-rose-500">*</span></span>
                <input type="text" class="cs-input" x-ref="newPersonName" x-model="draft.name" placeholder="e.g. Kaylynn Adkins">
            </label>
            <label>
                <span class="cs-label">Address</span>
                {{-- Rule 5: left blank it means "same as the child's", resolved
                     when it is shown rather than copied in — a copy is what goes
                     stale when the family moves. --}}
                <input type="text" class="cs-input" x-model="draft.address"
                       :disabled="draft.same_as_household"
                       :placeholder="draft.same_as_household ? household : 'Street, town, ZIP'">
                <label class="mt-1 inline-flex items-center gap-2 text-[10.5px] text-slate-500">
                    <input type="checkbox" class="h-3.5 w-3.5 rounded border-slate-300" x-model="draft.same_as_household">
                    Same as household
                </label>
            </label>
            <label><span class="cs-label">Home phone</span><input type="text" class="cs-input" x-model="draft.home_phone"></label>
            <label><span class="cs-label">Employer</span><input type="text" class="cs-input" x-model="draft.employer"></label>
            <label><span class="cs-label">Work phone</span><input type="text" class="cs-input" x-model="draft.work_phone"></label>
            <label><span class="cs-label">Fax</span><input type="text" class="cs-input" x-model="draft.fax"></label>
            <label>
                <span class="cs-label">Cell <span class="text-rose-500">*</span></span>
                <input type="text" class="cs-input" x-model="draft.cell" placeholder="585 000 0000">
                <span class="cs-help">Used to spot duplicates.</span>
            </label>
            <label><span class="cs-label">Email</span><input type="email" class="cs-input" x-model="draft.email"></label>
            <label><span class="cs-label">Title</span><input type="text" class="cs-input" x-model="draft.title"></label>
            <label><span class="cs-label">SSN</span><input type="text" class="cs-input" x-model="draft.ssn" placeholder="000 00 0000"></label>
        </div>

        <div class="mt-3 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-3 dark:border-white/5">
            <label class="w-40">
                <span class="cs-label">Relationship</span>
                <select class="cs-input h-8 py-0 text-[12px]" x-model="draft.relationship">
                    <option value="">&mdash;</option>
                    <template x-for="name in relationships" :key="name"><option :value="name" x-text="name"></option></template>
                </select>
            </label>
            <label class="inline-flex items-center gap-2 text-[11px] font-medium"><input type="checkbox" class="h-4 w-4 rounded border-slate-300" x-model="draft.is_guardian"> Guardian</label>
            <label class="inline-flex items-center gap-2 text-[11px] font-medium"><input type="checkbox" class="h-4 w-4 rounded border-slate-300" x-model="draft.can_pickup"> Can pick up</label>
            <label class="inline-flex items-center gap-2 text-[11px] font-medium"><input type="checkbox" class="h-4 w-4 rounded border-slate-300" x-model="draft.is_emergency"> Emergency</label>

            <div class="ml-auto flex items-center gap-2">
                <button type="button" class="rounded-lg px-3 py-1.5 text-[11px] font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-white/10" @click="creating = false">Cancel</button>
                <button type="button" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-[11px] font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                        :disabled="saving || ! draft.name || ! draft.cell" @click="createPerson()">
                    Create &amp; link
                </button>
            </div>
        </div>

        <p x-show="createError" x-cloak class="mt-2 text-[11px] font-semibold text-rose-600" x-text="createError"></p>
    </section>

    @include('children.partials.person-drawer')
    @include('children.partials.people-confirm')
</div>
@endif
