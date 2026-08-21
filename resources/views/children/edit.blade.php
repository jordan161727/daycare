@extends('layouts.app')
@section('title', 'Edit Child')
@section('content')
<x-page-header title="Edit Child" subtitle="Update {{ $child->first_name }} {{ $child->last_name }}’s record." />
{{-- No width cap: the form lays itself out from the room it is given. --}}
<div class="mt-7">@include('children.form', ['action' => route('children.update', $child), 'method' => 'PUT', 'child' => $child, 'submit' => 'Save Changes'])</div>
@endsection
