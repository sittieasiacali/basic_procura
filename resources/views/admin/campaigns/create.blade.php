@extends('layouts.app')

@section('title', 'Create Campaign')

@section('content')
    <x-ui.form-page-header eyebrow="Administration" title="Create Campaign" description="Add a reusable campaign for account assignment." icon="campaign" />
    <form method="POST" action="{{ route('admin.campaigns.store') }}" class="procura-form-page procura-simple-form">
        @csrf
        @include('admin.campaigns.partials.form')
    </form>
@endsection
