@props([
    'title',
    'description' => null,
    'eyebrow' => null,
])

<header {{ $attributes->class('page-header') }}>
    <div>
        @if($eyebrow)<div class="page-eyebrow">{{ $eyebrow }}</div>@endif
        <h1>{{ $title }}</h1>
        @if($description)<p>{{ $description }}</p>@endif
    </div>
    @isset($actions)
        <div class="page-actions d-flex flex-wrap gap-2">{{ $actions }}</div>
    @endisset
</header>
