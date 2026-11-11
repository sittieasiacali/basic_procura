<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $request->user()->unreadNotifications()->count()])
            ->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => $request->user()->notifications()
                ->when($request->query('filter') === 'unread', fn ($query) => $query->whereNull('read_at'))
                ->latest()->paginate(25)->withQueryString(),
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return back()->with('success', 'Notification marked as read.');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
