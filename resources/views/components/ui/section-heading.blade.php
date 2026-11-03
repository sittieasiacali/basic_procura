@props(['icon' => 'file-text', 'eyebrow' => null, 'title', 'description' => null])

<div {{ $attributes->class('procura-section-heading') }}>
    <span class="procura-section-icon"><x-ui.icon :name="$icon" size="20" /></span>
    <div>
        @if($eyebrow)<div class="section-kicker">{{ $eyebrow }}</div>@endif
        <h2>{{ $title }}</h2>
        @if($description)<p>{{ $description }}</p>@endif
    </div>
</div>