@props([
    'title',
    'eyebrow' => null,
    'description' => null,
    'icon' => 'file-text',
])

<section {{ $attributes->class('card procura-form-section') }}>
    <div class="card-header d-flex align-items-center gap-3">
        <span class="procura-section-icon"><x-ui.icon :name="$icon" size="20" /></span>
        <div>
            @if($eyebrow)<div class="section-kicker">{{ $eyebrow }}</div>@endif
            <h2 class="section-title mb-0">{{ $title }}</h2>
            @if($description)<p class="procura-section-description">{{ $description }}</p>@endif
        </div>
        @isset($actions)<div class="ms-auto">{{ $actions }}</div>@endisset
    </div>
    <div class="card-body">{{ $slot }}</div>
</section>