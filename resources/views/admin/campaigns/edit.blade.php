@extends('layouts.app')

@section('title', 'Edit Campaign')

@section('content')
    <x-ui.form-page-header eyebrow="Administration" title="Edit Campaign" description="Update {{ $campaign->name }} and its assignment availability." icon="campaign" />
    <form method="POST" action="{{ route('admin.campaigns.update', $campaign) }}" class="procura-form-page procura-simple-form">
        @csrf @method('PUT')
        @include('admin.campaigns.partials.form')
    </form>
@endsection
