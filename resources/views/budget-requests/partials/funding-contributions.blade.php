@if($fundingLine->funding_allocations)
<section class="my-3" aria-label="Campaign source contributions">
    <h3 class="h6 fw-semibold">Campaign source contributions @if($fundingLine->purchaseOrder)&mdash; {{ $fundingLine->purchaseOrder->po_number }}@endif</h3>
    <div class="table-responsive"><table class="table table-sm align-middle" data-mobile-cards>
        <thead><tr><th>Campaign source account</th><th>Account number</th><th>Contribution</th></tr></thead><tbody>
        @foreach($fundingLine->sourceContributions() as $source)
            <tr><td>{{ $source['campaign_name'] }}</td><td>{{ $source['account_number'] }}</td><td>&#8369; {{ number_format((float) $source['amount'], 2) }}</td></tr>
        @endforeach
        </tbody><tfoot><tr><th colspan="2">Total contribution</th><td>&#8369; {{ number_format((float) $fundingLine->amount, 2) }}</td></tr></tfoot>
    </table></div>
</section>
@endif
