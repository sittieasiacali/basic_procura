<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\BudgetRequest;
use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\InventoryRelease;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\RequestScreening;
use App\Models\User;
use App\Notifications\AdminRecordDeleted;
use App\Support\AdminRecordCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class AdminRecordDeletion
{
    public function delete(Model $record, User $actor, string $reason): array
    {
        abort_unless($actor->isAdministrator(), 403);

        return DB::transaction(function () use ($record, $actor, $reason): array {
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            if ($record instanceof User && $record->is($actor)) {
                throw ValidationException::withMessages(['record' => 'You cannot delete your signed-in account.']);
            }

            $this->assertNoDependents($record);
            $requests = $this->relatedRequests($record);
            $label = AdminRecordCatalog::label($record);
            $recipients = [];
            foreach ($requests as $purchaseRequest) {
                $owner = $purchaseRequest->requestor;
                $email = strtolower(trim((string) $owner?->om_notification_email));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['record' => 'Assign a valid OM notification email to '.$purchaseRequest->request_number.'\'s requestor before deleting this record.']);
                }
                $recipients[] = [$email, [
                    'request_number' => $purchaseRequest->request_number,
                    'requestor' => $owner->name,
                    'record' => $label,
                    'actor' => $actor->name,
                    'reason' => $reason,
                    'deleted_at' => now()->toDateTimeString(),
                ]];
            }

            $snapshot = collect($record->getAttributes())->reject(fn ($value, $key) => preg_match('/password|token|secret|signature_data/i', $key))->all();
            $audit = new AuditLog;
            $audit->forceFill([
                'user_id' => $actor->id,
                'auditable_type' => $record->getMorphClass(),
                'auditable_id' => is_numeric($record->getKey()) ? $record->getKey() : null,
                'action' => 'ADMIN_RECORD_DELETED',
                'remarks' => $reason,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'metadata' => [
                    'record_type' => $record::class, 'record_id' => (string) $record->getKey(),
                    'record_label' => $label, 'snapshot' => $snapshot,
                    'request_ids' => $requests->modelKeys(),
                    'om_recipients' => array_values(array_unique(array_column($recipients, 0))),
                ],
            ])->save();
            $record->delete();
            foreach ($recipients as [$email, $details]) {
                Notification::route('mail', $email)->notify(new AdminRecordDeleted($details));
            }

            return ['label' => $label, 'email_count' => count($recipients)];
        });
    }

    private function assertNoDependents(Model $record): void
    {
        $softDelete = in_array(SoftDeletes::class, class_uses_recursive($record), true);
        $tables = collect(AdminRecordCatalog::models())->map(fn ($class) => (new $class)->getTable())->push('role_user')->unique();
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['foreign_table'] !== $record->getTable()) {
                    continue;
                }
                $query = DB::table($table);
                foreach ($foreignKey['columns'] as $index => $column) {
                    $query->where($column, $record->getAttribute($foreignKey['foreign_columns'][$index]));
                }
                if ($softDelete && Schema::hasColumn($table, 'deleted_at')) {
                    $query->whereNull('deleted_at');
                }
                if ($query->exists()) {
                    throw ValidationException::withMessages(['record' => 'This record is still referenced by '.str_replace('_', ' ', $table).'. Delete or reassign those linked records first.']);
                }
            }
        }

        if (! $record instanceof Attachment && Attachment::whereMorphedTo('attachable', $record)->exists()) {
            throw ValidationException::withMessages(['record' => 'Delete the linked attachments first.']);
        }
    }

    private function relatedRequests(Model $record, int $depth = 0): Collection
    {
        if ($depth > 5) {
            return (new PurchaseRequest)->newCollection();
        }
        if ($record instanceof PurchaseRequest) {
            return $record->newCollection([$record]);
        }
        if ($record instanceof Attachment) {
            return $record->attachable ? $this->relatedRequests($record->attachable, $depth + 1) : (new PurchaseRequest)->newCollection();
        }
        if ($record instanceof AuditLog && $record->auditable) {
            return $this->relatedRequests($record->auditable, $depth + 1);
        }
        $requests = (new PurchaseRequest)->newCollection();
        if ($id = $record->getAttribute('purchase_request_id')) {
            if ($purchaseRequest = PurchaseRequest::withTrashed()->find($id)) {
                $requests->push($purchaseRequest);
            }
        }
        if ($record instanceof BudgetRequest) {
            $requests = $requests->merge($record->purchaseRequests);
        }
        foreach ([
            'purchase_order_id' => PurchaseOrder::class, 'budget_request_id' => BudgetRequest::class,
            'goods_receipt_id' => GoodsReceipt::class, 'inventory_item_id' => InventoryItem::class,
            'inventory_release_id' => InventoryRelease::class, 'payment_id' => Payment::class,
            'request_screening_id' => RequestScreening::class,
        ] as $key => $class) {
            if (($id = $record->getAttribute($key)) && ($parent = $class::find($id))) {
                $requests = $requests->merge($this->relatedRequests($parent, $depth + 1));
            }
        }

        return $requests->unique('id')->values();
    }
}
