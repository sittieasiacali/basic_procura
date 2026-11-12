<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MobileDeviceController extends Controller
{
    public function store(): JsonResponse
    {
        return response()->json(['message' => 'Native app notifications have been retired. Use PROCURA Chat in your browser.'], 410);
    }
}
