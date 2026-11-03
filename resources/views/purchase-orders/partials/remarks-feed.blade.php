@php
    $stageName = fn (string $stage) => (string) str($stage)->replaceMatches('/^Po\b/', 'PO')->replaceMatches('/ By$/', '');
@endphp
@if($groups->isEmpty())
    <x-ui.empty-state icon="message-square" title="No remarks found" :description="$search !== '' ? 'No remarks match the search.' : 'Remarks appear here as each workflow step is done.'" />
@else
    @foreach($groups as $day => $entries)
        <section class="remarks-feed-day" aria-label="{{ $day }}">
            <h3 class="remarks-feed-day-label">{{ $day }}</h3>
            <ol class="workflow-tracker-steps remarks-timeline remarks-feed">
                @foreach($entries as $entry)
                    <li @class(['workflow-tracker-step', 'workflow-tracker-step--complete', 'is-new' => $entry['new']])>
                        <span class="workflow-tracker-marker" aria-hidden="true"></span>
                        <div class="workflow-tracker-step-body">
                            <div class="workflow-tracker-step-top">
                                <span><strong>{{ $stageName($entry['stage']) }}</strong>@if($entry['new'])<span class="remarks-feed-new">New</span>@endif</span>
                                @if($showOrder ?? true)<a class="remarks-feed-po" href="{{ route('workflow-tracker.show', $entry['order']) }}" title="Open {{ $entry['title'] }} in the Workflow Tracker">{{ $entry['order']->po_number ?? $entry['request'] }} <x-ui.icon name="chevron-right" size="12" /></a>@endif
                            </div>
                            <div class="workflow-tracker-meta">{{ $entry['actor'] }}@if($entry['at']) <span aria-hidden="true">·</span> <time datetime="{{ $entry['at']->toIso8601String() }}" title="{{ $entry['at']->format('M j, Y g:i A') }}">{{ $entry['at']->diffForHumans() }}</time>@endif</div>
                            <p class="workflow-tracker-note">{{ $entry['text'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endforeach
@endif
