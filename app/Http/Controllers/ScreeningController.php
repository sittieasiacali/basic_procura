<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Http\Requests\StoreScreeningDecisionRequest;
use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\RequestScreening;
use App\Services\PurchaseRequestWorkflow;
use App\Support\WorkflowSignature;
use App\Support\AttachmentFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScreeningController extends Controller
{
    public function store(
        StoreScreeningDecisionRequest $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validated();
        WorkflowSignature::assertValid($request, $validated);
        $assets = $validated['decision'] === 'AVAILABLE' ? ($validated['assets'] ?? []) : [];

        if ($validated['decision'] === 'AVAILABLE' && $request->hasFile('premises_import')) {
            $assets = [...$assets, ...$this->parsePremisesCsv($request->file('premises_import'))];
        }

        if ($validated['decision'] === 'AVAILABLE') {
            Validator::make(['assets' => $assets], [
                'assets' => ['required', 'array', 'min:1', 'max:100'],
                'assets.*.asset_tag' => ['required', 'string', 'max:255'],
                'assets.*.item_code' => ['required', 'string', 'max:255'],
                'assets.*.description' => ['required', 'string', 'max:2000'],
                'assets.*.inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
                'assets.*.quantity' => ['required', 'numeric', 'gt:0'],
                'assets.*.serial_number' => ['nullable', 'string', 'max:255'],
                'assets.*.location' => ['required', 'string', 'max:255'],
                'assets.*.source' => ['required', Rule::in(['IT_SELF_INVENTORY', 'PREMISES_EXCEL_IMPORT', 'MANUAL'])],
                'assets.*.release_date' => ['required', 'date'],
                'assets.*.recipient' => ['required', 'string', 'max:255'],
            ])->validate();
        }

        DB::transaction(function () use ($request, $purchaseRequest, $workflow, $validated, $assets) {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);

            // IT is the default owner of requests already routed to IT. Record the
            // acting screener when the decision is submitted instead of requiring
            // a separate claim step.
            if ($lockedRequest->assigned_user_id === null
                && $lockedRequest->status === PurchaseRequestStatus::ForItScreening
                && $request->user()->hasRole(\App\Enums\RoleSlug::ItStaff->value)) {
                $lockedRequest->forceFill(['assigned_user_id' => $request->user()->id])->save();
            }

            $this->authorize('screen', $lockedRequest);

            $team = match ($lockedRequest->status) {
                PurchaseRequestStatus::ForItScreening => 'IT',
                PurchaseRequestStatus::ForPremisesScreening => 'PREMISES',
                default => throw ValidationException::withMessages(['status' => 'This request is no longer awaiting team screening.']),
            };

            if ($lockedRequest->screening()->exists()) {
                throw ValidationException::withMessages(['status' => 'A screening decision has already been recorded.']);
            }

            if ($request->hasFile('premises_import') && $team !== 'PREMISES') {
                throw ValidationException::withMessages(['premises_import' => 'Premises imports may only be used for Premises requests.']);
            }

            $allowedSources = $team === 'IT'
                ? ['IT_SELF_INVENTORY', 'MANUAL']
                : ['PREMISES_EXCEL_IMPORT', 'MANUAL'];

            foreach ($assets as $index => $asset) {
                if (! in_array($asset['source'], $allowedSources, true)) {
                    throw ValidationException::withMessages([
                        "assets.{$index}.source" => "The selected source is not valid for {$team} screening.",
                    ]);
                }
            }

            $requestedInventoryQuantities = collect($assets)
                ->filter(fn (array $asset): bool => ! empty($asset['inventory_item_id']))
                ->groupBy(fn (array $asset): int => (int) $asset['inventory_item_id'])
                ->map(fn ($inventoryAssets): float => (float) $inventoryAssets->sum(
                    fn (array $asset): float => (float) $asset['quantity'],
                ))
                ->sortKeys();
            $lockedInventoryItems = InventoryItem::query()
                ->whereKey($requestedInventoryQuantities->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($requestedInventoryQuantities as $inventoryId => $requestedQuantity) {
                $inventory = $lockedInventoryItems->get((int) $inventoryId);
                if (! $inventory
                    || ! str_starts_with((string) $inventory->sku, ($team === 'IT' ? 'IT-' : 'PREM-'))
                    || $inventory->status !== 'LOGGED'
                    || (float) $inventory->quantity < $requestedQuantity) {
                    throw ValidationException::withMessages([
                        'assets' => 'A selected inventory item is outside your team or does not have enough available stock.',
                    ]);
                }
            }

            $screening = $lockedRequest->screening()->create([
                'screened_by' => $request->user()->id,
                'team' => $team,
                'decision' => $validated['decision'],
                'evidence' => $validated['evidence'] ?? null,
                'decided_at' => now(),
            ]);

            if ($validated['decision'] === 'AVAILABLE') {
                foreach ($assets as $asset) {
                    $stock = ! empty($asset['inventory_item_id'])
                        ? $lockedInventoryItems->get((int) $asset['inventory_item_id'])
                        : InventoryItem::query()->create([
                            'inventory_code' => InventoryItem::nextCode(),
                            'asset_tag' => $asset['asset_tag'],
                            'logged_by' => $request->user()->id,
                            'description' => $asset['description'],
                            'quantity' => $asset['quantity'],
                            'unit' => 'unit',
                            'serial_number' => $asset['serial_number'] ?? null,
                            'location' => $asset['location'],
                            'status' => 'LOGGED',
                            'logged_at' => now(),
                        ]);
                    $asset = [...$asset,
                        'inventory_item_id' => $stock->id,
                        'asset_tag' => $stock->asset_tag ?: $stock->inventory_code,
                        'item_code' => $stock->sku,
                        'description' => $stock->description,
                        'serial_number' => $stock->serial_number,
                        'location' => $stock->location ?: 'Not recorded',
                        'release_date' => now()->toDateString(),
                    ];
                    $screening->assets()->create([
                        ...$asset,
                        'released_by' => $request->user()->id,
                    ]);
                    $release = $stock->releases()->create([
                        'released_by' => $request->user()->id,
                        'recipient_user_id' => $asset['recipient'] === $lockedRequest->requestor?->name ? $lockedRequest->requestor_id : null,
                        'quantity' => $asset['quantity'],
                        'released_to' => $asset['recipient'],
                        'remarks' => 'Automatically released for request '.$lockedRequest->request_number.'.',
                        'released_at' => now(),
                        'signature_name' => $validated['signature_name'],
                        'signature_data' => $validated['signature_data'],
                    ]);
                    $stock->activities()->create([
                        'inventory_release_id' => $release->id,
                        'actor_id' => $request->user()->id,
                        'action' => 'RELEASED',
                        'quantity' => $asset['quantity'],
                        'occurred_at' => $release->released_at,
                    ]);
                    $remaining = round((float) $stock->quantity - (float) $asset['quantity'], 2);
                    $stock->update(['quantity' => $remaining, 'status' => $remaining > 0 ? 'LOGGED' : 'RELEASED']);
                    $lockedRequest->endorsements()->create([
                        'inventory_item_id' => $stock->id,
                        'requestor_id' => $lockedRequest->requestor_id,
                        'endorsed_by' => $request->user()->id,
                        'endorsed_at' => now(),
                        'status' => 'PROVIDED',
                        'remarks' => $validated['evidence'] ?? 'Provided from available inventory.',
                    ]);
                }
            }

            foreach (['quotation_evidence' => 'screening_evidence', 'email_evidence' => 'screening_email_evidence'] as $field => $category) {
                foreach (AttachmentFiles::files($request->file($field)) as $file) {
                    $this->storeAttachment($screening, $file, $category, $request->user()->id);
                }
            }
            $this->storeAttachment($screening, $request->file('premises_import'), 'premises_excel_import', $request->user()->id);

            $available = $validated['decision'] === 'AVAILABLE';
            $workflow->transition(
                $lockedRequest,
                $available ? PurchaseRequestStatus::Fulfilled : PurchaseRequestStatus::ForProcurement,
                $request->user(),
                $available ? 'INVENTORY_AVAILABLE' : 'INVENTORY_UNAVAILABLE',
                $validated['evidence'] ?? ($available ? 'Available inventory assets recorded.' : null),
                'IT_PREMISES_SCREENING',
                [],
                $validated['signature_name'],
                $validated['signature_data'],
            );
            if ($available) {
                $workflow->recordActivity($lockedRequest, $request->user(), 'INVENTORY_RELEASED',
                    'Selected stock was released automatically when screening was signed.');
            }
        });

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => $validated['decision'] === 'AVAILABLE'
                    ? 'Available inventory released and request fulfilled.'
                    : 'Inventory marked unavailable. You can now add quotations.',
                'quotation_url' => $validated['decision'] === 'UNAVAILABLE'
                    ? route('purchase-requests.show', $purchaseRequest).'#canvassing-actions'
                    : null,
            ]);
        }

        // Unavailable stock opens quotation entry for the same screener. Keep
        // the dashboard iframe open instead of closing it and reloading its queues.
        if ($validated['decision'] === 'UNAVAILABLE') {
            return redirect()->to(route('purchase-requests.show', $purchaseRequest).'#canvassing-actions')
                ->with('success', 'Inventory marked unavailable. You can now add quotations.');
        }

        // Available stock completes screening and closes the action iframe.
        return ($request->boolean('_embedded') ? redirect()->away(\App\Support\WorkflowUrl::route('workflow.done')) : redirect()->route('purchase-requests.show', $purchaseRequest))
            ->with('success', $purchaseRequest->request_number.': screening decision recorded.');
    }

    public function undo(
        Request $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        $attachments = DB::transaction(function () use ($request, $purchaseRequest, $workflow): array {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('undoScreening', $lockedRequest);

            $screening = $lockedRequest->screening()->with('attachments')->firstOrFail();
            $attachmentPaths = [];
            foreach ($screening->attachments as $attachment) {
                $attachmentPaths[] = [$attachment->disk, $attachment->path];
                $attachment->delete();
            }
            $screening->delete();

            // Unavailable decisions release no stock, so only the decision itself is reverted.
            $workflow->transition(
                $lockedRequest,
                $lockedRequest->request_type === 'PREMISES'
                    ? PurchaseRequestStatus::ForPremisesScreening
                    : PurchaseRequestStatus::ForItScreening,
                $request->user(),
                'SCREENING_UNDONE',
                'Inventory unavailable decision undone; request returned to screening.',
                'IT_PREMISES_SCREENING',
            );

            return $attachmentPaths;
        });

        foreach ($attachments as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        return redirect()
            ->route('purchase-requests.show', $purchaseRequest)
            ->with('success', 'Screening decision undone. You can now record the inventory decision again.');
    }

    /** @return list<array<string, string|null>> */
    private function parsePremisesCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages(['premises_import' => 'The CSV file could not be read.']);
        }

        $header = fgetcsv($handle);
        $required = ['asset_tag', 'item_code', 'description', 'serial_number', 'location', 'release_date', 'recipient'];
        $normalized = array_map(
            fn ($value) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))),
            $header ?: [],
        );

        if (array_diff($required, $normalized)) {
            fclose($handle);
            throw ValidationException::withMessages([
                'premises_import' => 'CSV columns must include: '.implode(', ', $required).'.',
            ]);
        }

        $assets = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (! array_filter($row, fn ($value) => trim((string) $value) !== '')) {
                continue;
            }

            $values = array_slice(array_pad($row, count($normalized), null), 0, count($normalized));
            $record = array_combine($normalized, $values);
            $assets[] = [
                'asset_tag' => trim((string) ($record['asset_tag'] ?? '')),
                'item_code' => trim((string) ($record['item_code'] ?? '')),
                'description' => trim((string) ($record['description'] ?? '')),
                'quantity' => '1',
                'serial_number' => trim((string) ($record['serial_number'] ?? '')) ?: null,
                'location' => trim((string) ($record['location'] ?? '')),
                'source' => 'PREMISES_EXCEL_IMPORT',
                'release_date' => trim((string) ($record['release_date'] ?? '')),
                'recipient' => trim((string) ($record['recipient'] ?? '')),
            ];
        }
        fclose($handle);

        if ($assets === []) {
            throw ValidationException::withMessages(['premises_import' => 'The CSV file contains no asset rows.']);
        }

        return $assets;
    }

    private function storeAttachment(RequestScreening $screening, ?UploadedFile $file, string $category, int $userId): void
    {
        if (! $file) {
            return;
        }

        $path = $file->store("purchase-requests/{$screening->purchase_request_id}/screening", 'local');
        $screening->attachments()->create([
            'uploaded_by' => $userId,
            'category' => $category,
            'original_name' => $file->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }
}
