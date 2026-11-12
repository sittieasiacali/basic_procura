<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'push_token' => ['required', 'string', 'max:255', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_-]+\]$/'],
            'platform' => ['required', 'in:android,ios'],
        ]);
        DB::table('mobile_devices')->updateOrInsert(['push_token' => $data['push_token']], [
            'user_id' => $request->user()->id,
            'personal_access_token_id' => $request->user()->currentAccessToken()->id,
            'platform' => $data['platform'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['registered' => true]);
    }
}
