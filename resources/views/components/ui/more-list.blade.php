{{--
    Shows the first item of a list; the rest appear in a popover on hover or keyboard focus.
    Each item: ['title' => string, 'note' => ?string].
--}}
@props([
    'items' => [],
    'label' => 'items',
    // When set, only a chip with this text shows; every item is in the popover.
    'chip' => null,
])

@php
    $items = collect($items)->values();
    $first = $items->first();
    $rest = $items->slice(1);
    $popover = $items->map(fn ($item) => '<div class="more-list-item"><strong>'.e($item['title']).'</strong>'
        .(filled($item['note'] ?? null) ? '<small>'.e($item['note']).'</small>' : '').'</div>')->join('');
@endphp

<div {{ $attributes->class('more-list') }}>
    @if($chip)
    @elseif($first)
        <div class="more-list-title">{{ $first['title'] }}</div>
        @if(filled($first['note'] ?? null))<div class="small text-muted">{{ $first['note'] }}</div>@endif
    @else
        <span class="text-muted">—</span>
    @endif
    @if($chip && $items->isNotEmpty())
        <button class="more-list-chip" type="button"
            data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="bottom" data-bs-html="true"
            data-bs-custom-class="more-list-popover"
            data-bs-title="{{ $items->count() }} {{ $label }}"
            data-bs-content="{{ $popover }}"
            aria-label="Show all {{ $items->count() }} {{ $label }}">{{ $chip }}</button>
    @elseif($rest->isNotEmpty())
        <button class="more-list-chip" type="button"
            data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="bottom" data-bs-html="true"
            data-bs-custom-class="more-list-popover"
            data-bs-title="{{ $items->count() }} {{ $label }}"
            data-bs-content="{{ $popover }}"
            aria-label="Show all {{ $items->count() }} {{ $label }}">+{{ $rest->count() }} more</button>
    @endif
</div>
