<?php

namespace App\Console\Commands;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\RoleSlug;
use App\Models\PoRoleAssignment;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use App\Support\PurchaseOrderActionQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;

class DiagnosePurchaseOrder extends Command
{
    protected $signature = 'po:diagnose {numbers* : Purchase Order numbers to inspect}';

    protected $description = 'Inspect PO checker routing and signature blockers without changing any records';

    public function handle(): int
    {
        $this->line('Application fingerprint: '.substr(hash('sha256', base_path()), 0, 12).'; PHP '.PHP_VERSION.' ('.PHP_SAPI.').');
        $assignments = PoRoleAssignment::query()->pluck('user_id', 'stage');
        $missing = false;

        foreach (array_unique($this->argument('numbers')) as $number) {
            $order = PurchaseOrder::query()->with(['approvals.assignedUser.role', 'approvals.assignedUser.roles', 'purchaseRequest'])
                ->where('po_number', $number)->first();

            if (! $order) {
                $this->error("{$number}: not found in this server's configured database.");
                $missing = true;

                continue;
            }

            $this->info("{$number}: {$order->status}");
            $this->table(['Stage', 'PO assignee ID', 'Current routing ID', 'Decision', 'Valid signature', 'Action confirmed'],
                collect(PurchaseOrderApprovalStage::cases())->map(function ($stage) use ($order, $assignments): array {
                    $approval = $order->approvalFor($stage);

                    return [
                        $stage->value,
                        $approval?->assigned_user_id ?? 'MISSING',
                        $assignments[$stage->value] ?? 'NOT CONFIGURED',
                        $approval?->status ?? 'MISSING',
                        $approval?->hasValidSignature() ? 'yes' : 'no',
                        $approval?->acted_at !== null && $approval?->signed_at !== null
                            && $approval->acted_at->gte($approval->signed_at) ? 'yes' : 'no',
                    ];
                })->all());

            $checker = $order->approvalFor(PurchaseOrderApprovalStage::CheckedBy)?->assignedUser;
            if (! $checker) {
                $this->warn('No checker account is assigned to this PO.');
            } else {
                $this->line("Assigned checker: {$checker->name} (ID {$checker->id}).");
                if (! $checker->is_active) {
                    $this->warn('The assigned checker account is inactive.');
                }
                if ((int) $order->created_by === (int) $checker->id) {
                    $this->line('The checker also prepared this PO; checking is allowed in their checker workspace.');
                }

                $rows = [];
                foreach ($checker->availableWorkspaceRoles() as $role) {
                    $actor = clone $checker;
                    $actor->useWorkspaceRole($role);
                    $queue = PurchaseOrderActionQueue::for($actor);
                    $rows[] = [$role->slug,
                        $actor->hasPoRole(PurchaseOrderApprovalStage::CheckedBy) ? 'yes' : 'no',
                        $queue?->whereKey($order->purchase_request_id)->exists() ? 'yes' : 'no',
                        Gate::forUser($actor)->allows('check', $order) ? 'yes' : 'no',
                        Gate::forUser($actor)->allows('sign', [$order, PurchaseOrderApprovalStage::CheckedBy]) ? 'yes' : 'no'];
                }
                $this->table(['Checker workspace', 'Checker role', 'In PO queue', 'Can check', 'Can sign'], $rows);
            }

            $prepared = $order->approvalFor(PurchaseOrderApprovalStage::PreparedBy);
            if (! $prepared?->hasValidSignature()) {
                $this->warn('Prepared By signature is missing or invalid; the checker queue requires it.');
            } elseif ($prepared->status !== 'APPROVED' || $prepared->acted_at === null || $prepared->acted_at->lt($prepared->signed_at)) {
                $this->warn('Prepared By handoff is incomplete; the preparer must confirm Move to Checker.');
            }

            $routing = $assignments[PurchaseOrderApprovalStage::CheckedBy->value] ?? null;
            if ($checker && $routing !== null && (int) $routing !== (int) $checker->id) {
                $this->warn('Current checker routing differs from this PO. Saving workflow assignments only affects new POs.');
            }

            $checkerRoleId = Role::query()->where('slug', RoleSlug::PoChecker->value)->value('id');
            if ($checkerRoleId !== null) {
                $others = User::query()->where('is_active', true)->withAssignedRole((int) $checkerRoleId)
                    ->when($checker, fn ($query) => $query->whereKeyNot($checker->id))->pluck('id');
                if ($others->isNotEmpty()) {
                    $this->line('Other PO Checker role account IDs: '.$others->implode(', ').'. Their role may allow access even if they are not the default assignee.');
                }
            }

            if ($order->status !== 'FOR_CHECKING') {
                $this->line('This PO is not currently at the checking stage.');
            }
        }

        $this->line('Read-only diagnosis complete. No assignments, signatures, or statuses were changed.');

        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
