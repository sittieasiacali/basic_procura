@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
<section class="notification-inbox" aria-labelledby="notification-inbox-heading">
    <header class="notification-inbox-header">
        <div>
            <h1 id="notification-inbox-heading">Notifications</h1>
            <p data-notification-unread>{{ $unreadCount }} unread</p>
        </div>
        <form method="POST" action="{{ route('notifications.read-all') }}">
            @csrf
            <button class="notification-read-all" type="submit" aria-label="Mark all notifications as read" title="Mark all as read" @disabled($unreadCount === 0)>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11v1a8 8 0 1 1-4.7-7.3M9 10l3 3 8-9"/></svg>
                <span>Mark all as read</span>
            </button>
        </form>
    </header>
    <nav class="notification-filters" aria-label="Filter notifications">
        <a href="{{ route('notifications.index') }}" @if(request('filter') !== 'unread') aria-current="page" @endif>All updates</a>
        <a href="{{ route('notifications.index', ['filter' => 'unread']) }}" @if(request('filter') === 'unread') aria-current="page" @endif>Unread <span>{{ $unreadCount }}</span></a>
    </nav>
    <div class="notification-feed">
        @forelse($notifications as $notification)
            @php
                $notificationUrl = \App\Support\NotificationInbox::requestUrl(auth()->user(), $notification->data);
                $notificationIsChat = \App\Support\ProcuraChatAccess::isCampaignRole(auth()->user()) || data_get($notification->data, 'chat_message_id');
            @endphp
            <article class="notification-row {{ $notification->read_at ? '' : 'notification-row--unread' }}">
                <div class="notification-avatar" aria-hidden="true">
                    @if(data_get($notification->data, 'chat_message_id'))
                        <svg viewBox="0 0 24 24"><path d="M5 4h14v12H9l-4 4V4Zm4 4h6m-6 4h4"/></svg>
                    @else
                        <svg viewBox="0 0 24 24"><path d="M7 3h7l4 4v14H7V3Zm7 0v5h4M10 12h5m-5 4h5"/></svg>
                    @endif
                </div>
                <div class="notification-row-content">
                    @unless($notification->read_at)<span class="visually-hidden">Unread notification:</span>@endunless
                    <h2>@if($notificationUrl)<a href="{{ $notificationUrl }}">{{ data_get($notification->data, 'title', 'Procurement update') }}</a>@else{{ data_get($notification->data, 'title', 'Procurement update') }}@endif</h2>
                    <p>{{ data_get($notification->data, 'message', data_get($notification->data, 'body', 'A new update is available.')) }}</p>
                    <div class="notification-row-meta">
                        <time datetime="{{ $notification->created_at->toIso8601String() }}" title="{{ $notification->created_at->format('M j, Y g:i A') }}">{{ $notification->created_at->diffForHumans() }}</time>
                        @if($notificationUrl)<a href="{{ $notificationUrl }}">{{ $notificationIsChat ? 'Open conversation' : 'View request' }} <span aria-hidden="true">&rsaquo;</span></a>@endif
                    </div>
                </div>
                @unless($notification->read_at)
                    <form class="notification-row-action" method="POST" action="{{ route('notifications.read', $notification->id) }}">
                        @csrf
                        <button type="submit" aria-label="Mark {{ data_get($notification->data, 'title', 'notification') }} as read" title="Mark as read"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg></button>
                    </form>
                @endunless
            </article>
        @empty
            <div class="notification-empty" role="status">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                <h2>{{ request('filter') === 'unread' ? "You're all caught up" : 'No notifications yet' }}</h2>
                <p>{{ request('filter') === 'unread' ? 'New unread updates will appear here.' : 'Your request and conversation updates will appear here.' }}</p>
            </div>
        @endforelse
    </div>
    @if($notifications->hasPages())<div class="notification-pagination">{{ $notifications->links() }}</div>@endif
    <details class="notification-preferences" data-campaign-alert-setup>
        <summary>Notification sound settings</summary>
<section class="notification-sound-settings" aria-labelledby="notification-sound-heading">
    <div class="card-body">
        <h2 class="h6" id="notification-sound-heading">Notification sound</h2>
        <div class="d-flex flex-wrap align-items-center gap-3">
            @unless(\App\Support\ProcuraChatAccess::isCampaignRole(auth()->user()))
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="notification-sound-enabled" data-notification-sound-toggle aria-describedby="notification-sound-status">
                <label class="form-check-label" for="notification-sound-enabled">Play a sound for new updates</label>
            </div>
            @endunless
            <p class="small mb-0">Ringtone: <strong data-notification-ringtone-label>{{ \App\Models\NotificationSoundSetting::TONES[\App\Models\NotificationSoundSetting::currentTone()] }}</strong> &middot; Set by Admin</p>
            @if(auth()->user()->isAdministrator())
                <a class="btn btn-outline-secondary" href="{{ route('admin.notification-sound.edit') }}">Choose ringtone for everyone</a>
            @endif
            @unless(\App\Support\ProcuraChatAccess::isCampaignRole(auth()->user()))
                <button class="btn btn-outline-primary" type="button" data-notification-sound-test>Test sound</button>
            @else
                <button class="btn btn-outline-primary" type="button" data-notification-sound-enable>Enable alerts</button>
            @endunless
        </div>
        <p class="small text-muted mt-3 mb-1" id="notification-sound-status" data-notification-sound-status role="status">Sound is off.</p>
        <p class="small text-muted mb-0">Saved for your account on this browser. Keep Procura open and tap the page after signing in to activate sound. Your device volume and silent settings apply.</p>
    </div>
</section>
    </details>
</section>
@endsection
