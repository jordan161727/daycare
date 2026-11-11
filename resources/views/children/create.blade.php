@extends('layouts.app')
@section('title', 'Add Child')
@section('content')
{{-- Add, as tabs in one card (Jordan's brief, 6 Nov 2026): six tabs under a
     summary strip, Back / Next / Add child along the bottom. Capped at 1120px,
     the width the brief draws it at. The PDF review keeps the stacked shape. --}}
<div class="ca-page">
    <div class="ca-header">
        <div class="min-w-0">
            <p class="ca-eyebrow">Children · New record</p>
            <h1 class="ca-title">Add child</h1>
            <p class="ca-subtitle">Create a child record for your daycare roster. Only first and last name are required to save.</p>
        </div>
        <a href="{{ route('children.document-import.create') }}" class="ca-btn">Import PDF / image</a>
    </div>
    @include('children.form', ['action' => route('children.store'), 'method' => 'POST', 'child' => null, 'submit' => 'Add child', 'nextLan' => $nextLan, 'tabbed' => true])
</div>
@endsection
