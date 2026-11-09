<?php

namespace App\Console\Commands;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\RoleSlug;
use App\Models\PoRoleAssignment;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssignPurchaseOrderChecker extends Command
{
    protected $signature = 'po:assign-checker
        {numbers* : Exact PO numbers to transfer}
        {--user= : Active PO Checker account ID to receive the POs}
        {--from-user= : Expected previous checker account ID}
        {--update-routing : Also route newly created POs to this checker}
        {--apply : Apply the transfer; otherwise only preview it}';

    protected $description = 'Transfer only pending, unsigned PO checking steps and record an audit entry';

    public function handle(): int
    {
        foreach (['user', 'from-user'] as $option) {
            if (! ctype_digit((string) $this->option($option)) || (int) $this->option($option) < 1) {
                $this->error("Supply --{$option} with a positive account ID.");

                return self::FAILURE;
            }
        }

        try {
            $result = DB::transaction(function (): array {
                $targetId = (int) $this->option('user');
                $fromId = (int) $this->option('from-user');
                $target = User::query()->lockForUpdate()->find($targetId);
                if (! $target?->is_active || ! $target->hasAssignedRole(RoleSlug::PoChecker->value)) {
                    throw new InvalidArgumentException('The receiving account must be active and have the PO Checker role.');
                }

                $stage = PurchaseOrderApprovalStage::CheckedBy;
                $routing = PoRoleAssignment::query()->where('stage', $stage->value)->lockForUpdate()->first();
                $routingChanged = $this->option('update-routing') && (int) $routing?->user_id !== $targetId;
                if ($routingChanged && $routing && (int) $routing->user_id !== $fromId) {
                    throw new InvalidArgumentException('Current checker routing does not match --from-user. Run po:diagnose again.');
                }

                $numbers = array_unique($this->argument('numbers'));
                $orders = PurchaseOrder::query()->whereIn('po_number', $numbers)->orderBy('id')->lockForUpdate()->get();
                if ($orders->count() !== count($numbers)) {
                    throw new InvalidArgumentException('PO numbers not found: '.implode(', ', array_diff($numbers, $orders->pluck('po_number')->all())).'. No changes applied.');
                }

                $transfers = [];
                foreach ($orders as $order) {
                    $approval = $order->approvals()->where('stage', $stage->value)->lockForUpdate()->first();
                    if ($order->status !== 'FOR_CHECKING' || ! $order->purchaseRequest || ! $approval
                        || $approval->status !== 'PENDING' || $approval->decision !== null
                        || $approval->acted_by !== null || $approval->acted_at !== null || $approval->signed_at !== null
                        || filled($approval->signature_name) || filled($approval->signature_data)) {
                        throw new InvalidArgumentException("{$order->po_number}: only pending, unsigned checking steps on active requests can be transferred. No changes applied.");
                    }
                    if (! in_array((int) $approval->assigned_user_id, [$fromId, $targetId], true)) {
                        throw new InvalidArgumentException("{$order->po_number}: checker does not match --from-user. No changes applied.");
                    }
                    $transfers[] = [$order, $approval];
                }

                $rows = [];
                foreach ($transfers as [$order, $approval]) {
                    $previousId = (int) $approval->assigned_user_id;
                    $changed = $previousId !== $targetId;
                    $rows[] = [$order->po_number, $previousId, $targetId, $changed ? 'Transfer' : 'Already assigned'];
                    if (! $this->option('apply')) {
                        continue;
                    }
                    if ($changed) {
                        $approval->update(['assigned_user_id' => $targetId]);
                    }
                    if ($changed || $routingChanged) {
                        $order->purchaseRequest->auditLogs()->create([
                            'user_id' => null,
                            'action' => 'PURCHASE_ORDER_CHECKER_REASSIGNED',
                            'previous_status' => $order->status,
                            'new_status' => $order->status,
                            'remarks' => 'Pending PO checker assignment updated through po:assign-checker.',
                            'metadata' => [
                                'source' => 'artisan',
                                'purchase_order_id' => $order->id,
                                'stage' => $stage->value,
                                'from_user_id' => $previousId,
                                'to_user_id' => $targetId,
                                'routing_changed' => (bool) $routingChanged,
                                'previous_routing_user_id' => $routing?->user_id,
                            ],
                        ]);
                    }
                }

                if ($this->option('apply') && $routingChanged) {
                    PoRoleAssignment::query()->updateOrCreate(['stage' => $stage->value], ['user_id' => $targetId]);
                }

                return [$target->name, $rows];
            });
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        [$name, $rows] = $result;
        $this->info("Receiving checker: {$name} (ID {$this->option('user')}).");
        $this->table(['PO', 'Previous checker ID', 'Receiving checker ID', 'Result'], $rows);
        if ($this->option('update-routing')) {
            $this->line('Future PO checker routing: account '.$this->option('user').'.');
        }
        $this->info($this->option('apply') ? 'Transfer complete. Changes were audited; signatures and workflow statuses were preserved.' : 'Preview only. No records changed. Add --apply to perform this transfer.');

        return self::SUCCESS;
    }
}
