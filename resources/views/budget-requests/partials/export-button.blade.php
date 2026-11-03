{{-- Exports the Budget Requests in view: the current stage tab, search and transaction type. --}}
<form class="br-export-form" method="GET" action="{{ route('budget.export') }}">
    @if($exportStage)<input type="hidden" name="stage" value="{{ $exportStage }}">@endif
    @if($isApprovedRegister ?? false)<input type="hidden" name="register_tab" value="approved">@endif
    @if($isMakerWorkflow)<input type="hidden" name="maker_only" value="1">@endif
    @foreach(['br_search', 'transaction_type'] as $exportFilter)
        @if(is_string(request($exportFilter)) && request($exportFilter) !== '')<input type="hidden" name="{{ $exportFilter }}" value="{{ request($exportFilter) }}">@endif
    @endforeach
    <button class="btn btn-sm btn-outline-primary br-export-button" type="submit" @disabled($budgetRequests->total() === 0)>
        <x-ui.icon name="file-text" size="15" />
        Export list ({{ number_format($budgetRequests->total()) }})
    </button>
</form>
