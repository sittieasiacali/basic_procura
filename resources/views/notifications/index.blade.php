@extends('layouts.app')
@section('content')
<h1 class="h3">Notifications <span class="badge text-bg-primary">{{ $unreadCount }} unread</span></h1>
<div class="d-flex gap-2 my-3"><a class="btn btn-outline-primary" href="{{ route('notifications.index') }}">All updates</a><a class="btn btn-outline-primary" href="{{ route('notifications.index', ['filter' => 'unread']) }}">Unread</a><form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-primary">Mark all as read</button></form></div>
@forelse($notifications as $notification)
<article class="card mb-2"><div class="card-body {{ $notification->read_at ? '' : 'border-start border-primary border-3' }}"><div class="d-flex justify-content-between"><strong>{{ data_get($notification->data, 'title', 'Procurement update') }}</strong><time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->format('M j, Y g:i A') }}</time></div><p>{{ data_get($notification->data, 'message', data_get($notification->data, 'body', 'A new update is available.')) }}</p>
@if(data_get($notification->data, 'purchase_request_id'))<a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', data_get($notification->data, 'purchase_request_id')) }}">View request</a>@endif
@unless($notification->read_at)<form class="d-inline" method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Mark as read</button></form>@endunless
</div></article>
@empty<p>No notifications found.</p>@endforelse
{{ $notifications->links() }}
@endsection
