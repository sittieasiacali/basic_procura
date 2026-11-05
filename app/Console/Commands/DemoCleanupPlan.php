<?php

namespace App\Console\Commands;

use App\Models\BudgetRequest;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\RequestScreening;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoCleanupPlan extends Command
{
    protected $signature = 'demo:cleanup-plan
        {--dry-run : Explicitly request a read-only plan (the only supported mode)}
        {--database= : Expected configured database name}
        {--backup= : Path to an existing database backup}
        {--production-review : Allow read-only inspection in production after backup}
        {--output= : Write the review manifest to a new JSON file}';

    protected $description = 'Identify exact seeded demo candidates and related rows; never delete or update records';

    public function handle(): int
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        if ($this->option('database') !== $database) {
            $this->error('Supply --database with the exact configured database name. No records inspected.');

            return self::FAILURE;
        }

        $backup = $this->option('backup');
        if (($backup && (! is_file($backup) || filesize($backup) === 0))
            || (app()->environment('production') && (! $this->option('production-review') || ! $backup))) {
            $this->error('Production inspection requires --production-review and --backup pointing to a nonempty backup file.');

            return self::FAILURE;
        }
        if ($this->option('output') && file_exists($this->option('output'))) {
            $this->error('Choose a new output file; existing manifests are never overwritten.');

            return self::FAILURE;
        }

        $numbers = self::seededRequestNumbers();
        $requests = DB::table('purchase_requests')->whereIn('request_number', $numbers)
            ->get(['id', 'request_number', 'requestor_id', 'status', 'deleted_at']);
        $ids = $requests->pluck('id');
        $rows = [];
        $collect = function (string $table, string $column, $values) use (&$rows) {
            $found = DB::table($table)->whereIn($column, $values)->get();
            $rows[$table] = $found->pluck('id')->all();

            return $found;
        };

        $items = $collect('purchase_request_items', 'purchase_request_id', $ids);
        $collect('request_approvals', 'purchase_request_id', $ids);
        $screenings = $collect('request_screenings', 'purchase_request_id', $ids);
        $assets = $collect('screening_assets', 'request_screening_id', $screenings->pluck('id'));
        $collect('supplier_quotations', 'purchase_request_id', $ids);
        $orders = $collect('purchase_orders', 'purchase_request_id', $ids);
        $collect('purchase_order_items', 'purchase_order_id', $orders->pluck('id'));
        $collect('purchase_order_approvals', 'purchase_order_id', $orders->pluck('id'));
        $budgetLinks = $collect('budget_request_purchase_orders', 'purchase_request_id', $ids);
        $budgets = DB::table('budget_requests')->whereIn('purchase_request_id', $ids)
            ->orWhereIn('id', $budgetLinks->pluck('budget_request_id'))->get();
        $rows['budget_requests'] = $budgets->pluck('id')->all();
        $budgetLines = $collect('budget_request_lines', 'budget_request_id', $budgets->pluck('id'));
        $allBudgetLinks = DB::table('budget_request_purchase_orders')->whereIn('budget_request_id', $budgets->pluck('id'))->get();
        $payments = DB::table('payments')->whereIn('purchase_request_id', $ids)
            ->orWhereIn('budget_request_id', $budgets->pluck('id'))->get();
        $rows['payments'] = $payments->pluck('id')->all();
        $receipts = $collect('goods_receipts', 'purchase_request_id', $ids);
        $collect('goods_receipt_items', 'goods_receipt_id', $receipts->pluck('id'));
        $inventory = $collect('inventory_items', 'purchase_request_id', $ids);
        $collect('endorsements', 'purchase_request_id', $ids);
        $releases = $collect('inventory_releases', 'inventory_item_id', $inventory->pluck('id'));
        $collect('inventory_claim_proofs', 'inventory_release_id', $releases->pluck('id'));
        $collect('inventory_activities', 'inventory_item_id', $inventory->pluck('id'));

        $morphs = [
            PurchaseRequest::class => $ids,
            PurchaseRequestItem::class => $items->pluck('id'),
            RequestScreening::class => $screenings->pluck('id'),
            PurchaseOrder::class => $orders->pluck('id'),
            BudgetRequest::class => $budgets->pluck('id'),
            Payment::class => $payments->pluck('id'),
        ];
        foreach (['attachments' => 'attachable', 'audit_logs' => 'auditable'] as $table => $prefix) {
            $query = DB::table($table)->where(function ($query) use ($morphs, $prefix) {
                foreach ($morphs as $type => $keys) {
                    $type = (new $type)->getMorphClass();
                    $query->orWhere(fn ($q) => $q->where($prefix.'_type', $type)->whereIn($prefix.'_id', $keys));
                }
            });
            $rows[$table] = $query->pluck('id')->all();
        }

        // Exact seed identifiers are candidates, not proof of current ownership.
        // Never delete users, suppliers, campaigns, shared stock, or grouped BRs automatically.
        $seedSource = file_get_contents(database_path('seeders/SampleUserSeeder.php'));
        preg_match_all('/[a-z0-9-]+@example\.com/', $seedSource, $matches);
        $accounts = DB::table('users')->whereIn('email', array_unique($matches[0]))->get(['id', 'email', 'is_active']);
        $sharedBudgetIds = $allBudgetLinks->whereNotIn('purchase_request_id', $ids)->pluck('budget_request_id')
            ->merge($budgetLines->whereNotIn('purchase_order_id', $orders->pluck('id'))->pluck('budget_request_id'))
            ->merge($payments->whereNotIn('purchase_request_id', $ids)->pluck('budget_request_id'))->unique()->values();
        $manifest = [
            'mode' => 'read-only; no deletion implementation',
            'database' => $database,
            'backup_sha256' => $backup ? hash_file('sha256', $backup) : null,
            'backup_restore_verified' => false,
            'candidate_requests' => $requests,
            'candidate_accounts_preserve_pending_review' => $accounts,
            'related_row_ids_review_only' => $rows,
            'shared_budget_ids_do_not_delete' => $sharedBudgetIds,
            'external_inventory_ids_do_not_delete' => $assets->whereNotNull('inventory_item_id')
                ->whereNotIn('inventory_item_id', $inventory->pluck('id'))->pluck('inventory_item_id')->unique()->values(),
            'limitations' => 'Check actual foreign keys, grouped BR lines, polymorphic records, manually reused seed IDs, attachments and queued emails before any separately authorized deletion. Suppliers, campaigns, assets and account roles are preserved. No wildcard matching or guessed IDs.',
        ];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if ($this->option('output')) {
            $handle = fopen($this->option('output'), 'x');
            if ($handle === false) {
                $this->error('Cannot create manifest.');

                return self::FAILURE;
            }
            fwrite($handle, $json.PHP_EOL);
            fclose($handle);
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }

    public static function seededRequestNumbers(): array
    {
        $numbers = [];
        for ($i = 1; $i <= 15; $i++) {
            $numbers[] = sprintf('REQ-SAMPLE-%03d', $i);
        }
        foreach (['PO-001', 'PO-QUEUE-001', 'PO-CHECK-001', 'PO-ACK-001', 'PO-APP-001', 'PO-APPROVED-001'] as $suffix) {
            $numbers[] = 'REQ-SAMPLE-'.$suffix;
        }
        foreach (['OM', 'TEAM', 'IT', 'PREMISES', 'CANVASS', 'SUPPLIER', 'PO', 'MAKER', 'CHECKER', 'PROCEEDER', 'FINAL', 'PAYMENT', 'RECEIVING'] as $stage) {
            for ($i = 1; $i <= 5; $i++) {
                $numbers[] = sprintf('REQ-UAT-%s-%02d', $stage, $i);
            }
        }
        foreach (['CREATE', 'PREPARED', 'CHECKING', 'NOTATION', 'APPROVAL'] as $stage) {
            for ($i = 1; $i <= 5; $i++) {
                $numbers[] = sprintf('UAT-PO-%s-%02d', $stage, $i);
            }
        }
        for ($i = 1; $i <= 3; $i++) {
            $numbers[] = sprintf('REQ-BR-DEMO-%03d', $i);
        }

        return $numbers;
    }
}
