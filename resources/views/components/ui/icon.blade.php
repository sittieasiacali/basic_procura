@props(['name', 'size' => 18])

@php
    $paths = [
        'tag' => '<path d="M20 13 11 22 2 13V3h10l8 8z"/><circle cx="7" cy="8" r="1"/>',
        'map-pin' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'edit' => '<path d="M12 4H4v16h16v-8M10 14l1-5L19 1l4 4-8 8z"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4 20-7ZM11 13 22 2"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'save' => '<path d="M5 3h12l4 4v14H3V3z"/><path d="M7 3v6h10V3M7 21v-8h10v8"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'flow' => '<circle cx="6" cy="5" r="2"/><circle cx="18" cy="12" r="2"/><circle cx="6" cy="19" r="2"/><path d="M8 5h3a4 4 0 0 1 4 4v1M8 19h3a4 4 0 0 0 4-4v-1"/>',
        'request' => '<path d="M6 2h9l4 4v16H6z"/><path d="M14 2v5h5M9 12h6M9 16h6"/>',
        'file-text' => '<path d="M6 2h9l4 4v16H6z"/><path d="M14 2v5h5M9 12h6M9 16h6"/>',
        'hourglass' => '<path d="M6 3h12M6 21h12M8 3c0 4 4 5 4 9s-4 5-4 9M16 3c0 4-4 5-4 9s4 5 4 9"/>',
        'exclamation-circle' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
        'shield-check' => '<path d="M12 3 19 6v5c0 4.5-2.8 8.2-7 10-4.2-1.8-7-5.5-7-10V6z"/><path d="m9 12 2 2 4-4"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'cart' => '<path d="M3 4h2l2 11h10l2-8H6"/><circle cx="9" cy="20" r="1"/><circle cx="17" cy="20" r="1"/>',
        'receive' => '<path d="M4 8 12 3l8 5-8 5z"/><path d="M4 8v9l8 5 8-5V8M12 13v9"/>',
        'payment' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'budget' => '<path d="M4 22V8l8-5 8 5v14M8 22v-5h8v5M8 10h8"/>',
        'inventory' => '<path d="M4 7 12 3l8 4-8 4zM4 7v10l8 4 8-4V7M12 11v10"/>',
        'supplier' => '<path d="M3 21V8l6-4v17M9 10l6-3v14M15 12l6-2v11M6 10v1M6 14v1M12 12v1M18 14v1"/>',
        'report' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'notification' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'logs' => '<path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5"/>',
        'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M16 5a4 4 0 0 1 0 7M18 14a6 6 0 0 1 4 6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'paperclip' => '<path d="m21 11-8.5 8.5a6 6 0 0 1-8.5-8.5L14 1a4 4 0 0 1 5.7 5.7L9.5 17a2 2 0 0 1-2.8-2.8L16 5"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-2.1 3M6.6 6.6C3.5 8.5 2 12 2 12s3.5 7 10 7a10 10 0 0 0 4.1-.9"/>',
        'campaign' => '<path d="M4 21V5h10l1 3h5v9h-6l-1-3H4"/>',
        'logout' => '<path d="M10 5V3H4v18h6v-2M14 8l4 4-4 4M18 12H8"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
        'warning' => '<path d="M12 3 2.5 20h19zM12 9v4M12 17h.01"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'receipt' => '<path d="M5 3h14v18l-2.5-1.5L14 21l-2-1.5L10 21l-2.5-1.5L5 21z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'wallet' => '<path d="M4 7a3 3 0 0 1 3-3h11v16H7a3 3 0 0 1-3-3z"/><path d="M4 8h15a2 2 0 0 1 2 2v6h-5a2 2 0 0 1 0-4h5M16 14h.01"/>',
        'clipboard-check' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 13l2 2 4-4M9 17h6"/>',
        'message-square' => '<path d="M5 5h14v11H9l-4 3z"/><path d="M8 9h8M8 12h5"/>',
        'calendar' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'building' => '<path d="M4 21V5l8-3 8 3v16M8 8h1M15 8h1M8 12h1M15 12h1M8 16h1M15 16h1M10 21v-3h4v3"/>',
        'currency' => '<circle cx="12" cy="12" r="9"/><path d="M15 8h-4a2 2 0 0 0 0 4h2a2 2 0 0 1 0 4H9M12 6v12"/>',
    ];
    $path = $paths[$name] ?? $paths['request'];
@endphp

<svg {{ $attributes->class('flex-shrink-0')->merge(['width' => $size, 'height' => $size, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'aria-hidden' => 'true']) }}>
    {!! $path !!}
</svg>
