<?php

namespace App\Http\Controllers;

use App\Models\InventoryClaimProof;
use App\Models\InventoryItem;
use App\Models\InventoryRelease;
use App\Support\WorkflowSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InventoryClaimController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('inventory.index', ['view' => 'released']);
    }

    public function store(Request $request, InventoryRelease $inventoryRelease): RedirectResponse
    {
        $canRecordClaim = (int) $inventoryRelease->recipient_user_id === (int) $request->user()->id
            || $request->user()->can('linkRecipient', [InventoryItem::class, $inventoryRelease]);
        abort_unless($inventoryRelease->recipient_user_id && $canRecordClaim, 403);

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
            ...WorkflowSignature::rules(),
            'photos' => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $storedPaths = [];
        try {
            DB::transaction(function () use ($request, $inventoryRelease, $validated, &$storedPaths): void {
                $release = InventoryRelease::query()->lockForUpdate()->findOrFail($inventoryRelease->id);
                abort_unless($release->recipient_user_id && (
                    (int) $release->recipient_user_id === (int) $request->user()->id
                    || $request->user()->can('linkRecipient', [InventoryItem::class, $release])
                ), 403);
                if ($release->claimed_at) {
                    throw ValidationException::withMessages(['photos' => 'This item has already been claimed.']);
                }
                WorkflowSignature::assertValidForSigner($validated, $release->recipient?->name ?? $release->released_to);

                foreach ($validated['photos'] as $photo) {
                    $path = $photo->store('inventory/claims/'.$release->id, 'local');
                    if (! $path) {
                        throw new \RuntimeException('The claim proof could not be saved.');
                    }
                    $storedPaths[] = $path;
                    $release->proofs()->create([
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => $photo->getClientOriginalName(),
                        'mime_type' => $photo->getMimeType(),
                    ]);
                }

                $release->update([
                    'claimed_by' => $request->user()->id,
                    'claimed_at' => now(),
                    'claim_remarks' => $validated['remarks'],
                    'claim_signature_name' => $validated['signature_name'],
                    'claim_signature_data' => $validated['signature_data'],
                ]);
                $release->inventoryItem->activities()->create([
                    'inventory_release_id' => $release->id,
                    'actor_id' => $request->user()->id,
                    'action' => 'CLAIMED',
                    'quantity' => $release->quantity,
                    'occurred_at' => $release->claimed_at,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }

        return redirect()->route('inventory.index', ['view' => 'claimed'])->with('success', 'Item claimed and proof photos saved.');
    }

    public function proof(Request $request, InventoryClaimProof $proof): StreamedResponse
    {
        $release = $proof->release;
        abort_unless(
            (int) $release->recipient_user_id === (int) $request->user()->id
                || ($request->user()->can('viewAny', InventoryItem::class)
                    && InventoryItem::query()->visibleToTeam($request->user())->whereKey($release->inventory_item_id)->exists()),
            403,
        );

        abort_unless(Storage::disk($proof->disk)->exists($proof->path), 404);

        return Storage::disk($proof->disk)->response($proof->path, $proof->original_name, [
            'Content-Type' => $proof->mime_type,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
