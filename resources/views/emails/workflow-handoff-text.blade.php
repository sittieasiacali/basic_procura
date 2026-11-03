{!! config('app.name') !!} - Workflow update

Request number: {!! $requestNumber !!}
Current stage: {!! $stage !!}

Hello, the workflow for a request from your assigned Team Leader has been updated.
@if ($requestDetails !== [])

REQUEST DETAILS
@foreach ($requestDetails as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach
Requested items:
@foreach ($items as $item)
- {!! $item !!}
@endforeach
@endif
@if ($processDetails !== [])

PROCESSING DETAILS
@foreach ($processDetails as $detail)
{!! $detail['label'] !!}: {!! $detail['value'] !!}
@endforeach
@endif
@if ($quotations !== [])

QUOTATIONS
@foreach ($quotations as $quote)
- {!! $quote['raw'] !!}
@endforeach
@endif
@if ($workflowStep !== null)

REQUEST WORKFLOW
@foreach ($workflowSteps as $i => $label)
[{!! $i + 1 < $workflowStep ? "x" : ($i + 1 === $workflowStep ? ">" : " ") !!}] {!! $label !!}
@endforeach
@endif

This is an informational update. No management review of the purchase request is required.
