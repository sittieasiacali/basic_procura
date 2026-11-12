<section class="mobile-home-recent" aria-labelledby="mobile-recent-title">
    <div class="mobile-home-recent-heading"><h2 id="mobile-recent-title">Recent requests</h2><a href="{{ route('purchase-requests.index') }}">View all <x-ui.icon name="chevron-right" size="14" /></a></div>
    @forelse($recentRequests->take(3) as $recent)
        <a class="mobile-recent-row" href="{{ route('purchase-requests.show', $recent) }}" data-workflow-request-preview>
            <span class="mobile-menu-icon"><x-ui.icon name="request" size="21" /></span>
            <span class="mobile-recent-copy"><strong>{{ $recent->request_number }}</strong><small>{{ $recent->title }}</small></span>
            <x-request-list-status-badge :status="$recent->status" />
        </a>
    @empty
        <p class="mobile-recent-empty">No requests to show yet.</p>
    @endforelse
</section>
