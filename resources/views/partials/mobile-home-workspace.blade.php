@php
    $mobileNavigation = app(\App\Support\MobileNavigation::class)->forUser($user);
    $mobileLinks = collect($mobileNavigation['groups'])->flatten(1)->keyBy('label');
    $mobileKpis = collect($kpis);
    $mobileActionValue = (int) ($actionCount ?? $mobileKpis->first()['value'] ?? 0);
    $mobileProcessedValue = (int) ($mobileKpis->first(fn ($item) => str_contains(strtolower($item['label']), 'processed'))['value'] ?? 0);
    $mobileApprovedValue = (int) ($mobileKpis->first(fn ($item) => str_contains(strtolower($item['label']), 'approved'))['value'] ?? 0);
    $mobileSections = [];
    $workLinks = collect(['Workflow Tracker', 'Return Monitoring'])->map(fn ($label) => $mobileLinks->get($label))->filter()->values();
    if ($workLinks->isNotEmpty()) $mobileSections['My Work'] = $workLinks;
    $procurementLinks = collect(['Canvassing', 'Purchase Orders', 'Budget Requests', 'Receiving', 'Payments'])->map(fn ($label) => $mobileLinks->get($label))->filter()->values();
    if ($procurementLinks->isNotEmpty()) $mobileSections['Procurement'] = $procurementLinks;
    $actionTab = collect($mobileNavigation['tabs'])->firstWhere('label', 'Actions') ?? collect($mobileNavigation['tabs'])->firstWhere('label', 'Approvals');
    $mobileSections['Requests Overview'] = collect([
        ['label' => 'Awaiting My Action', 'url' => $actionTab['url'] ?? route('purchase-requests.index', ['summary' => 'pending']), 'icon' => 'users', 'tone' => 'blue', 'count' => $mobileActionValue],
        ['label' => 'Processed', 'url' => route('dashboard', ['mobile_tab' => 'actions']).'#processed-requests-heading', 'icon' => 'check-circle', 'tone' => 'blue', 'count' => $mobileProcessedValue],
        ['label' => 'Approved', 'url' => route('purchase-requests.index', ['summary' => 'approved']), 'icon' => 'check', 'tone' => 'green', 'count' => $mobileApprovedValue],
    ]);
@endphp

<nav class="mobile-home-workspace" aria-label="Workspace shortcuts">
    @foreach($mobileSections as $section => $links)
        <section class="mobile-home-section">
            <h2>{{ $section }}</h2>
            @foreach($links as $link)
                <a href="{{ $link['url'] }}">
                    <span class="mobile-menu-icon" data-tone="{{ $link['tone'] ?? 'blue' }}"><x-ui.icon :name="$link['icon']" size="20" /></span>
                    <span>{{ $link['label'] }}</span>
                    @isset($link['count'])<b>{{ number_format($link['count']) }}</b>@endisset
                    <x-ui.icon class="mobile-home-chevron" name="chevron-right" size="18" />
                </a>
            @endforeach
        </section>
    @endforeach
</nav>
