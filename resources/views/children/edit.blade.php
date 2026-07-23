@extends('layouts.app')
@section('title', 'Edit Child')
@section('content')
<x-page-header title="Edit Child" subtitle="Update {{ $child->first_name }} {{ $child->last_name }}’s record." />
<div class="mt-7 max-w-3xl">@include('children.form', ['action' => route('children.update', $child), 'method' => 'PUT', 'child' => $child, 'submit' => 'Save Changes'])</div>
@endsection
