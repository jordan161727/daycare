@extends('layouts.app')
@section('title', 'Add Child')
@section('content')
<div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><x-page-header title="Add Child" subtitle="Create a child record for your daycare roster." /><a href="{{ route('children.document-import.create') }}" class="inline-flex w-fit rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">Import PDF / Image</a></div>
{{-- No width cap: the form lays itself out from the room it is given. --}}
<div class="mt-7">@include('children.form', ['action' => route('children.store'), 'method' => 'POST', 'child' => null, 'submit' => 'Add Child', 'nextLan' => $nextLan])</div>
@endsection
