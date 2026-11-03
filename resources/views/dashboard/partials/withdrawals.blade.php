{{-- Requests the requestor withdrew while they were at this user's stage. Processing is paused until acknowledged. --}}
@if($withdrawalsToAcknowledge->isNotEmpty())
    <section class="card dashboard-panel withdrawal-panel" aria-labelledby="withdrawals-heading">
        <div class="card-header">
            <div>
                <h2 id="withdrawals-heading">Withdrawals to acknowledge <span class="badge rounded-pill text-bg-warning">{{ $withdrawalsToAcknowledge->count() }}</span></h2>
                <p>The requestor withdrew these while they were at your stage. Processing is paused; acknowledge to void the request.</p>
            </div>
        </div>
        <ul class="withdrawal-list list-unstyled mb-0">
            @foreach($withdrawalsToAcknowledge as $withdrawn)
                <li class="withdrawal-list-item">
                    <div class="withdrawal-list-main">
                        <div class="withdrawal-list-heading">
                            <a class="request-number" href="{{ route('purchase-requests.show', $withdrawn) }}#withdrawal-status" data-workflow-request-preview>{{ $withdrawn->request_number }}</a>
                            <span class="fw-semibold">{{ $withdrawn->title }}</span>
                        </div>
                        <div class="small text-muted">
                            {{ $withdrawn->withdrawnBy?->name ?? $withdrawn->requestor?->name }} · stopped at {{ \App\Support\RequestWithdrawal::stageLabel($withdrawn->withdrawal_stage) }} · {{ $withdrawn->withdrawn_at?->format('M j, Y g:i A') }}
                        </div>
                        <p class="withdrawal-list-reason mb-0">“{{ $withdrawn->withdrawal_reason }}”</p>
                    </div>
                    <a class="btn btn-sm btn-warning text-nowrap" href="{{ route('purchase-requests.show', $withdrawn) }}#withdrawal-status" data-workflow-action-modal data-workflow-action-title="Acknowledge withdrawal">Acknowledge Withdrawal <x-ui.icon name="chevron-right" size="15" /></a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
