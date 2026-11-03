@props(['fallback' => null])

<button
    type="button"
    class="app-page-back"
    data-app-page-back
    data-back-fallback="{{ $fallback ?: route('dashboard') }}"
>
    <span class="app-page-back-icon" aria-hidden="true">
        <x-ui.icon name="chevron-right" size="16" />
    </span>
    <span>Back</span>
</button>