<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use App\Services\GoodsReceiving;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceivingController extends Controller
{
    public function store(
        Request $request,
        PurchaseRequest $purchaseRequest,
        GoodsReceiving $receiving,
    ): RedirectResponse {
        $validated = $request->validate(GoodsReceiving::rules());

        DB::transaction(function () use ($request, $purchaseRequest, $receiving, $validated) {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('receiveGoods', $lockedRequest);

            $receiving->receive($request, $lockedRequest, $request->user(), $validated);
        });

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('success', 'Goods received and inventory updated. Request marked complete.');
    }
}
