@extends('layouts.app')

@section('title', 'Voided Monitoring')

@section('content')
    <div class="dashboard-page voided-monitoring-page">
        <header class="dashboard-page-header">
            <div class="dashboard-heading-block">
                <nav class="dashboard-breadcrumb" aria-label="Breadcrumb">
                    <a href="{{ route('dashboard') }}">Workspace</a><span aria-hidden="true">/</span><span aria-current="page">Voided Monitoring</span>
                </nav>
                <h1>Voided Monitoring</h1>
                <p class="dashboard-greeting">Track withdrawn requests and acknowledge the ones handled by your team.</p>
            </div>
            <div class="dashboard-header-actions page-actions d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary" href="{{ route('dashboard') }}">Back to dashboard</a>
                <a class="btn btn-outline-primary d-inline-flex align-items-center gap-2" href="{{ route('workflow-tracker.index') }}"><x-ui.icon name="flow" size="16" />Workflow Tracker</a>
            </div>
        </header>

        @include('dashboard.partials.voided-monitoring', [
            'voidedMonitoringDescription' => 'Canvassing, PO Maker, Prepare Budget, and Final Budget Request in one register. Processing pauses when a request is withdrawn.',
            'voidedMonitoringEmptyDescription' => 'Withdrawn requests appear here while waiting for acknowledgment and remain here once voided. Adjust the search or filters if you expected results.',
        ])
    </div>
@endsection
