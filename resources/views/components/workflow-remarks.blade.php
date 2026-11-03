@props(['request' => null, 'requestId' => null, 'budget' => null, 'heading' => false, 'timeline' => false])
@php
    $remarkRequestIds = $budget
        ? collect($budget->lines->all())->map(fn ($line) => $line->purchaseOrder?->purchase_request_id)->push($budget->purchase_request_id)->filter()->unique()
        : collect([$request?->id ?? $requestId])->filter();
    $workflowRemarkRows = $remarkRequestIds->flatMap(fn ($id) => app(\App\Support\WorkflowRemarks::class)->forRequest((int) $id));
@endphp
@if($timeline)
    {{-- Conversation-style feed for the request page: who said it first, then what was said. --}}
    @if($workflowRemarkRows->isEmpty())
        <div {{ $attributes->class(['workflow-remarks', 'workflow-remarks--timeline', 'workflow-remarks-empty']) }}>N/A</div>
    @else
        <ol {{ $attributes->class(['workflow-remarks', 'workflow-remarks--timeline']) }}>
            @foreach($workflowRemarkRows as $remark)
                @php
                    $tone = str((string) $remark['action'].' '.$remark['stage'])->upper();
                    $tone = match (true) {
                        $tone->contains('REJECT') => 'danger',
                        $tone->contains('RETURN') => 'warning',
                        $tone->contains('APPROV') => 'success',
                        default => 'neutral',
                    };
                    $initials = collect(preg_split('/\s+/', trim($remark['actor'])))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('');
                @endphp
                <li class="workflow-remarks-entry">
                    <span class="workflow-remarks-avatar" aria-hidden="true">{{ $initials }}</span>
                    <div class="workflow-remarks-body">
                        <div class="workflow-remarks-head">
                            <span class="workflow-remarks-actor">{{ $remark['actor'] }}</span>
                            <span class="workflow-remarks-stage workflow-remarks-stage--{{ $tone }}">{{ $remark['stage'] }}</span>
                            @if($remarkRequestIds->count() > 1)<span class="workflow-remarks-request">{{ $remark['request'] }}</span>@endif
                            @if($remark['at'])<time class="workflow-remarks-time" datetime="{{ $remark['at']->toIso8601String() }}" title="{{ $remark['at']->format('l, F j, Y g:i A') }}">{{ $remark['at']->format('M j, Y') }} · {{ $remark['at']->format('g:i A') }}</time>@endif
                        </div>
                        <div class="workflow-remarks-text">{{ $remark['text'] }}</div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
@else
<div {{ $attributes->class(['workflow-remarks']) }}>
    @if($heading)<div class="workflow-remarks-heading">Workflow remarks</div>@endif
    @forelse($workflowRemarkRows as $remark)
        <div class="workflow-remarks-entry">
            <div class="workflow-remarks-meta"><strong>{{ $remark['stage'] }}</strong> · {{ $remark['actor'] }}@if($remark['at']) · {{ $remark['at']->format('M j, Y g:i A') }}@endif @if($remarkRequestIds->count() > 1) · {{ $remark['request'] }}@endif</div>
            <div class="workflow-remarks-text">{{ $remark['text'] }}</div>
        </div>
    @empty
        <span class="text-muted">N/A</span>
    @endforelse
</div>
@endif
