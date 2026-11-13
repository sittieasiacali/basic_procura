@extends('layouts.app')
@section('title', 'Notification ringtone')
@section('content')
    <x-ui.page-header eyebrow="Administration" title="Notification ringtone" description="Choose a four-second ringtone for new updates across all campaigns." />
    <form method="POST" action="{{ route('admin.notification-sound.update') }}" class="card">
        @csrf
        @method('PUT')
        <div class="card-body">
            <label class="form-label" for="admin-notification-tone">Ringtone for everyone</label>
            <select class="form-select" id="admin-notification-tone" name="tone" data-admin-notification-tone required>
                @foreach($tones as $value => $label)
                    <option value="{{ $value }}" @selected(old('tone', $tone) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('tone')<p class="text-danger mt-2">{{ $message }}</p>@enderror
            <p class="small text-muted mt-3">Each ringtone plays for four seconds. Users can turn sound on or off in Notifications. Their ringtone follows this setting.</p>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-outline-primary" type="button" data-notification-sound-test>Test sound</button>
                <button class="btn btn-primary" type="submit">Save ringtone</button>
            </div>
            <p class="small text-muted mt-3 mb-0" data-notification-sound-status role="status">Tap Test sound to preview your choice before saving.</p>
        </div>
    </form>
@endsection