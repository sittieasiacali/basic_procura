@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $hour = (int) now()->format('G');
        $greeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
        $isPurchasingDashboard = $user->hasAnyRole([
            \App\Enums\RoleSlug::Purchasing->value,
            \App\Enums\RoleSlug::ProcurementStaff->value,
        ]);
        $isFinanceDashboard = $user->hasAnyRole([
            \App\Enums\RoleSlug::Finance->value,
            \App\Enums\RoleSlug::FinanceBudgetStaff->value,
        ]);
        $isBudgetMakerDashboard = $user->hasRole(\App\Enums\RoleSlug::BudgetMaker->value) || ($creatorWorkflow ?? null) === 'budget-request';
        $isBudgetProcessedView = ($creatorWorkflow ?? null) === 'budget-request' && request('budget_view') === 'processed';
        $activeCreatorTab = $isBudgetProcessedView ? 'budget-request-processed' : ($creatorWorkflow ?? null);
        // Tint the queue below the step tabs in the active step's colour so switching tabs is obvious.
        $activeStep = $user->hasRole(\App\Enums\RoleSlug::PurchasingTeam->value) ? match ($activeCreatorTab) {
            'canvassing' => ['Step 1', 'Canvassing', 'supplier'],
            'purchase-order' => ['Step 2', 'Purchase Orders', 'receipt'],
            'budget-request' => ['Step 3', 'Prepare Budget', 'wallet'],
            'budget-request-processed' => ['History', 'Final Budget Request', 'check-circle'],
            default => null,
        } : null;
        $activeStepKey = $activeCreatorTab;
        // Finance gets the same step cards and tinted queue: awaiting payment → receiving → inventory.
        $financeSteps = [
            'awaiting' => ['Awaiting payment', 'payment', 'Budget-approved, ready to pay'],
            'partial' => ['Partially paid', 'hourglass', 'Balance still due'],
            'receiving' => ['For receiving', 'receive', 'Paid, waiting for delivery'],
            'inventory' => ['Inventory', 'inventory', 'Goods received and logged'],
        ];
        $canBatchPay = ($isPaymentDashboard ?? false) && $awaitingPaymentRequests->getCollection()
            ->contains(fn ($purchaseRequest) => $purchaseRequest->budgetRequest && $user->can('processPayment', $purchaseRequest));
        $financeStepCounts = ($isPaymentDashboard ?? false) ? [
            'awaiting' => $awaitingPaymentRequests->total(),
            'partial' => $partialPaymentRequests->total(),
            'receiving' => $forReceivingRequests->total(),
            'inventory' => $inventoryItems->total(),
        ] : [];
        if (! $activeStep && ($isPaymentDashboard ?? false)) {
            $financeStepNumber = array_search($paymentTab, array_keys($financeSteps), true) + 1;
            $activeStep = ['Step '.$financeStepNumber, $financeSteps[$paymentTab][0], $financeSteps[$paymentTab][1]];
            $activeStepKey = 'finance-'.$paymentTab;
        }
        $pageTitle = $isPurchasingDashboard
            ? 'Operational overview'
            : ($isFinanceDashboard ? 'Finance overview' : $profile['eyebrow']);
        $kpiColumnClass = count($kpis) === 6 ? 'col-xl-2' : 'col-xl-3';
    @endphp

    <div class="dashboard-page {{ $isPurchasingDashboard ? 'dashboard-page--purchasing' : '' }} {{ $isFinanceDashboard ? 'dashboard-page--finance' : '' }} {{ $user->hasAnyRole([\App\Enums\RoleSlug::ItStaff->value, \App\Enums\RoleSlug::PremisesStaff->value]) ? 'dashboard-page--screening' : '' }} {{ $user->hasRole(\App\Enums\RoleSlug::ItStaff->value) ? 'dashboard-page--it' : '' }}">
        <header class="dashboard-page-header" @if($user->hasRole(\App\Enums\RoleSlug::ItStaff->value)) style="--dashboard-header-image: url('{{ asset('images/it-dashboard-header.png') }}')" @endif>
            <div class="dashboard-heading-block">
                <div class="dashboard-heading-top">

                    <nav class="dashboard-breadcrumb" aria-label="Breadcrumb">
                        <span>Workspace</span><span aria-hidden="true">/</span><span aria-current="page">Dashboard</span>
                    </nav>
                </div>
                <h1>{{ $pageTitle }}</h1>
                <p class="dashboard-greeting">{{ $greeting }}, {{ $user->name }}.</p>
            </div>
            <div class="dashboard-header-actions page-actions d-flex flex-wrap gap-2">
                @if($user->hasRole(\App\Enums\RoleSlug::PurchasingTeam->value))
                    <a class="btn btn-outline-primary d-inline-flex align-items-center gap-2" href="{{ route('workflow-tracker.index') }}"><x-ui.icon name="flow" size="16" />Workflow Tracker</a>
                    <a class="btn btn-outline-danger d-inline-flex align-items-center gap-2" href="{{ route('voided-monitoring.index') }}"><x-ui.icon name="x-circle" size="16" />Voided Monitoring</a>
                @endif
                @if($myItemsCount > 0)
                    <a class="btn btn-outline-primary d-inline-flex align-items-center gap-2" href="{{ route('inventory.index', ['view' => 'released']) }}"><x-ui.icon name="check-circle" size="16" />My items</a>
                @endif
                @can('viewAny', \App\Models\InventoryItem::class)
                    <a class="btn btn-outline-primary dashboard-inventory-action d-inline-flex align-items-center gap-2" href="{{ route('inventory.index') }}">
                        <x-ui.icon name="inventory" size="16" />Inventory
                    </a>
                @endcan
                @can('create', \App\Models\PurchaseRequest::class)
                    <a class="btn btn-outline-primary dashboard-create-action d-inline-flex align-items-center gap-2" href="{{ route('purchase-requests.create') }}">
                        <x-ui.icon name="plus" size="16" />Create Request
                    </a>
                @endcan
            </div>
        </header>

        <section class="card dashboard-context-bar" aria-label="Workspace and quick links">
            <h2 id="workspace-heading" class="visually-hidden">Workspace</h2>
            <dl class="dashboard-context-details" aria-labelledby="workspace-heading">
                <div>
                    <dt><x-ui.icon name="users" size="16" />Role</dt>
                    <dd>{{ $user->activeRole()?->name ?? 'Unassigned' }}</dd>
                </div>
                <div>
                    <dt><x-ui.icon name="campaign" size="16" />Team</dt>
                    <dd>{{ $user->campaign?->name ?? $user->department ?? 'Not assigned' }}</dd>
                </div>
                <div>
                    <dt><x-ui.icon name="report" size="16" />Department</dt>
                    <dd>{{ $user->department ?: 'Not assigned' }}</dd>
                </div>
                <div>
                    <dt><x-ui.icon name="check" size="16" />Status</dt>
                    <dd class="{{ $user->is_active ? 'is-active' : 'is-inactive' }}"><span class="status-dot"></span>{{ $user->is_active ? 'Active' : 'Inactive' }}</dd>
                </div>
            </dl>

            @if(! $isPurchaseOrderApprover && ! $isWorkflowDashboard)
                <nav class="dashboard-context-links" aria-labelledby="quick-links-heading">
                    <h2 id="quick-links-heading" class="dashboard-context-links-label">Quick links</h2>
                    <a class="dashboard-context-link" href="{{ route('purchase-requests.index') }}" title="Search requests available to your role">
                        <x-ui.icon name="request" size="16" />Purchase Requests
                    </a>
                    @can('create', \App\Models\PurchaseRequest::class)
                        <a class="dashboard-context-link" href="{{ route('purchase-requests.create') }}" title="Start a new purchase request">
                            <x-ui.icon name="plus" size="16" />Create Request
                        </a>
                    @endcan
                    @can('viewAny', \App\Models\User::class)
                        <a class="dashboard-context-link" href="{{ route('admin.accounts.index') }}" title="Review users and account status">
                            <x-ui.icon name="users" size="16" />Manage Accounts
                        </a>
                    @endcan
                </nav>
            @endif
        </section>

        <section aria-label="Operational metrics">
            <div class="row g-3 dashboard-kpi-grid">
                @foreach($kpis as $kpi)
                    <div class="col-12 col-sm-6 col-md-4 {{ $kpiColumnClass }}">
                        <div class="card dashboard-kpi dashboard-kpi--{{ $kpi['tone'] }} {{ $isPurchasingDashboard && $kpi['label'] === $profile['actionLabel'] ? 'dashboard-kpi--priority' : '' }} h-100">
                            <div class="card-body">
                                <div class="dashboard-kpi-heading-row">
                                    <div class="kpi-label">{{ $kpi['label'] }}</div>
                                    <span class="kpi-icon kpi-icon--{{ $kpi['tone'] }}" aria-hidden="true">
                                        <x-ui.icon :name="$kpi['icon']" size="20" />
                                    </span>
                                </div>
                                <div class="dashboard-kpi-value" data-kpi="{{ str($kpi['label'])->slug() }}">{{ number_format($kpi['value']) }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        @if($accountMonitoring)
            @include('dashboard.partials.account-monitoring')
        @endif

        {{-- Purchasing Team step tabs filter each section on its own instead. --}}
        @unless($creatorWorkflow || $isPaymentDashboard)
            @include('dashboard.partials.filters', ['idPrefix' => 'dashboard'])
        @endunless

        @if($user->hasRole(\App\Enums\RoleSlug::PurchasingTeam->value))
            <nav class="dashboard-creator-tabs" aria-label="Purchasing Team workflow queues" data-creator-workflow-tabs>
                @foreach([
                    'canvassing' => ['Canvassing', 'supplier', 'Compare supplier quotations'],
                    'purchase-order' => ['Purchase Orders', 'receipt', 'Prepare approved purchases'],
                    'budget-request' => ['Prepare Budget', 'wallet', 'Create budget requests'],
                    'budget-request-processed' => ['Final Budget Request', 'check-circle', 'Review submitted budget requests'],
                ] as $creatorTabKey => [$creatorTabLabel, $creatorTabIcon, $creatorTabHint])
                    @if(! $loop->first)<span class="dashboard-creator-connector" aria-hidden="true"><x-ui.icon name="chevron-right" size="16" /></span>@endif
                    <a class="dashboard-creator-tab dashboard-creator-tab--{{ $creatorTabKey }} {{ $activeCreatorTab === $creatorTabKey ? 'is-active' : '' }}" href="{{ route('dashboard', $creatorTabKey === 'budget-request-processed' ? ['workflow' => 'budget-request', 'budget_view' => 'processed'] : ['workflow' => $creatorTabKey]) }}" @if($activeCreatorTab === $creatorTabKey) aria-current="page" @endif>
                        <span class="dashboard-creator-tab-icon" aria-hidden="true"><x-ui.icon :name="$creatorTabIcon" size="18" /></span>
                        <span class="dashboard-creator-tab-text">
                            <span class="dashboard-creator-tab-step">{{ $creatorTabKey === 'budget-request-processed' ? 'History' : 'Step '.$loop->iteration }}</span>
                            <span class="dashboard-creator-tab-label">{{ $creatorTabLabel }}</span>
                            <span class="dashboard-creator-tab-hint">{{ $creatorTabHint }}</span>
                        </span>
                        @php($creatorTabCount = $creatorWorkflowCounts[$creatorTabKey] ?? 0)
                        <span class="dashboard-creator-tab-count {{ $creatorTabCount > 0 ? 'has-items' : '' }}" title="{{ $creatorTabCount }} {{ $creatorTabKey === 'budget-request-processed' ? 'processed' : 'waiting' }}">{{ number_format($creatorTabCount) }}<span class="visually-hidden"> {{ $creatorTabKey === 'budget-request-processed' ? 'processed' : 'waiting' }}</span></span>
                    </a>
                @endforeach
            </nav>
        @endif

        @if($isPaymentDashboard)
            <nav class="dashboard-creator-tabs" aria-label="Finance workflow queues" data-creator-workflow-tabs>
                @foreach($financeSteps as $financeTabKey => [$financeTabLabel, $financeTabIcon, $financeTabHint])
                    @if(! $loop->first)<span class="dashboard-creator-connector" aria-hidden="true"><x-ui.icon name="chevron-right" size="16" /></span>@endif
                    <a class="dashboard-creator-tab dashboard-creator-tab--finance-{{ $financeTabKey }} {{ $paymentTab === $financeTabKey ? 'is-active' : '' }}" href="{{ route('dashboard', $financeTabKey === 'awaiting' ? [] : ['payment_tab' => $financeTabKey]) }}" @if($paymentTab === $financeTabKey) aria-current="page" @endif>
                        <span class="dashboard-creator-tab-icon" aria-hidden="true"><x-ui.icon :name="$financeTabIcon" size="18" /></span>
                        <span class="dashboard-creator-tab-text">
                            <span class="dashboard-creator-tab-step">Step {{ $loop->iteration }}</span>
                            <span class="dashboard-creator-tab-label">{{ $financeTabLabel }}</span>
                            <span class="dashboard-creator-tab-hint">{{ $financeTabHint }}</span>
                        </span>
                        <span class="dashboard-creator-tab-count {{ $financeStepCounts[$financeTabKey] > 0 ? 'has-items' : '' }}">{{ number_format($financeStepCounts[$financeTabKey]) }}</span>
                    </a>
                @endforeach
            </nav>
        @endif

        <div @class(['dashboard-content-grid', 'dashboard-step-theme dashboard-step-theme--'.$activeStepKey => $activeStep])>
            @if($activeStep)
                <div class="dashboard-step-marker" role="status">
                    <span class="dashboard-step-marker-icon" aria-hidden="true"><x-ui.icon :name="$activeStep[2]" size="16" /></span>
                    <span>You are viewing <strong>{{ $activeStep[0] }} · {{ $activeStep[1] }}</strong></span>
                </div>
            @endif
            <div class="dashboard-primary-column">
            @include('dashboard.partials.withdrawals')
            @if($isPaymentDashboard)
                @php($paymentTabRows = $paymentTab === 'receiving' ? $forReceivingRequests : $awaitingPaymentRequests)
                <section class="card dashboard-panel dashboard-recent-panel" aria-labelledby="payment-queue-heading">
                    <div class="card-header">
                        <div>
                            <h2 id="payment-queue-heading">{{ match ($paymentTab) { 'partial' => 'Partially paid', 'receiving' => 'For receiving', 'inventory' => 'Inventory', default => 'Awaiting payment' } }}</h2>
                            <p>{{ match ($paymentTab) {
                                'partial' => 'Requests with part of the amount paid. Record the remaining balance to mark them fully paid and move them to receiving.',
                                'receiving' => 'Paid requests waiting for delivery. Mark them received to add the goods to inventory.',
                                'inventory' => 'Goods are added automatically when a payment or delivery is marked Received. Releasing is done by Inventory, IT and Premises.',
                                default => 'Budget-approved requests waiting for payment. Select a Budget Request to preview it.',
                            } }}</p>
                        </div>
                    </div>
                    @if($paymentTab === 'inventory')
                        @include('partials.table-toolbar', ['toolbar' => [
                            'prefix' => 'inventory',
                            'label' => 'Filter inventory',
                            'anchor' => 'payment-queue-heading',
                            'searchPlaceholder' => 'Search code, description, serial, request…',
                            'statusLabel' => 'Stock',
                            'statusPlaceholder' => 'All stock',
                            'statusOptions' => ['in_stock' => 'In stock', 'released' => 'Fully released'],
                            'dateLabel' => 'Logged',
                        ]])
                        @include('inventory.partials.items-table', ['items' => $inventoryItems])
                        @if($inventoryItems->hasPages())<div class="card-footer">{{ $inventoryItems->fragment('payment-queue-heading')->links() }}</div>@endif
                    @elseif($paymentTab === 'partial')
                        @include('dashboard.partials.partial-payments', ['partialRequests' => $partialPaymentRequests])
                    @else
                    @include('partials.table-toolbar', ['toolbar' => [
                        'prefix' => $paymentTab === 'receiving' ? 'receiving' : 'awaiting',
                        'label' => $paymentTab === 'receiving' ? 'Filter requests for receiving' : 'Filter requests awaiting payment',
                        'anchor' => 'payment-queue-heading',
                        'searchPlaceholder' => 'Search BR number, PO, title…',
                        'dateLabel' => 'Updated',
                    ]])
                    {{-- Awaiting payment: tick POs, then record one payment for all of them (app.js keeps the button in sync). --}}
                    <div @if($paymentTab !== 'receiving') data-select-scope @endif>
                    @if($paymentTab !== 'receiving' && $canBatchPay)
                        <div class="br-action-bar finance-batch-bar" data-br-final>
                            <span class="br-action-hint" data-br-final-hint>Tick the POs to pay, then click Record payment to pay them together.</span>
                            <a class="btn btn-sm btn-primary br-final-button disabled" href="{{ route('payments.batch') }}" aria-disabled="true" data-br-final-button data-base-url="{{ route('payments.batch') }}" data-workflow-action-modal data-workflow-action-title="Record payment">
                                <x-ui.icon name="payment" size="15" />
                                <span data-br-final-label data-base-label="Record payment">Record payment</span>
                            </a>
                        </div>
                    @endif
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 dashboard-table finance-payment-table">
                            <thead><tr>@if($paymentTab !== 'receiving' && $canBatchPay)<th class="br-select-cell"><input class="form-check-input" type="checkbox" aria-label="Select all POs awaiting payment on this page" data-br-select-all></th>@endif<th class="table-row-number">#</th><th>BR Number</th><th>Project title</th><th class="text-end">{{ $paymentTab === 'receiving' ? 'Paid' : 'Balance due' }}</th><th class="text-end">Action</th></tr></thead>
                            <tbody>
                            @forelse($paymentTabRows as $purchaseRequest)
                                @php($latestPayment = $paymentTab === 'receiving' ? $purchaseRequest->payments->first() : null)
                                <tr>
                                    @if($paymentTab !== 'receiving' && $canBatchPay)
                                        <td class="br-select-cell">@can('processPayment', $purchaseRequest)@if($purchaseRequest->budgetRequest)<input class="form-check-input" type="checkbox" value="{{ $purchaseRequest->id }}" data-amount="{{ max(0, (float) ($purchaseRequest->purchaseOrder?->total_amount ?? $purchaseRequest->total_amount) - (float) ($purchaseRequest->amount_paid ?? 0)) }}" aria-label="Select {{ $purchaseRequest->purchaseOrder?->po_number ?? $purchaseRequest->request_number }} for payment" data-br-select>@endif @endcan</td>
                                    @endif
                                    <td class="table-row-number">{{ $paymentTabRows->firstItem() + $loop->index }}</td>
                                    <td class="text-nowrap">
                                        @if($purchaseRequest->budgetRequest)
                                            <a class="budget-preparation-code" href="{{ route('budget-requests.document', $purchaseRequest->budgetRequest) }}" data-br-preview-url="{{ route('budget-requests.document', $purchaseRequest->budgetRequest) }}" aria-label="Preview Budget Request {{ $purchaseRequest->budgetRequest->displayNumber() }}"><x-ui.icon name="receipt" size="14" />{{ $purchaseRequest->budgetRequest->displayNumber() }}</a>
                                            <div class="small text-success mt-1">Budget approved</div>
                                        @else
                                            <span class="text-muted">Not linked</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="fw-semibold">{{ $purchaseRequest->title }}</span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @if($paymentTab === 'receiving')
                                            @if($latestPayment)
                                                <div class="fw-semibold">&#8369; {{ number_format((float) $latestPayment->amount, 2) }}</div>
                                                <div class="small text-muted">{{ $latestPayment->paid_at?->format('M j, Y') }}</div>
                                            @else
                                                <span class="text-muted">Not recorded</span>
                                            @endif
                                        @else
                                            @php($dashboardAmountDue = (float) ($purchaseRequest->purchaseOrder?->total_amount ?? $purchaseRequest->total_amount))
                                            @php($dashboardAmountPaid = (float) ($purchaseRequest->amount_paid ?? 0))
                                            <span class="fw-semibold">&#8369; {{ number_format(max(0, $dashboardAmountDue - $dashboardAmountPaid), 2) }}</span>
                                            @if($dashboardAmountPaid > 0)
                                                <div class="small text-warning-emphasis">Partially paid · &#8369; {{ number_format($dashboardAmountPaid, 2) }} of &#8369; {{ number_format($dashboardAmountDue, 2) }}</div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @if($paymentTab === 'receiving')
                                            @can('receiveGoods', $purchaseRequest)
                                                <a class="btn btn-sm btn-outline-primary dashboard-row-action" href="{{ route('purchase-requests.show', ['purchaseRequest' => $purchaseRequest, 'from_stage' => 'receiving']) }}#receiving-actions" data-workflow-action-modal data-workflow-action-title="Mark as received">Mark as received <x-ui.icon name="chevron-right" size="15" /></a>
                                            @endcan
                                        @else
                                            @can('processPayment', $purchaseRequest)
                                                <a class="btn btn-sm btn-outline-primary dashboard-row-action" href="{{ route('purchase-requests.show', ['purchaseRequest' => $purchaseRequest, 'from_stage' => 'payment']) }}#payment-actions" data-workflow-action-modal data-workflow-action-title="Record payment">Record payment <x-ui.icon name="chevron-right" size="15" /></a>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $paymentTab !== 'receiving' && $canBatchPay ? 6 : 5 }}">
                                    @if($paymentTab === 'receiving')
                                        <x-ui.empty-state icon="receive" title="Nothing waiting for receiving" description="Requests paid with the status For receiving appear here until their goods are received." />
                                    @else
                                        <x-ui.empty-state icon="payment" title="No requests awaiting payment" description="Requests appear here once their Budget Request is approved. Adjust the search or filters if you expected results." />
                                    @endif
                                </td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    </div>
                    @if($paymentTabRows->hasPages())<div class="card-footer">{{ $paymentTabRows->fragment('payment-queue-heading')->links() }}</div>@endif
                    @endif
                </section>
                @include('purchase-orders.partials.preview-modal')
            @elseif(! $isBudgetProcessedView)
            <section class="card dashboard-panel dashboard-actions-panel" aria-labelledby="my-actions-heading" @if($isBudgetMakerDashboard) data-budget-request-queue @endif>
                <div class="card-header">
                    <div>
                        <h2 id="my-actions-heading">{{ $profile['actionLabel'] }}</h2>
                        <p>{{ $executiveSummary ? 'Requests waiting for your decision, '.($approvalOrder === 'oldest' ? 'oldest first' : 'newest first') : 'Requests ready for your next step' }}</p>
                    </div>
                    @if($executiveSummary)
                        <form method="GET" action="{{ route('dashboard') }}#my-actions-heading" class="d-flex flex-wrap align-items-center gap-2">
                            @foreach(request()->except('approval_order', 'approval_page') as $key => $value)
                                @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                            @endforeach
                            <label class="small text-muted" for="approval-order">Show</label>
                            <select class="form-select form-select-sm w-auto" name="approval_order" id="approval-order" onchange="this.form.submit()">
                                <option value="newest" @selected($approvalOrder === 'newest')>Newest submissions first</option>
                                <option value="oldest" @selected($approvalOrder === 'oldest')>Oldest waiting first</option>
                            </select>
                        </form>
                    @endif
                    @if($presidentActionsPagination && $actionCount > 0)
                        <span class="small text-muted">Showing {{ $presidentActionsPagination->firstItem() }}–{{ $presidentActionsPagination->lastItem() }} of {{ $presidentActionsPagination->total() }}</span>
                    @endif
                </div>
                @if($executiveSummary && $actionCount > 0)
                    <dl class="executive-summary">
                        <div class="executive-summary-item">
                            <dt>Awaiting decision</dt>
                            <dd>{{ number_format($actionCount) }} <span>{{ str('request')->plural($actionCount) }}</span></dd>
                        </div>
                        <div class="executive-summary-item">
                            <dt>Total value</dt>
                            <dd>&#8369; {{ number_format($executiveSummary['value'], 2) }}</dd>
                        </div>
                        <div class="executive-summary-item">
                            <dt>Oldest waiting</dt>
                            <dd>{{ $executiveSummary['oldest'] ? $executiveSummary['oldest']->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE) : '—' }}</dd>
                        </div>
                        <div @class(['executive-summary-item', 'is-alert' => $executiveSummary['overdue'] > 0])>
                            <dt>Waiting over 3 days</dt>
                            <dd>{{ number_format($executiveSummary['overdue']) }}</dd>
                        </div>
                    </dl>
                @endif
                @if($creatorWorkflow)
                    @include('partials.table-toolbar', ['toolbar' => [
                        'prefix' => 'action',
                        'label' => 'Filter '.$profile['actionLabel'],
                        'anchor' => 'my-actions-heading',
                        'searchPlaceholder' => $creatorWorkflow === 'budget-request' ? 'Search PO, request, title, requestor…' : 'Search request, title, requestor…',
                        'dateLabel' => 'Updated',
                    ]])
                @endif
                <div class="dashboard-request-list">
                    @forelse($myActions as $action)
                        @php($purchaseRequest = $action['request'])
                        @php($waitingDays = $executiveSummary ? (int) $purchaseRequest->updated_at->diffInDays(now()) : null)
                        <article @class(['dashboard-request-card', 'dashboard-request-card--executive' => $executiveSummary, 'is-overdue' => $waitingDays > 3])>
                            <div class="dashboard-request-card-topline">
                                @if($isBudgetMakerDashboard)
                                    <a class="request-number" href="{{ route('purchase-requests.show', $purchaseRequest) }}" data-workflow-request-preview>{{ $purchaseRequest->purchaseOrder->po_number }}</a>
                                @else
                                <a class="request-number" href="{{ route('purchase-requests.show', $purchaseRequest) }}" data-workflow-request-preview>{{ $purchaseRequest->request_number }}</a>
                                @endif
                                <x-status-badge :status="$purchaseRequest->status" />
                            </div>
                            <h3 class="request-title">{{ $purchaseRequest->title }}</h3>
                            @if($user->hasAnyRole([\App\Enums\RoleSlug::ItStaff->value, \App\Enums\RoleSlug::PremisesStaff->value]) && $purchaseRequest->requested_team === null && $purchaseRequest->assigned_user_id === null)
                                <span class="badge text-bg-light border text-secondary mb-2" title="Saved before the Screening team field existed, so both IT and Premises can claim it.">No team selected</span>
                            @endif
                            @if($user->hasAnyRole([\App\Enums\RoleSlug::ItStaff->value, \App\Enums\RoleSlug::PremisesStaff->value]) && $purchaseRequest->assigned_user_id === $user->id && $purchaseRequest->items->isNotEmpty())
                                <div class="small text-muted mb-2">Requested items: {{ $purchaseRequest->items->map(fn ($item) => $item->description.($item->quantity ? ' ('.rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.').' '.$item->unit.')' : ''))->implode(', ') }}</div>
                            @endif
                            <div class="request-metadata">
                                <span>{{ $purchaseRequest->requestor->name }}</span>
                                <span aria-hidden="true">&middot;</span>
                                <span>{{ $purchaseRequest->department_campaign }}</span>
                                <span aria-hidden="true">&middot;</span>
                                <span>
                                    @if($user->hasRole(\App\Enums\RoleSlug::BudgetMaker->value) && $purchaseRequest->purchaseOrder?->approved_at)
                                        PO approved {{ $purchaseRequest->purchaseOrder->approved_at->format('M j, Y g:i A') }}
                                    @else
                                        Updated {{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}
                                    @endif
                                </span>
                            </div>
                            @if($executiveSummary)
                                @if($purchaseRequest->quotations_count === 0)
                                    <div class="alert alert-warning py-2 small mb-2">No supplier quotation or pricing provided. President acknowledgement requested.</div>
                                @endif
                                <div class="executive-decision">
                                    <div>
                                        <div class="executive-decision-label">Recommended supplier</div>
                                        <div class="executive-decision-supplier">{{ $action['supplier'] ?? 'Not recorded' }}</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="executive-decision-label">Amount</div>
                                        @if($action['amount'] > 0)<div class="executive-decision-amount">&#8369; {{ number_format($action['amount'], 2) }}</div>@else<div class="executive-decision-supplier text-muted">Not quoted</div>@endif
                                    </div>
                                </div>
                                <span @class(['executive-waiting', 'is-overdue' => $waitingDays > 3]) title="Last updated {{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}">
                                    <x-ui.icon name="hourglass" size="13" />{{ $waitingDays === 0 ? 'Arrived today' : 'Waiting '.$waitingDays.' '.str('day')->plural($waitingDays) }}
                                </span>
                            @endif
                            @if($isBudgetMakerDashboard && $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForBudgetProcessing)
                                <ol class="budget-card-steps" aria-label="Budget Request progress: step 1 of 3">
                                    <li class="is-current">Prepare</li>
                                    <li>Finance</li>
                                    <li>Payment</li>
                                </ol>
                            @endif
                            
                            <div class="dashboard-request-card-footer">
                                @if($user->hasRole(\App\Enums\RoleSlug::BudgetMaker->value) && $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForBudgetProcessing && $purchaseRequest->purchaseOrder)
                                    <a class="btn btn-sm btn-outline-primary dashboard-row-action" href="{{ route('budget.create', ['purchase_request_id' => $purchaseRequest->id]) }}">Prepare Budget Request <x-ui.icon name="chevron-right" size="15" /></a>
                                @else
                                    <a class="btn btn-sm btn-outline-primary dashboard-row-action" href="{{ $action['url'] }}" data-workflow-action-modal data-workflow-action-title="{{ $action['label'] }}">{{ $action['label'] }} <x-ui.icon name="chevron-right" size="15" /></a>
                                @endif
                                @can('unclaim', $purchaseRequest)
                                    <form class="dashboard-unclaim-form" method="POST" action="{{ route('request-flow.unclaim', $purchaseRequest) }}" onsubmit="return confirm('Unclaim this request and return it to the screening queue?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-secondary dashboard-unclaim-button" type="submit">Unclaim</button>
                                    </form>
                                @endcan
                                @if($action['purchaseOrderPreviewUrl'])
                                    <button class="btn btn-sm btn-outline-secondary dashboard-row-action" type="button" data-po-preview-url="{{ $action['purchaseOrderPreviewUrl'] }}"><x-ui.icon name="eye" size="15" />View PO</button>
                                @endif
                                @unless($isBudgetMakerDashboard)
                                    <a class="dashboard-request-view-link" href="{{ route('purchase-requests.show', $purchaseRequest) }}" data-workflow-request-preview>View request</a>
                                @endunless
                            </div>
                        </article>
                    @empty
                        @if($actionSearch !== '')
                            <x-ui.empty-state class="dashboard-actions-empty-state" icon="search" title="No matching requests" description="Nothing in your queue matches “{{ $actionSearch }}”. Try a PO number, request number, title or requestor." />
                        @else
                            <x-ui.empty-state class="dashboard-actions-empty-state" icon="flow" title="No requests require your action" description="This queue updates automatically as requests reach a stage authorized for your role." />
                        @endif
                    @endforelse
                </div>
            </section>
            @endif

            @if($budgetRequests && ! $embedsBudgetRegister)
                @include('budget-requests.partials.register', [
                    'class' => 'dashboard-panel',
                    'filterUrl' => route('dashboard'),
                ])
            @endif

            @if($presidentActionsPagination && $presidentActionsPagination->hasPages())
                <div class="mb-4">{{ $presidentActionsPagination->fragment('my-actions-heading')->links() }}</div>
            @endif

            @if($isWorkflowDashboard && ($creatorWorkflow !== 'budget-request' || $isBudgetProcessedView))
                <section class="card dashboard-panel dashboard-recent-panel" aria-labelledby="processed-requests-heading">
                    @if($creatorWorkflow === 'budget-request')
                        <div class="card-header br-panel-header">
                            <span class="br-panel-icon" aria-hidden="true"><x-ui.icon name="check-circle" size="22" /></span>
                            <div>
                                <div class="br-panel-kicker">History</div>
                                <h2 id="processed-requests-heading">Final Budget Request</h2>
                                <p>Budget Requests prepared by the Budget Maker. Tick the ones waiting for Final BR to issue one group BR number.</p>
                            </div>
                        </div>
                    @else
                    <div class="card-header">
                        <div>
                            <h2 id="processed-requests-heading">{{ $creatorWorkflow ? match ($creatorWorkflow) {
                                'canvassing' => 'Canvassing processed',
                                'purchase-order' => 'Purchase Orders processed',
                                'budget-request' => 'Budget Maker processed',
                            } : (in_array($processedTab, ['created', 'approved'], true) ? 'Procurement Records' : 'Processed by me') }}</h2>
                            <p>{{ $creatorWorkflow ? match ($creatorWorkflow) {
                                'canvassing' => 'Requests you personally submitted to the President, with or without quotations.',
                                'purchase-order' => 'Purchase Orders prepared and submitted for checking.',
                                'budget-request' => 'Budget Requests submitted by Budget Maker.',
                            } : match ($processedTab) {
                                'created' => 'Budget Requests created by Purchasing.',
                                'approved' => 'View only: Budget Requests that passed final approval.',
                                default => 'Requests you completed and handed to the next workflow stage.',
                            } }}</p>
                        </div>
                    </div>
                    @endif
                    @if($creatorWorkflow === 'budget-request')
                        {{-- One search for every Final Budget Request tab: the tab counts show its matches. --}}
                        <div class="br-global-search">
                            <div class="br-global-search-label">
                                <x-ui.icon name="search" size="16" />
                                <span><strong>Search all Final Budget Requests</strong>
                                    @if(filled(request('br_search')) || filled(request('transaction_type')))
                                        <small>Results in every tab: {{ number_format($processedTabCounts['processed'] ?? 0) }} found. Switch tabs to narrow by stage.</small>
                                    @else
                                        <small>Searches All, Waiting for Final BR and Finalized at once.</small>
                                    @endif
                                </span>
                            </div>
                            @include('partials.table-toolbar', ['toolbar' => [
                                'prefix' => 'br',
                                'searchName' => 'br_search',
                                'statusName' => 'transaction_type',
                                'pageName' => 'history_page',
                                'anchor' => 'processed-requests-heading',
                                'label' => 'Search all Final Budget Requests',
                                'searchPlaceholder' => 'Search BR number, PO, campaign, supplier, reference…',
                                'statusLabel' => 'Transaction type',
                                'statusPlaceholder' => 'All transaction types',
                                'statusOptions' => ['ONLINE' => 'Online Transaction', 'CASH' => 'Cash Transaction', 'CHECK' => 'Check Transaction'],
                            ]])
                        </div>
                    @endif
                    {{-- Canvassing and Purchase Orders have a single "Processed" view, so no tab strip. --}}
                    @if($processedTabCounts && ! ($creatorWorkflow && count($processedTabCounts) === 1))
                        <nav @class(['po-history-tabs', 'po-history-tabs--large' => $creatorWorkflow === 'budget-request']) aria-label="Processed request views">
                            @foreach(array_intersect_key([
                                'processed' => [$creatorWorkflow === 'budget-request' ? 'All' : 'Processed', $creatorWorkflow === 'budget-request' ? 'receipt' : 'check-circle'],
                                'finance' => ['Waiting for Final BR', 'hourglass'],
                                'finalized' => ['Finalized', 'check-circle'],
                                'mine' => ['Processed by me', 'user'],
                                'created' => ['Created', 'receipt'],
                                'approved' => ['Budget Approved', 'check-circle'],
                            ], $processedTabCounts) as $processedTabKey => [$processedTabLabel, $processedTabIcon])
                                <a class="po-history-tab po-history-tab--{{ $processedTabKey }} {{ $processedTab === $processedTabKey ? 'is-active' : '' }}" href="{{ route('dashboard', [...request()->except(['processed_tab', 'processed_page', 'processed_status', 'budget_register_tab', 'history_page', 'inventory_page', 'created_po_page', 'canvassing_page', 'po_history_page', 'po_history_tab']), 'processed_tab' => $processedTabKey]) }}#processed-requests-heading" @if($processedTab === $processedTabKey) aria-current="page" @endif>
                                    @if($creatorWorkflow === 'budget-request')
                                        <span class="po-history-tab-icon" aria-hidden="true"><x-ui.icon :name="$processedTabIcon" size="18" /></span>
                                        <span class="po-history-tab-text">
                                            <span class="po-history-tab-label">{{ $processedTabLabel }}</span>
                                            <span class="po-history-tab-hint">{{ match ($processedTabKey) { 'finance' => 'Tick to issue a group BR code', 'finalized' => 'BR code issued · ready for payment', default => 'Every prepared Budget Request' } }}</span>
                                        </span>
                                    @else
                                        <x-ui.icon :name="$processedTabIcon" size="15" />
                                        <span>{{ $processedTabLabel }}</span>
                                    @endif
                                    <span class="po-history-tab-count">{{ number_format($processedTabCounts[$processedTabKey] ?? 0) }}</span>
                                </a>
                            @endforeach
                            @if($processedTab === 'approved')<span class="po-history-readonly"><x-ui.icon name="eye" size="14" />View only</span>@endif
                        </nav>
                    @endif
                    @if($creatorWorkflow)
                        @include('dashboard.partials.procurement-records')
                    @elseif(in_array($processedTab, ['created', 'approved'], true))
                        @if($processedTab === 'created' && $createdPurchaseOrders)
                            <div class="px-3 pt-3 pb-2 border-top">
                                <h3 class="h6 mb-1">Created Purchase Orders</h3>
                                <p class="small text-muted mb-0">Purchase Orders appear here as soon as they are created, including those awaiting checking.</p>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 dashboard-table">
                                    <thead><tr><th class="table-row-number">#</th><th>PO Number</th><th>Request</th><th>Supplier</th><th>Status</th><th>Created by</th><th class="text-end">Amount</th><th class="text-end">Action</th></tr></thead>
                                    <tbody>
                                    @forelse($createdPurchaseOrders as $purchaseOrder)
                                        <tr>
                                            <td class="table-row-number">{{ $createdPurchaseOrders->firstItem() + $loop->index }}</td>
                                            <td class="fw-semibold">{{ $purchaseOrder->po_number }}</td>
                                            <td>@if($purchaseOrder->purchaseRequest)<a href="{{ route('purchase-requests.show', $purchaseOrder->purchaseRequest) }}" data-workflow-request-preview>{{ $purchaseOrder->purchaseRequest->request_number }}</a><div class="small text-muted">{{ $purchaseOrder->purchaseRequest->title }}</div>@else<span class="text-muted">Request unavailable</span>@endif</td>
                                            <td>{{ $purchaseOrder->supplier?->name ?? 'Not recorded' }}</td>
                                            <td><span class="badge text-bg-light border text-dark">{{ str($purchaseOrder->status)->replace('_', ' ')->title() }}</span></td>
                                            <td>{{ $purchaseOrder->creator?->name ?? 'Not recorded' }}<div class="small text-muted">{{ $purchaseOrder->created_at?->format('M j, Y g:i A') }}</div></td>
                                            <td class="text-end text-nowrap fw-semibold">&#8369; {{ number_format((float) $purchaseOrder->total_amount, 2) }}</td>
                                            <td class="text-end text-nowrap"><button class="btn btn-sm btn-outline-primary" type="button" data-po-preview-url="{{ route('purchase-orders.preview', $purchaseOrder) }}">View PO</button></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="8"><x-ui.empty-state icon="receipt" title="No Purchase Orders created" description="New Purchase Orders will appear here as soon as they are saved." /></td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                            @if($createdPurchaseOrders->hasPages())<div class="card-footer">{{ $createdPurchaseOrders->fragment('processed-requests-heading')->links() }}</div>@endif
                            <div class="px-3 pt-3 pb-2 border-top"><h3 class="h6 mb-0">Created Budget Requests</h3></div>
                        @endif
                        @include('budget-requests.partials.register', ['embedded' => true, 'filterUrl' => route('dashboard')])
                    @else
                    @include('partials.table-toolbar', ['toolbar' => [
                        'prefix' => 'processed',
                        'label' => 'Filter processed requests',
                        'anchor' => 'processed-requests-heading',
                        'searchPlaceholder' => 'Search request, title, requestor…',
                        'statusOptions' => ['pending' => 'In progress', 'approved' => 'Approved', 'disapproved' => 'Rejected / cancelled'],
                        'dateLabel' => 'Updated',
                    ]])
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 dashboard-table">
                            <thead><tr><th class="table-row-number">#</th><th>Request</th><th>Status</th><th class="text-end">Updated</th></tr></thead>
                            <tbody>
                            @forelse($processedRequests as $purchaseRequest)
                                <tr>
                                    <td class="table-row-number">{{ $processedRequests->firstItem() + $loop->index }}</td>
                                    <td><a class="fw-semibold text-decoration-none" href="{{ route('purchase-requests.show', $purchaseRequest) }}" data-workflow-request-preview>{{ $purchaseRequest->request_number }}</a><div class="small text-muted text-truncate request-title">{{ $purchaseRequest->title }}</div></td>
                                    <td><x-status-badge :status="$purchaseRequest->status" /></td>
                                    <td class="text-end small text-muted text-nowrap">{{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4"><x-ui.empty-state icon="check-circle" title="No processed requests found" description="Requests you complete will remain here as they move through the workflow. Adjust the search or filters if you expected results." /></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($processedRequests->hasPages())<div class="card-footer">{{ $processedRequests->fragment('processed-requests-heading')->links() }}</div>@endif
                    @endif
                </section>
                @if($creatorWorkflow === 'purchase-order' && $createdPurchaseOrders)
                    @include('purchase-orders.partials.preview-modal')
                @endif
            @endif

            @if($isPurchaseOrderApprover)
                @php($poHistoryIsMine = $purchaseOrderHistoryTab === 'mine')
                @php($poHistoryStepLabels = ['PO_PREPARED_BY' => 'Prepared', 'PO_CHECKED_BY' => 'Checked', 'PO_NOTED_BY' => 'Noted', 'PO_APPROVED_BY' => 'Approved'])
                <section class="card dashboard-panel dashboard-recent-panel" aria-labelledby="po-review-history-heading">
                    <div class="card-header">
                        <div>
                            <h2 id="po-review-history-heading">Purchase Order Review History</h2>
                            <p>{{ $poHistoryIsMine
                                ? 'Purchase Orders you completed that are waiting on the next stage. A PO moves to the next signer once they sign it.'
                                : ($user->hasRole(\App\Enums\RoleSlug::PurchasingTeam->value)
                                    ? 'View only: every Purchase Order at this review stage, signed or still pending. Select a PO to preview it.'
                                    : 'View only: live Purchase Orders assigned to you or completed by you at this review stage. Select a PO to preview it.') }}</p>
                        </div>
                    </div>
                    <nav class="po-history-tabs" aria-label="Purchase Order review history views">
                        @foreach([
                            'mine' => ['My reviews', 'user'],
                            'checked' => ['PO Checked', 'clipboard-check'],
                            'noted' => ['Noted', 'eye'],
                            'approved' => ['Approved', 'shield-check'],
                        ] as $poHistoryTabKey => [$poHistoryTabLabel, $poHistoryTabIcon])
                            <a class="po-history-tab {{ $purchaseOrderHistoryTab === $poHistoryTabKey ? 'is-active' : '' }}" href="{{ route('dashboard', [...request()->except(['po_history_tab', 'po_history_page', 'po_history_status']), ...($poHistoryTabKey === 'mine' ? [] : ['po_history_tab' => $poHistoryTabKey])]) }}#po-review-history-heading" @if($purchaseOrderHistoryTab === $poHistoryTabKey) aria-current="page" @endif>
                                <x-ui.icon :name="$poHistoryTabIcon" size="15" />
                                <span>{{ $poHistoryTabLabel }}</span>
                                <span class="po-history-tab-count" aria-label="{{ $purchaseOrderHistoryCounts[$poHistoryTabKey] ?? 0 }} Purchase Orders">{{ number_format($purchaseOrderHistoryCounts[$poHistoryTabKey] ?? 0) }}</span>
                            </a>
                        @endforeach
                        @unless($poHistoryIsMine)<span class="po-history-readonly"><x-ui.icon name="eye" size="14" />View only</span>@endunless
                    </nav>
                    @include('partials.table-toolbar', ['toolbar' => [
                        'prefix' => 'po_history',
                        'label' => 'Filter Purchase Order review history',
                        'anchor' => 'po-review-history-heading',
                        'searchPlaceholder' => 'Search PO number, request…',
                        'statusLabel' => 'Completed step',
                        'statusPlaceholder' => 'All steps',
                        'statusOptions' => $poHistoryIsMine ? $poHistoryStepLabels : [],
                        'dateLabel' => $poHistoryIsMine ? 'Processed' : 'Assigned / signed',
                    ]])
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 dashboard-table po-history-table">
                            <thead><tr><th class="table-row-number">#</th><th>Purchase Order</th><th>Request</th>@if($poHistoryIsMine)<th>Completed step</th><th class="text-end">Processed</th>@else<th>Assigned / signed by</th><th class="text-end">Assigned / signed</th>@endif</tr></thead>
                            <tbody>
                            @forelse($purchaseOrderReviewActions as $activity)
                                @php($reviewRequest = $activity->purchaseOrder?->purchaseRequest)
                                @php($reviewPreviewUrl = $activity->purchaseOrder ? route('purchase-orders.preview', $activity->purchaseOrder) : null)
                                <tr @if($reviewPreviewUrl) class="po-review-history-row" data-po-preview-row data-po-preview-url="{{ $reviewPreviewUrl }}" tabindex="0" role="button" aria-label="Preview Purchase Order {{ $activity->purchaseOrder->po_number }}" @endif>
                                    <td class="table-row-number">{{ $purchaseOrderReviewActions->firstItem() + $loop->index }}</td>
                                    <td class="fw-semibold">@if($reviewPreviewUrl)<button class="btn btn-link p-0 fw-semibold text-decoration-none" type="button" data-po-preview-url="{{ $reviewPreviewUrl }}">{{ $activity->purchaseOrder->po_number }}</button>@else PO not available @endif</td>
                                    <td>@if($reviewRequest)<div class="text-truncate request-title">{{ $reviewRequest->title }}</div>@else <span class="text-muted">Not available</span> @endif</td>
                                    @if($poHistoryIsMine)
                                        <td>{{ $poHistoryStepLabels[$activity->stage->value] }}</td>
                                        <td class="text-end small text-muted text-nowrap">{{ $activity->purchaseOrder?->approved_at?->format('M j, Y g:i A') ?? $activity->acted_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</td>
                                    @else
                                        @php($isPendingPoReview = ! in_array($activity->status, ['APPROVED', 'REJECTED'], true))
                                        <td><span class="po-history-signer"><span class="po-history-avatar" aria-hidden="true">{{ str($activity->signature_name ?: ($activity->actor?->name ?? $activity->assignedUser?->name ?? '?'))->substr(0, 1)->upper() }}</span>{{ $activity->signature_name ?: ($activity->actor?->name ?? $activity->assignedUser?->name ?? 'Not recorded') }}</span></td>
                                        <td class="text-end small text-muted text-nowrap">{{ $isPendingPoReview ? 'Pending signature' : ($activity->acted_at?->format('M j, Y g:i A') ?? 'Not recorded') }}</td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="5"><x-ui.empty-state icon="check-circle" title="No Purchase Orders found" :description="($poHistoryIsMine ? 'Completed Purchase Order checks, notations, or approvals will appear here.' : 'Purchase Orders you are part of will appear here once this step is signed.').' Adjust the search or filters if you expected results.'" /></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($purchaseOrderReviewActions->hasPages())<div class="card-footer">{{ $purchaseOrderReviewActions->fragment('po-review-history-heading')->links() }}</div>@endif
                </section>
                @include('purchase-orders.partials.preview-modal')
            @endif
            @if($isPaymentDashboard)
                <section class="card dashboard-panel dashboard-recent-panel" aria-labelledby="processed-payments-heading">
                    <div class="card-header">
                        <div>
                            <h2 id="processed-payments-heading">Processed payment</h2>
                            <p>Payments you recorded. Select a payment code to view its details.</p>
                        </div>
                    </div>
                    @include('partials.table-toolbar', ['toolbar' => [
                        'prefix' => 'payments',
                        'label' => 'Filter processed payments',
                        'anchor' => 'processed-payments-heading',
                        'searchPlaceholder' => 'Search payment code, reference, PO, request…',
                        'statusLabel' => 'Payment method',
                        'statusPlaceholder' => 'All payment methods',
                        'statusOptions' => collect(\App\Http\Controllers\DashboardController::PAYMENT_METHODS)->mapWithKeys(fn ($method) => [$method => str($method)->title()->toString()])->all(),
                        'dateLabel' => 'Payment date',
                    ]])
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 dashboard-table">
                            <thead><tr><th class="table-row-number">#</th><th>Payment code</th><th>Request / PO</th><th>Method</th><th class="text-end">Amount</th><th>Status</th><th class="text-end">Payment date</th></tr></thead>
                            <tbody>
                            @forelse($processedPayments as $payment)
                                @php($paymentDetails = $payment->viewDetails())
                                <tr>
                                    <td class="table-row-number">{{ $processedPayments->firstItem() + $loop->index }}</td>
                                    <td class="text-nowrap"><button class="btn btn-link p-0 fw-semibold text-decoration-none" type="button" data-payment-view="{{ json_encode($paymentDetails) }}" aria-label="View payment {{ $payment->payment_code }}">{{ $payment->payment_code }}</button></td>
                                    <td>
                                        @if($payment->purchaseRequest)
                                            <a class="fw-semibold text-decoration-none" href="{{ route('purchase-requests.show', $payment->purchaseRequest) }}" data-workflow-request-preview>{{ $payment->purchaseRequest->request_number }}</a>
                                        @else
                                            <span class="text-muted">Not linked</span>
                                        @endif
                                        <div class="small text-muted">{{ $payment->purchaseOrder?->po_number ?? 'No PO' }}</div>
                                    </td>
                                    <td>{{ $paymentDetails['method'] }}</td>
                                    <td class="text-end text-nowrap fw-semibold">{{ $paymentDetails['amount'] }}</td>
                                    <td><span class="badge {{ $paymentDetails['status'] === 'Paid' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $paymentDetails['status'] }}</span></td>
                                    <td class="text-end small text-muted text-nowrap">{{ $paymentDetails['paid_at'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7"><x-ui.empty-state icon="check-circle" title="No processed payments found" description="Payments you record will appear here. Adjust the search or filters if you expected results." /></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($processedPayments->hasPages())<div class="card-footer">{{ $processedPayments->fragment('processed-payments-heading')->links() }}</div>@endif
                </section>
                @include('payments.partials.view-modal')
            @endif
            @if(! $isPurchaseOrderApprover && ! $isWorkflowDashboard && ! $isBudgetMakerDashboard && ! $isPaymentDashboard)
            <section class="card dashboard-panel dashboard-recent-panel" aria-labelledby="recent-requests-heading">
                <div class="card-header">
                    <div>
                        <h2 id="recent-requests-heading">Recent requests</h2>
                        <p>Latest requests in your view</p>
                    </div>
                    <a class="dashboard-text-link" href="{{ route('purchase-requests.index') }}">View all <x-ui.icon name="chevron-right" size="16" /></a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 dashboard-table">
                        <thead><tr><th>Request</th><th>Requestor / Campaign</th><th>Status</th><th class="text-end">Updated</th></tr></thead>
                        <tbody>
                        @forelse($recentRequests as $purchaseRequest)
                            <tr>
                                <td><a class="fw-semibold text-decoration-none" href="{{ route('purchase-requests.show', $purchaseRequest) }}" data-workflow-request-preview>{{ $purchaseRequest->request_number }}</a><div class="small text-muted text-truncate request-title">{{ $purchaseRequest->title }}</div></td>
                                <td><div>{{ $purchaseRequest->requestor->name }}</div><div class="small text-muted">{{ $purchaseRequest->department_campaign }}</div></td>
                                <td><x-status-badge :status="$purchaseRequest->status" /></td>
                                    <td class="text-end small text-muted text-nowrap" title="{{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}">{{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-ui.empty-state icon="request" title="No recent requests" description="No requests are currently available to your account." /></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
            @endif
            @include('dashboard.partials.voided-monitoring')
            </div>
        </div>

        @can('viewAny', \App\Models\AuditLog::class)
            <section class="card dashboard-panel dashboard-activity-panel" aria-labelledby="recent-activity-heading">
                <div class="card-header"><div><h2 id="recent-activity-heading">System request activity</h2><p>Recent activity across visible requests</p></div></div>
                <div class="card-body">
                    @forelse($recentActivity as $activity)
                        <div class="activity-item d-flex gap-3 py-3">
                            <div class="activity-marker flex-shrink-0"></div>
                            <div class="min-w-0">
                                <div class="small fw-semibold text-dark">{{ str($activity->action)->replace('_', ' ')->title() }}</div>
                                <div class="small text-muted">{{ $activity->user?->name ?? 'System' }} @if($activity->auditable) on <a href="{{ route('purchase-requests.show', $activity->auditable) }}">{{ $activity->auditable->request_number }}</a>@endif</div>
                                @if($activity->previous_status || $activity->new_status)<div class="d-flex flex-wrap align-items-center gap-1 mt-2">@if($activity->previous_status)<x-status-badge :status="$activity->previous_status" />@endif @if($activity->previous_status && $activity->new_status)<span class="small text-muted">&rarr;</span>@endif @if($activity->new_status)<x-status-badge :status="$activity->new_status" />@endif</div>@endif
                                <div class="small text-muted mt-1" title="{{ $activity->acted_at?->format('M j, Y g:i A') ?? 'Not recorded' }}">{{ $activity->created_at->diffForHumans() }}</div>
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state icon="logs" title="No recent activity" description="No request activity has been recorded." />
                    @endforelse
                </div>
            </section>
        @endcan

    </div>
@endsection

