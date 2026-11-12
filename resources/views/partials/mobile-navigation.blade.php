@auth
    @unless($hideNavigation ?? false)
        @php($mobileNavigation = app(\App\Support\MobileNavigation::class)->forUser(auth()->user()))
        <nav class="mobile-bottom-navigation" aria-label="Mobile navigation">
            @foreach($mobileNavigation['tabs'] as $tab)
                <a href="{{ $tab['url'] }}" @if($tab['active']) aria-current="page" @endif>
                    <x-ui.icon :name="$tab['icon']" size="22" />
                    <span>{{ $tab['label'] }}</span>
                </a>
            @endforeach
        </nav>
    @endunless
@endauth
