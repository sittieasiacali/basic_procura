@extends('layouts.app')
@section('title', 'Menu')
@section('content')
    @php
        $menuUser = auth()->user();
        $mobileNavigation = app(\App\Support\MobileNavigation::class)->forUser($menuUser);
    @endphp
    <div class="mobile-menu-page">
        <h1 class="mobile-menu-desktop-title">Menu</h1>
        @foreach($mobileNavigation['groups'] as $heading => $items)
            <section class="mobile-menu-group" aria-labelledby="mobile-menu-group-{{ $loop->index }}">
                <h2 id="mobile-menu-group-{{ $loop->index }}">{{ $heading }}</h2>
                @foreach($items as $item)
                    <a class="mobile-menu-link" href="{{ $item['url'] }}">
                        <span class="mobile-menu-icon" data-tone="{{ $item['tone'] }}"><x-ui.icon :name="$item['icon']" size="21" /></span>
                        <span>{{ $item['label'] }}</span>
                        <x-ui.icon class="mobile-menu-chevron" name="chevron-right" size="17" />
                    </a>
                @endforeach
            </section>
        @endforeach
        <section class="mobile-menu-group mobile-menu-account" aria-label="Your workspace">
            <h2>{{ $menuUser->name }}</h2>
            <p>{{ $menuUser->activeRole()?->name }} @if($menuUser->campaign) &middot; {{ $menuUser->campaign->name }} @endif</p>
            @include('partials.workspace-role-menu')
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="mobile-menu-signout" type="submit"><x-ui.icon name="logout" size="19" />Sign out</button>
            </form>
        </section>
    </div>
@endsection
