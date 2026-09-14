@extends('layouts.app')
@section('title', 'Edit Child')
@section('content')
{{-- No page header: the form's own band already names the child, carries the
     LAN, room and status, and holds Cancel and Save — a second title above it
     would say the same thing twice and push the first step under the fold. --}}
@include('children.form', [
    'action' => route('children.update', $child),
    'method' => 'PUT',
    'child' => $child,
    'submit' => 'Save changes',
    'stepper' => true,
])
@endsection
