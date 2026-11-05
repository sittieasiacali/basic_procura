@extends('layouts.app')

@section('title', 'System Health')

@php
    $light = fn (string $status) => match ($status) {
        'ok' => ['class' => 'status-success', 'text' => 'Healthy', 'icon' => 'check-circle', 'color' => 'text-success'],
        'warn' => ['class' => 'status-pending', 'text' => 'Needs attention', 'icon' => 'warning', 'color' => 'text-warning'],
        default => ['class' => 'status-danger', 'text' => 'Problem', 'icon' => 'x-circle', 'color' => 'text-danger'],
    };
    $overallLight = $light($overall);
@endphp

@section('content')
    <x-ui.page-header eyebrow="Administration" title="System Health" description="Read-only checks of the database, email queue, errors, stuck requests, data, disk and settings.">
        <x-slot:actions><a class="btn btn-outline-secondary" href="{{ route('admin.system-health') }}">Check again</a></x-slot:actions>
    </x-ui.page-header>

    <div class="alert {{ ['ok' => 'alert-success', 'warn' => 'alert-warning'][$overall] ?? 'alert-danger' }} d-flex align-items-center gap-2" data-overall="{{ $overall }}">
        <x-ui.icon :name="$overallLight['icon']" size="20" />
        <div>
            <strong>{{ ['ok' => 'Everything looks healthy.', 'warn' => 'The system works, but some items need attention.'][$overall] ?? 'Something is broken. Start with the red items.' }}</strong>
            <div class="small">Checked {{ $checkedAt->format('M j, Y g:i A') }}</div>
        </div>
    </div>

    <div class="row g-3">
        @foreach($checks as $check)
            @php($l = $light($check['status']))
            <div class="col-12 col-lg-6">
                <div class="card h-100" data-check="{{ $check['key'] }}" data-status="{{ $check['status'] }}">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                        <h2 class="section-title mb-0 d-flex align-items-center gap-2"><span class="{{ $l['color'] }}"><x-ui.icon :name="$l['icon']" size="18" /></span>{{ $check['label'] }}</h2>
                        <span class="status-badge {{ $l['class'] }}">{{ $l['text'] }}</span>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">{{ $check['summary'] }}</p>
                        @if($check['help'])
                            <p class="small text-muted mb-2"><strong>What to do:</strong> {{ $check['help'] }}</p>
                        @endif
                        @if($check['details'] !== [])
                            <details @if($check['status'] === 'fail') open @endif>
                                <summary class="small">Show {{ count($check['details']) }} item(s)</summary>
                                <ul class="list-unstyled small mt-2 mb-0">
                                    @foreach($check['details'] as $row)
                                        <li class="border-top py-1">
                                            @if(! empty($row['url']))
                                                <a href="{{ $row['url'] }}" class="fw-semibold">{{ $row['label'] }}</a>
                                            @else
                                                <span class="fw-semibold">{{ $row['label'] }}</span>
                                            @endif
                                            <span class="text-muted">— {{ $row['value'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
