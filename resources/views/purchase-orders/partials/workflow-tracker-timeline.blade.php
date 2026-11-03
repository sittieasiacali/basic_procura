<ol class="workflow-tracker-steps" aria-label="Purchase Order workflow stages">
    @foreach($tracker['steps'] as $step)
        <li class="workflow-tracker-step workflow-tracker-step--{{ $step['state'] }}">
            <span class="workflow-tracker-marker" aria-hidden="true">@if($step['state'] === 'complete')<x-ui.icon name="check" size="11" />@elseif($step['state'] === 'rejected')<x-ui.icon name="x-circle" size="11" />@endif</span>
            <div class="workflow-tracker-step-body">
                <div class="workflow-tracker-step-top">
                    <strong>{{ $step['label'] }}</strong>
                    <span class="workflow-tracker-state">{{ match ($step['state']) { 'complete' => 'Completed', 'current' => 'In progress', 'rejected' => 'Rejected', default => 'Waiting' } }}</span>
                </div>
                <div class="workflow-tracker-meta">{{ $step['role'] }}@if($step['actor']) <span aria-hidden="true">·</span> {{ $step['actor'] }}@endif @if($step['at']) <span aria-hidden="true">·</span> {{ $step['at']->format('M j, Y g:i A') }}@elseif($step['state'] !== 'complete') <span aria-hidden="true">·</span> No action yet @endif</div>
                @if($step['note'])<p class="workflow-tracker-note">{{ $step['note'] }}</p>@endif
            </div>
        </li>
    @endforeach
</ol>
