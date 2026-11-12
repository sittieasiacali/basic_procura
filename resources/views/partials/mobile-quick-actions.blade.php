@php($mobileActions = app(\App\Support\MobileNavigation::class)->forUser(auth()->user())['quickActions'])
@if($mobileActions)
    <section class="mobile-quick-actions d-md-none" aria-labelledby="mobile-quick-actions-title">
        <h2 id="mobile-quick-actions-title">Quick actions</h2>
        <div class="mobile-quick-actions-grid" style="--mobile-action-columns: {{ min(4, count($mobileActions)) }}">
            @foreach(array_slice($mobileActions, 0, 4) as $action)
                <a href="{{ $action['url'] }}" data-tone="{{ $action['tone'] }}"><x-ui.icon :name="$action['icon']" size="24" /><span>{{ $action['label'] }}</span></a>
            @endforeach
        </div>
    </section>
@endif
