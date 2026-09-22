@extends('layouts.app')
@section('title', 'Edit department')
@section('content')
@include('departments.form', [
    'heading' => 'Edit Department',
    'subheading' => $department->name,
    'action' => route('departments.update', $department),
    'method' => 'PUT',
    'submit' => 'Save Changes',
])
@endsection
