@extends('layouts.app')

@section('title', 'Create Account')

@section('content')
    <x-ui.form-page-header eyebrow="Administration" title="Create Account" description="Add a role-based ASI PROCURA account and assign its operational access." icon="users" />
    <form method="POST" action="{{ route('admin.accounts.store') }}" class="procura-form-page procura-simple-form">
        @csrf
        @include('admin.accounts.partials.form')
    </form>
@endsection
