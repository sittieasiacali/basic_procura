@props([
    'title' => 'Review & Summary',
    'eyebrow' => 'Review',
    'icon' => 'clipboard-check',
])

<section {{ $attributes->class('card procura-form-summary') }}>
    <div class="card-header d-flex align-items-center gap-3">
        <span class="procura-section-icon"><x-ui.icon :name="$icon" size="20" /></span>
        <div><div class="section-kicker">{{ $eyebrow }}</div><h2 class="section-title mb-0">{{ $title }}</h2></div>
    </div>
    <div class="card-body">{{ $slot }}</div>
    @isset($actions)<div class="card-footer">{{ $actions }}</div>@endisset
</section>