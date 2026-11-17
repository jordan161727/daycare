@extends('layouts.app')
@section('title', 'Kitchen list')
@section('content')
{{-- The kitchen list (QA E3): today's children with an allergy or a medical
     alert, by room, severe first. Drawn from the same alerts as the record,
     the roll and check-in. Inline styles only, so no CSS build is needed. --}}
<x-page-header title="Kitchen list" subtitle="Allergies and medical alerts for the children on today's roll, by room. Severe first."/>

<div style="margin-top: 20px; display: flex; flex-direction: column; gap: 16px;">
    <p style="font-size: 14px; margin: 0;" class="text-slate-600 dark:text-slate-300">
        {{ $total }} {{ $total === 1 ? 'child' : 'children' }} with an alert{{ $severe ? ' · '.$severe.' severe' : '' }} · {{ today()->format('l, M j, Y') }}
        <button type="button" onclick="window.print()" style="margin-left: 10px; font-weight: 600; text-decoration: underline;">Print</button>
    </p>

    @forelse($rooms as $room => $rows)
        <section style="border: 1px solid #e7e9f0; border-radius: 14px; padding: 14px 16px;" class="bg-white dark:bg-slate-900 dark:!border-slate-700" data-kitchen-room="{{ $room }}">
            <h2 style="margin: 0 0 10px; font-size: 16px; font-weight: 700;">{{ $room }}</h2>
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <tbody>
                    @foreach($rows as $row)
                        @foreach($row['alerts'] as $alert)
                            <tr style="border-top: 1px solid #f0f1f5;">
                                <td style="padding: 8px 6px; width: 28%; font-weight: 600;">@if($loop->first){{ $row['child']->displayName() }}@endif</td>
                                <td style="padding: 8px 6px; width: 30%;">
                                    {{-- The same chip as every other screen (<x-child-alert>). --}}
                                    <x-child-alert :alert="$alert" style="white-space: normal;" />
                                </td>
                                <td style="padding: 8px 6px;" class="text-slate-600 dark:text-slate-300">
                                    {{ $alert['reaction'] ? 'Reaction: '.$alert['reaction'].'. ' : '' }}{{ $alert['action'] ? 'Do: '.$alert['action'] : '' }}
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </section>
    @empty
        <p style="font-size: 14px;" class="text-slate-500">No allergies or medical alerts for the children on today's roll.</p>
    @endforelse
</div>
@endsection
