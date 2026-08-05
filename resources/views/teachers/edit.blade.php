@extends('layouts.app')

@section('title', 'Edit Teacher')

@section('content')
<x-page-header title="Edit Teacher" subtitle="Update account details or classroom assignments." />
<div class="mt-7 max-w-3xl">@include('teachers.form', ['action' => route('teachers.update', $teacher), 'method' => 'PUT', 'submit' => 'Save Changes'])</div>
@endsection
