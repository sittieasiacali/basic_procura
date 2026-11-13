<?php

namespace App\Http\Controllers;

use App\Models\NotificationSoundSetting;
use App\Support\NotificationInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $unread = NotificationInbox::forUser($request->user())->whereNull('read_at');

        return response()->json([
            'unread_count' => (clone $unread)->count(),
            'ringtone' => NotificationSoundSetting::currentTone(),
            'recent_unread' => (clone $unread)->orderByDesc('created_at')->orderByDesc('id')->limit(20)
                ->get(['id', 'created_at', 'data'])->map(fn ($notification) => [
                    'id' => $notification->id,
                    'created_at' => $notification->created_at->toISOString(),
                    'title' => data_get($notification->data, 'title', 'Campaign update'),
                    'message' => data_get($notification->data, 'message', data_get($notification->data, 'body', 'A new update is available.')),
                    'url' => NotificationInbox::requestUrl($request->user(), $notification->data),
                ]),
        ])
            ->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => NotificationInbox::forUser($request->user())
                ->when($request->query('filter') === 'unread', fn ($query) => $query->whereNull('read_at'))
                ->latest()->paginate(25)->withQueryString(),
            'unreadCount' => NotificationInbox::forUser($request->user())->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        NotificationInbox::forUser($request->user())->findOrFail($notification)->markAsRead();

        return back()->with('success', 'Notification marked as read.');
    }

    public function readAll(Request $request): RedirectResponse
    {
        NotificationInbox::forUser($request->user())->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
