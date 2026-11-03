<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryRelease;
use App\Models\InventoryActivity;
use App\Models\User;
use App\Support\WorkflowSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    public function index(Request $request): View
    {
        $canViewRegister = $request->user()->can('viewAny', InventoryItem::class);
        $ownReleases = InventoryRelease::query()->where('recipient_user_id', $request->user()->id);
        abort_unless($canViewRegister || in_array($request->query('view'), ['released', 'claimed'], true), 403);

        $filters = $request->validate([
            'view' => ['nullable', 'in:stock,added,released,claimed'],
            'search' => ['nullable', 'string', 'max:200'],
            'stock' => ['nullable', 'in:in_stock,released'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $view = $canViewRegister ? ($filters['view'] ?? 'stock') : (($filters['view'] ?? null) === 'claimed' ? 'claimed' : 'released');
        $teamItem = fn (Builder $query): Builder => $query->visibleToTeam($request->user());
        $teamRelease = fn (Builder $query): Builder => $canViewRegister
            ? $query->whereHas('inventoryItem', $teamItem)
            : $query->where('recipient_user_id', $request->user()->id);
        $itemsQuery = InventoryItem::query()->visibleToTeam($request->user())->registerFilters($filters);
        if ($view === 'stock') {
            $itemsQuery->reorder('logged_at')->orderBy('id');
        }
        $items = $canViewRegister && ! in_array($view, ['released', 'claimed'], true) ? $itemsQuery->paginate(15)->withQueryString() : null;
        $releases = $view === 'released'
            ? $teamRelease(InventoryRelease::query())
                ->with(['inventoryItem', 'recipient:id,name', 'releaser.role:id,name'])
                ->latest('released_at')->latest('id')->paginate(15)->withQueryString()
            : null;

        return view('inventory.index', [
            'items' => $items,
            'releases' => $releases,
            'claimedReleases' => $view === 'claimed'
                ? $teamRelease(InventoryRelease::query())->whereNotNull('claimed_at')
                    ->with(['inventoryItem', 'recipient:id,name', 'claimant.role:id,name', 'proofs'])
                    ->latest('claimed_at')->paginate(12)->withQueryString()
                : null,
            'view' => $view,
            'canViewRegister' => $canViewRegister,
            'recipients' => $canViewRegister
                ? User::query()->where('is_active', true)->with('role:id,name')->orderBy('name')->get(['id', 'name', 'role_id'])
                : collect(),
            'activities' => $canViewRegister
                ? InventoryActivity::query()->whereHas('item', $teamItem)->with(['item:id,sku,description,unit', 'actor.role:id,name'])
                    ->latest('occurred_at')->latest('id')->paginate(20, ['*'], 'activity_page')->withQueryString()
                : null,
            'summary' => [
                'items' => $canViewRegister ? InventoryItem::query()->visibleToTeam($request->user())->count() : 0,
                'in_stock' => $canViewRegister ? InventoryItem::query()->visibleToTeam($request->user())->where('quantity', '>', 0)->count() : 0,
                'released' => $canViewRegister ? InventoryItem::query()->visibleToTeam($request->user())->where('quantity', '<=', 0)->count() : 0,
                'release_entries' => $canViewRegister ? $teamRelease(InventoryRelease::query())->count() : (clone $ownReleases)->count(),
                'claimed_entries' => $canViewRegister
                    ? $teamRelease(InventoryRelease::query())->whereNotNull('claimed_at')->count()
                    : (clone $ownReleases)->whereNotNull('claimed_at')->count(),
            ],
        ]);
    }

    /** Manual stock entry by IT / Premises: items that arrived outside a purchase request. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', InventoryItem::class);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit' => ['required', 'string', 'max:50'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $item = DB::transaction(fn () => InventoryItem::query()->create([
            ...$validated,
            'inventory_code' => InventoryItem::nextCode(),
            'asset_tag' => $validated['serial_number'] ?? null,
            'logged_by' => $request->user()->id,
            'status' => 'LOGGED',
            'logged_at' => now(),
        ]));

        return redirect()->back(fallback: route('inventory.index'))
            ->with('success', "Inventory item {$item->inventory_code} added.");
    }

    /** Columns of the bulk stock import, in template order. */
    public const IMPORT_COLUMNS = ['description', 'quantity', 'unit', 'serial_number', 'location'];

    private const IMPORT_MAX_ROWS = 500;

    /** Blank CSV with the import columns and one example row. */
    public function importTemplate(): StreamedResponse
    {
        $this->authorize('create', InventoryItem::class);

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel keeps accents and the peso sign.
            fputcsv($out, self::IMPORT_COLUMNS);
            fputcsv($out, ['Wireless mouse', '10', 'pc', '', 'IT storage room']);
            fclose($out);
        }, 'inventory-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Bulk manual stock entry: every row is checked first, then all are added together or none. */
    public function import(Request $request): RedirectResponse
    {
        $this->authorize('create', InventoryItem::class);
        $request->validate(['inventory_csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $rows = $this->parseInventoryCsv($request->file('inventory_csv'));
        $errors = [];
        foreach ($rows as $line => $row) {
            $validator = Validator::make($row, [
                'description' => ['required', 'string', 'max:2000'],
                'quantity' => ['required', 'numeric', 'gt:0'],
                'unit' => ['required', 'string', 'max:50'],
                'serial_number' => ['nullable', 'string', 'max:255'],
                'location' => ['nullable', 'string', 'max:255'],
            ]);
            foreach ($validator->errors()->all() as $message) {
                $errors[] = "Row {$line}: {$message}";
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['inventory_csv' => array_slice($errors, 0, 20)]);
        }

        DB::transaction(function () use ($rows, $request): void {
            foreach ($rows as $row) {
                InventoryItem::query()->create([
                    ...$row,
                    'inventory_code' => InventoryItem::nextCode(),
                    'asset_tag' => $row['serial_number'],
                    'logged_by' => $request->user()->id,
                    'status' => 'LOGGED',
                    'logged_at' => now(),
                ]);
            }
        });

        $count = count($rows);

        return redirect()->route('inventory.index', ['view' => 'added'])
            ->with('success', "{$count} inventory ".str('item')->plural($count).' imported.');
    }

    /** @return array<int, array<string, ?string>> Rows keyed by their line number in the file. */
    private function parseInventoryCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['inventory_csv' => 'The CSV file could not be read.']);
        }

        $header = array_map(
            fn ($value) => str((string) $value)->replaceMatches('/^\xEF\xBB\xBF/', '')->trim()->lower()->replace(' ', '_')->toString(),
            fgetcsv($handle) ?: [],
        );
        $missing = array_diff(['description', 'quantity', 'unit'], $header);
        if ($missing !== []) {
            fclose($handle);
            throw ValidationException::withMessages(['inventory_csv' => 'The CSV is missing the column(s): '.implode(', ', $missing).'. Download the template to see the expected columns.']);
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if (! array_filter($values, fn ($value) => trim((string) $value) !== '')) {
                continue;
            }
            if (count($rows) >= self::IMPORT_MAX_ROWS) {
                fclose($handle);
                throw ValidationException::withMessages(['inventory_csv' => 'A CSV can add at most '.self::IMPORT_MAX_ROWS.' items. Split the file and import it in parts.']);
            }
            $record = array_combine($header, array_slice(array_pad($values, count($header), null), 0, count($header)));
            $rows[$line] = collect(self::IMPORT_COLUMNS)
                ->mapWithKeys(fn (string $column) => [$column => filled($record[$column] ?? null) ? trim((string) $record[$column]) : null])
                ->all();
        }
        fclose($handle);

        if ($rows === []) {
            throw ValidationException::withMessages(['inventory_csv' => 'The CSV contains no item rows.']);
        }

        return $rows;
    }

    /** Issues stock out of inventory. Finance can never reach this (see InventoryItemPolicy). */
    public function release(Request $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $this->authorize('release', $inventoryItem);
        $validated = $request->validate([
            ...WorkflowSignature::rules(),
            'quantity' => ['required', 'numeric', 'gt:0'],
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        WorkflowSignature::assertValid($request, $validated);
        $recipient = User::query()->where('is_active', true)->find($validated['recipient_user_id']);
        if (! $recipient) {
            throw ValidationException::withMessages(['recipient_user_id' => 'Select an active recipient account.']);
        }

        DB::transaction(function () use ($request, $inventoryItem, $validated, $recipient): void {
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($inventoryItem->id);
            $this->authorize('release', $item);

            if ((float) $validated['quantity'] > (float) $item->quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Only '.rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.').' '.$item->unit.' is available to release.',
                ]);
            }

            $release = $item->releases()->create([
                'released_by' => $request->user()->id,
                'quantity' => (float) $validated['quantity'],
                'recipient_user_id' => $recipient->id,
                'released_to' => $recipient->name,
                'remarks' => $validated['remarks'] ?? null,
                'released_at' => now(),
                'signature_name' => $validated['signature_name'],
                'signature_data' => $validated['signature_data'],
            ]);

            $item->activities()->create([
                'inventory_release_id' => $release->id,
                'actor_id' => $request->user()->id,
                'action' => 'RELEASED',
                'quantity' => $validated['quantity'],
                'occurred_at' => $release->released_at,
            ]);

            $remaining = round((float) $item->quantity - (float) $validated['quantity'], 2);
            $item->forceFill([
                'quantity' => $remaining,
                'status' => $remaining <= 0 ? 'RELEASED' : $item->status,
            ])->save();
        });

        return redirect()->back(fallback: route('inventory.index'))
            ->with('success', "Released {$validated['quantity']} from {$inventoryItem->inventory_code}.");
    }

    /** Connect a legacy release to the recipient's account so it can be claimed. */
    public function linkRecipient(Request $request, InventoryRelease $inventoryRelease): RedirectResponse
    {
        $this->authorize('linkRecipient', [InventoryItem::class, $inventoryRelease]);
        $validated = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $recipient = User::query()->where('is_active', true)->find($validated['recipient_user_id']);
        if (! $recipient) {
            throw ValidationException::withMessages(['recipient_user_id' => 'Select an active recipient account.']);
        }

        DB::transaction(function () use ($request, $inventoryRelease, $recipient): void {
            $release = InventoryRelease::query()->lockForUpdate()->findOrFail($inventoryRelease->id);
            if ($release->recipient_user_id || $release->claimed_at) {
                throw ValidationException::withMessages(['recipient_user_id' => 'This release already has a recipient account.']);
            }
            $originalRecipient = $release->released_to;
            $release->update([
                'recipient_user_id' => $recipient->id,
                'released_to' => $recipient->name,
                'remarks' => trim(($release->remarks ? $release->remarks."\n" : '').'Original recipient label: '.$originalRecipient),
            ]);
            $release->inventoryItem->activities()->create([
                'inventory_release_id' => $release->id,
                'actor_id' => $request->user()->id,
                'action' => 'RECIPIENT_LINKED',
                'occurred_at' => now(),
            ]);
        });

        return redirect()->back(fallback: route('inventory.index', ['view' => 'released']))
            ->with('success', 'Recipient account linked. The item can now be claimed.');
    }
}
