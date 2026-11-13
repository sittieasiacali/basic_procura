<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationSoundSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotificationSoundController extends Controller
{
    public function edit(): View
    {
        return view('admin.notification-sound', [
            'tone' => NotificationSoundSetting::currentTone(),
            'tones' => NotificationSoundSetting::TONES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['tone' => ['required', Rule::in(array_keys(NotificationSoundSetting::TONES))]]);
        DB::transaction(function () use ($request, $validated) {
            $setting = NotificationSoundSetting::query()->lockForUpdate()->findOrFail(1);
            if ($setting->tone === $validated['tone']) {
                return;
            }
            $previous = $setting->tone;
            $setting->update(['tone' => $validated['tone'], 'updated_by' => $request->user()->id]);
            $setting->auditLogs()->create([
                'user_id' => $request->user()->id, 'action' => 'NOTIFICATION_RINGTONE_UPDATED',
                'remarks' => 'Changed the Procura notification ringtone.',
                'metadata' => ['previous_tone' => $previous, 'tone' => $setting->tone],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        });

        return redirect()->route('admin.notification-sound.edit')->with('success', 'Notification ringtone updated for everyone.');
    }
}
