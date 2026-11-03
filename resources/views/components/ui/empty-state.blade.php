@props([
    'title',
    'description' => null,
    'icon' => 'request',
])

<div {{ $attributes->class('empty-state text-center py-5 px-3') }}>
    <x-ui.icon class="mb-2" :name="$icon" size="24" />
    <div class="empty-state-title">{{ $title }}</div>
    @if($description)<div class="small mt-1">{{ $description }}</div>@endif
    @if(trim((string) $slot))<div class="mt-3">{{ $slot }}</div>@endif
</div>
