<?php

use App\Http\Controllers\Api\MobileDeviceController;
use App\Http\Controllers\Api\MobileSessionController;
use App\Http\Controllers\OmApprovalController;
use App\Http\Controllers\PresidentApprovalController;
use App\Http\Controllers\ProcuraChatController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->name('mobile.')->group(function () {
    Route::post('login', [MobileSessionController::class, 'store'])->middleware('throttle:procura-mobile-login')->name('login');
    Route::middleware(['auth:sanctum', 'procura.mobile', 'throttle:180,1'])->group(function () {
        Route::get('me', [MobileSessionController::class, 'me'])->name('me');
        Route::post('role', [MobileSessionController::class, 'role'])->name('role');
        Route::post('password', [MobileSessionController::class, 'password'])->middleware('throttle:procura-mobile-password')->name('password');
        Route::post('logout', [MobileSessionController::class, 'destroy'])->name('logout');
        Route::post('devices', [MobileDeviceController::class, 'store'])->name('devices');
        Route::get('requests', [ProcuraChatController::class, 'index'])->name('requests.index');
        Route::get('requests/{purchaseRequest}', [ProcuraChatController::class, 'show'])->name('requests.show');
        Route::post('requests/{purchaseRequest}/messages', [ProcuraChatController::class, 'store'])->middleware('throttle:procura-chat-write')->name('requests.messages');
        Route::post('requests/{purchaseRequest}/read', [ProcuraChatController::class, 'read'])->name('requests.read');
        Route::post('requests/{purchaseRequest}/acknowledge', [ProcuraChatController::class, 'acknowledge'])->name('requests.acknowledge');
        Route::get('messages/{message}/attachment', [ProcuraChatController::class, 'attachment'])->name('attachment');
        Route::get('requests/{purchaseRequest}/files/{attachment}', [ProcuraChatController::class, 'workflowAttachment'])->name('workflow-attachment');
        Route::post('requests/{purchaseRequest}/om/approve', [OmApprovalController::class, 'approve'])->name('om.approve');
        Route::post('requests/{purchaseRequest}/om/return', [OmApprovalController::class, 'return'])->name('om.return');
        Route::post('requests/{purchaseRequest}/om/reject', [OmApprovalController::class, 'reject'])->name('om.reject');
        Route::post('requests/{purchaseRequest}/president', [PresidentApprovalController::class, 'supplierDecision'])->name('president.decision');
    });
});
