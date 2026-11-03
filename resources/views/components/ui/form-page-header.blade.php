@props([
    'title',
    'description' => null,
    'eyebrow' => null,
    'icon' => 'file-text',
])

<header {{ $attributes->class('procura-form-header') }}>
    <span class="procura-form-header-icon"><x-ui.icon :name="$icon" size="27" /></span>
    <div class="procura-form-header-content">
        @if($eyebrow)<div class="section-kicker">{{ $eyebrow }}</div>@endif
        <h1>{{ $title }}</h1>
        @if($description)<p>{{ $description }}</p>@endif
    </div>
    @isset($actions)<div class="procura-form-header-actions">{{ $actions }}</div>@endisset
</header>