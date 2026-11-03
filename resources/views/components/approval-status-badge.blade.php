@props(['status'])

@php
    $class = match ($status) {
        'Approved' => 'status-success',
        'Disapproved' => 'status-danger',
        'Returned' => 'status-pending',
        default => 'status-draft',
    };
@endphp

<span {{ $attributes->class(['badge', $class]) }}>{{ $status }}</span>
