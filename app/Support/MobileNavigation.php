<?php

namespace App\Support;

use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;

class MobileNavigation
{
    public function forUser(User $user): array
    {
        $purchasing = $user->hasRole('purchasing-team');
        $poRole = $user->hasAnyRole(['po-workflow', 'po-checker', 'po-noted', 'po-approver']) || $user->poRoleAssignments()->exists();
        $hasWorkQueue = $poRole || $user->hasAnyRole(['purchasing-team', 'finance', 'it-staff', 'premises-staff', 'budget-maker', 'inventory', 'inventory-staff']);
        $approver = $user->hasAnyRole(['om', 'tl', 'team-leader', 'om-smts-approver', 'president', 'budget-checker', 'budget-proceeder', 'po-workflow', 'po-checker', 'po-noted', 'po-approver', 'admin']);
        $approvalUrl = $user->hasRole('president')
            ? route('president-approvals.index')
            : route('dashboard', ['mobile_tab' => 'approvals']).'#my-actions-heading';
        $poUrl = $purchasing ? route('dashboard', ['workflow' => 'purchase-order'])
            : route('dashboard', ['mobile_tab' => 'pos']).'#po-review-history-heading';
        $link = fn (string $label, string $url, string $icon, string $tone = 'blue'): array => compact('label', 'url', 'icon', 'tone');
        $workflow = [$link('Requests', route('purchase-requests.index'), 'request')];
        if ($user->can('create', PurchaseRequest::class)) {
            $workflow[] = $link('New request', route('purchase-requests.create'), 'plus');
        }
        if ($approver) {
            $workflow[] = $link('Approvals', $approvalUrl, 'check', 'green');
        }
        if ($user->hasAnyRole(['it-staff', 'premises-staff'])) {
            $workflow[] = $link('Team screening', route('dashboard').'#my-actions-heading', 'users');
        }
        if ($purchasing) {
            $workflow[] = $link('Canvassing', route('dashboard', ['workflow' => 'canvassing']), 'supplier', 'purple');
        }
        if ($purchasing || $poRole) {
            $workflow[] = $link('Purchase Orders', $poUrl, 'receipt', 'orange');
        }
        if ($user->hasRole('finance')) {
            $workflow[] = $link('Receiving', route('dashboard', ['payment_tab' => 'receiving']), 'inventory', 'orange');
            $workflow[] = $link('Payments', route('dashboard'), 'payment');
        }
        $management = [];
        if ($user->hasAnyRole(['purchasing', 'purchasing-team', 'budget-maker', 'admin'])) {
            $management[] = $link('Budget Requests', route('budget.index'), 'wallet', 'green');
        }
        if ($user->can('viewAny', InventoryItem::class)) {
            $management[] = $link('Inventory', route('inventory.index'), 'inventory');
        }
        $management[] = $link('My items', route('inventory.my-items'), 'clipboard-check');
        if ($user->hasAnyRole(['it-staff', 'premises-staff', 'purchasing', 'purchasing-team', 'procurement-staff', 'canvassing-staff', 'admin']) && $user->can('viewAny', Supplier::class)) {
            $management[] = $link('Suppliers', route('suppliers.index'), 'supplier', 'purple');
        }
        $monitoring = [];
        if ($user->hasAnyRole(['purchasing-team', 'admin'])) {
            $monitoring = [
                $link('Workflow Tracker', route('workflow-tracker.index'), 'flow'),
                $link('Return Monitoring', route('returned-monitoring.index'), 'file-text', 'orange'),
                $link('Voided Monitoring', route('voided-monitoring.index'), 'x-circle', 'orange'),
            ];
        }
        $administration = [];
        if ($user->hasRole('admin')) {
            $administration = [
                $link('Users & roles', route('admin.accounts.index'), 'users'),
                $link('Campaigns', route('admin.campaigns.index'), 'campaign', 'green'),
                $link('Manage data', route('admin.data.index'), 'file-text'),
                $link('System health', route('admin.system-health'), 'info'),
            ];
        }
        $account = [
            $link('Conversations', route('chat.index'), 'message-square', 'purple'),
            $link('Notifications', route('notifications.index'), 'notification', 'orange'),
        ];
        $groups = array_filter(['Procurement workflow' => $workflow, 'Management' => $management, 'Monitoring' => $monitoring, 'Administration' => $administration, 'Account' => $account]);
        $isPo = request('workflow') === 'purchase-order' || request('mobile_tab') === 'pos';
        $isAction = request('mobile_tab') === 'actions';
        $isApproval = request()->routeIs('president-approvals.*') || request('mobile_tab') === 'approvals';
        $tabs = [
            $link('Home', route('dashboard'), 'dashboard') + ['active' => request()->routeIs('dashboard') && ! $isPo && ! $isApproval && ! $isAction],
            $link('Requests', route('purchase-requests.index'), 'request') + ['active' => request()->routeIs('purchase-requests.*')],
            ($approver ? $link('Approvals', $approvalUrl, 'check') : ($hasWorkQueue ? $link('Actions', route('dashboard', ['mobile_tab' => 'actions']).'#my-actions-heading', 'check') : $link('Updates', route('notifications.index'), 'notification'))) + ['active' => $approver ? $isApproval : ($hasWorkQueue ? $isAction : request()->routeIs('notifications.*'))],
            ($purchasing || $poRole ? $link('POs', $poUrl, 'receipt') : $link('Chats', route('chat.index'), 'message-square')) + ['active' => $purchasing || $poRole ? (request()->routeIs('dashboard') && $isPo) || request()->routeIs('purchase-orders.*') : request()->routeIs('chat.*')],
            $link('More', route('mobile.menu'), 'menu') + ['active' => request()->routeIs('mobile.menu')],
        ];
        $quickActions = array_values(array_filter(array_merge($workflow, $management, $account), fn ($item) => in_array($item['label'], ['New request', 'Canvassing', 'Purchase Orders', 'Budget Requests', 'Team screening', 'Approvals', 'Conversations', 'Inventory', 'Receiving', 'Payments'], true)));

        $quickActions = array_map(fn ($item) => $item['label'] === 'Conversations' ? array_replace($item, ['label' => 'Chats']) : $item, $quickActions);

        return compact('tabs', 'groups', 'quickActions');
    }
}
