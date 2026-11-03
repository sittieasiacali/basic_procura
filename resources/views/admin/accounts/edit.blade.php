@extends('layouts.app')

@section('title', 'Edit Account')

@section('content')
    <x-ui.form-page-header eyebrow="Administration" title="Edit Account" description="Update {{ $account->name }} without exposing the existing password." icon="users" />
    <form method="POST" action="{{ route('admin.accounts.update', $account) }}" class="procura-form-page procura-simple-form">
        @csrf @method('PUT')
        @include('admin.accounts.partials.form')
    </form>
@endsection
