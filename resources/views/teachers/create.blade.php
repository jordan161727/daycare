@extends('layouts.app')

@section('title', 'Add Teacher')

@section('content')
<x-page-header title="Add Teacher" subtitle="Create a teacher login and assign their classrooms." />
<div class="mt-7 max-w-3xl">@include('teachers.form', ['action' => route('teachers.store'), 'method' => 'POST', 'submit' => 'Create Teacher'])</div>
@endsection
