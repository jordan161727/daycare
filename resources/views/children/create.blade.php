@extends('layouts.app')
@section('title', 'Add Child')
@section('content')
<x-page-header title="Add Child" subtitle="Create a child record for your daycare roster." />
<div class="mt-7 max-w-3xl">@include('children.form', ['action' => route('children.store'), 'method' => 'POST', 'child' => null, 'submit' => 'Add Child'])</div>
@endsection
