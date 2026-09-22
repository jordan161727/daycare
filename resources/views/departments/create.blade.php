@extends('layouts.app')
@section('title', 'Add department')
@section('content')
@include('departments.form', [
    'heading' => 'Add Department',
    'subheading' => 'Create a new department',
    'action' => route('departments.store'),
    'submit' => 'Add Department',
])
@endsection
