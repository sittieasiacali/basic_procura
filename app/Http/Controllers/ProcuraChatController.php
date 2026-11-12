<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\Attachment;
use App\Models\ChatMessage;
use App\Models\PurchaseRequest;
use App\Services\ProcuraChat;
use App\Support\ProcuraChatAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcuraChatController extends Controller
{
    public function page(): View
    {
        return view('chat.index');
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'in:all,unread,mine,approval'], 'page' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();
        $query = ProcuraChatAccess::visibleQuery($user)
            ->with(['requestor:id,name,campaign_id', 'responsibleRole:id,name'])
            ->addSelect(['last_message_id' => ChatMessage::query()->select('id')
                ->whereColumn('purchase_request_id', 'purchase_requests.id')->latest('id')->limit(1)])
            ->withCount(['chatMessages as unread_count' => fn ($q) => $q
                ->where(fn ($other) => $other->where('user_id', '!=', $user->id)->orWhereNull('user_id'))
                ->where('chat_messages.id', '>', function ($read) use ($user) {
                    $read->selectRaw('COALESCE(MAX(last_message_id), 0)')->from('chat_reads')
                        ->whereColumn('purchase_request_id', 'purchase_requests.id')->where('user_id', $user->id);
                })])
            ->when(filled($data['search'] ?? null), fn ($q) => $q->where(fn ($search) => $search
                ->where('request_number', 'like', '%'.addcslashes($data['search'], '%_\\').'%')
                ->orWhere('title', 'like', '%'.addcslashes($data['search'], '%_\\').'%')))
            ->when(($data['filter'] ?? '') === 'mine', fn ($q) => $q->where('requestor_id', $user->id))
            ->when(($data['filter'] ?? '') === 'unread', fn ($q) => $q->whereHas('chatMessages', fn ($messages) => $messages
                ->where(fn ($other) => $other->where('user_id', '!=', $user->id)->orWhereNull('user_id'))
                ->where('chat_messages.id', '>', function ($read) use ($user) {
                    $read->selectRaw('COALESCE(MAX(last_message_id), 0)')->from('chat_reads')
                        ->whereColumn('purchase_request_id', 'purchase_requests.id')->where('user_id', $user->id);
                })))
            ->when(($data['filter'] ?? '') === 'approval', function ($q) use ($user) {
                if ($user->hasAnyRole([RoleSlug::Om->value, RoleSlug::OmSmtsApprover->value])) {
                    $q->where('status', PurchaseRequestStatus::ForOmApproval->value);
                } elseif ($user->hasRole(RoleSlug::President->value)) {
                    $q->where('status', PurchaseRequestStatus::ForSupplierApproval->value);
                } else {
                    $q->whereRaw('1 = 0');
                }
            });
        $page = $query->orderByDesc('last_message_id')->orderByDesc('updated_at')->paginate(30);
        $latest = ChatMessage::query()->whereIn('id', $page->getCollection()->pluck('last_message_id')->filter())->get()->keyBy('id');

        return response()->json([
            'data' => $page->getCollection()->map(fn ($item) => $this->summary($item) + [
                'unread_count' => $item->unread_count,
                'last_message' => $latest->get($item->last_message_id)?->body,
                'last_message_at' => $latest->get($item->last_message_id)?->created_at?->toIso8601String(),
            ]),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->checkView($request, $purchaseRequest);
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0'], 'before' => ['nullable', 'integer', 'min:1']]);
        $messages = $purchaseRequest->chatMessages()->with('user:id,name')
            ->when(isset($data['after']), fn ($q) => $q->where('id', '>', $data['after']))
            ->when(isset($data['before']), fn ($q) => $q->where('id', '<', $data['before']));
        // Initial page is the newest 80; incremental pages are ascending to avoid gaps.
        $items = isset($data['after']) ? $messages->orderBy('id')->limit(80)->get() : $messages->latest('id')->limit(80)->get()->reverse()->values();
        $latestWorkflowId = $purchaseRequest->chatMessages()->where('kind', 'workflow')->max('id');
        $canAcknowledge = $request->user()->hasRole(RoleSlug::Smt->value) && $latestWorkflowId !== null;
        $purchaseRequest->load(['requestor:id,name,campaign_id', 'responsibleRole:id,name', 'items.attachments', 'attachments', 'quotations.supplier', 'quotations.attachments']);

        return response()->json([
            'request' => $this->summary($purchaseRequest) + [
                'items' => $purchaseRequest->items->map(fn ($item) => $item->only(['id', 'description', 'quantity', 'unit', 'estimated_unit_price', 'estimated_total', 'brand_specifications', 'reference_url']) + ['files' => $item->attachments->map(fn ($file) => $this->fileData($request, $purchaseRequest, $file))]),
                'attachments' => $purchaseRequest->attachments->map(fn ($file) => $this->fileData($request, $purchaseRequest, $file)),
                'quotations' => $purchaseRequest->quotations->map(fn ($quote) => [
                    'id' => $quote->id, 'supplier' => $quote->supplier?->name, 'item' => $quote->item_or_service,
                    'price' => $quote->price, 'total' => $quote->vatBreakdown()['total'], 'delivery_terms' => $quote->delivery_terms,
                    'warranty' => $quote->warranty, 'remarks' => $quote->remarks, 'is_recommended' => $quote->is_recommended,
                    'files' => $quote->attachments->map(fn ($file) => $this->fileData($request, $purchaseRequest, $file)),
                ]),
            ],
            'permissions' => [
                'send' => ProcuraChatAccess::canSend($request->user(), $purchaseRequest),
                'approve_om' => $request->user()->can('reviewOm', $purchaseRequest),
                'approve_president' => $request->user()->can('reviewPresident', $purchaseRequest),
                'acknowledge' => $canAcknowledge,
            ],
            'workflow_message_id' => $latestWorkflowId,
            'acknowledged' => $canAcknowledge && DB::table('chat_acknowledgements')
                ->where('chat_message_id', $latestWorkflowId)->where('user_id', $request->user()->id)->exists(),
            'messages' => $items->map(fn ($message) => $this->messageData($request, $message)),
            'has_more' => $items->count() === 80,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, PurchaseRequest $purchaseRequest, ProcuraChat $chat): JsonResponse
    {
        $this->checkView($request, $purchaseRequest);
        $request->merge(['body' => trim((string) $request->input('body'))]);
        $data = $request->validate([
            'body' => ['nullable', 'required_without:attachment', 'string', 'max:5000'],
            'client_id' => ['required', 'uuid'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $message = DB::transaction(function () use ($request, $purchaseRequest, $data, $chat) {
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            abort_unless(ProcuraChatAccess::canSend($request->user(), $locked), 403, 'This conversation is read-only for your current role.');
            $existing = $locked->chatMessages()->where('user_id', $request->user()->id)->where('client_id', $data['client_id'])->first();
            if ($existing) {
                return $existing;
            }
            $file = $request->file('attachment');
            $path = $file?->store('procura-chat/'.$locked->id, 'local');
            try {
                $message = $locked->chatMessages()->create([
                    'user_id' => $request->user()->id, 'body' => $data['body'] ?: 'Attachment',
                    'client_id' => $data['client_id'], 'attachment_path' => $path,
                    'attachment_name' => $file ? mb_substr(basename($file->getClientOriginalName()), 0, 240) : null,
                    'attachment_mime' => $file?->getMimeType(), 'attachment_size' => $file?->getSize(),
                ]);
                $locked->touch();
                $chat->notify($message);

                return $message;
            } catch (\Throwable $error) {
                if ($path) {
                    Storage::disk('local')->delete($path);
                }
                throw $error;
            }
        });

        return response()->json($this->messageData($request, $message->load('user:id,name')), 201);
    }

    public function read(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->checkView($request, $purchaseRequest);
        $data = $request->validate(['message_id' => ['required', 'integer', 'min:1']]);
        abort_unless($purchaseRequest->chatMessages()->whereKey($data['message_id'])->exists(), 422);
        DB::transaction(function () use ($request, $purchaseRequest, $data) {
            $key = ['purchase_request_id' => $purchaseRequest->id, 'user_id' => $request->user()->id];
            DB::table('chat_reads')->insertOrIgnore($key + ['last_message_id' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('chat_reads')->where($key)->where('last_message_id', '<', $data['message_id'])
                ->update(['last_message_id' => $data['message_id'], 'updated_at' => now()]);
        });

        return response()->json(['read' => true]);
    }

    public function acknowledge(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->checkView($request, $purchaseRequest);
        abort_unless($request->user()->hasRole(RoleSlug::Smt->value), 403);
        $data = $request->validate(['message_id' => ['required', 'integer']]);
        DB::transaction(function () use ($request, $purchaseRequest, $data) {
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $latest = $locked->chatMessages()->where('kind', 'workflow')->latest('id')->first();
            if (! $latest || $latest->id !== (int) $data['message_id']) {
                throw ValidationException::withMessages(['message_id' => 'The workflow changed. Refresh and acknowledge the current update.']);
            }
            $inserted = DB::table('chat_acknowledgements')->insertOrIgnore([
                'purchase_request_id' => $locked->id, 'chat_message_id' => $latest->id,
                'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted) {
                $locked->chatMessages()->create(['user_id' => $request->user()->id, 'kind' => 'acknowledgement',
                    'body' => 'SMT acknowledged the workflow update.', 'metadata' => ['workflow_message_id' => $latest->id]]);
                $locked->auditLogs()->create(['user_id' => $request->user()->id, 'action' => 'SMT_ACKNOWLEDGED',
                    'remarks' => 'Optional acknowledgement; workflow unchanged.', 'metadata' => ['chat_message_id' => $latest->id]]);
            }
        });

        return response()->json(['acknowledged' => true]);
    }

    public function attachment(Request $request, ChatMessage $message): StreamedResponse
    {
        $this->checkView($request, $message->purchaseRequest);
        abort_unless($message->attachment_path && Storage::disk('local')->exists($message->attachment_path), 404);

        return Storage::disk('local')->download($message->attachment_path, $message->attachment_name,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function workflowAttachment(Request $request, PurchaseRequest $purchaseRequest, Attachment $attachment): StreamedResponse
    {
        $this->checkView($request, $purchaseRequest);
        $allowed = $purchaseRequest->attachments()->whereKey($attachment->id)->exists()
            || $purchaseRequest->items()->whereHas('attachments', fn ($q) => $q->whereKey($attachment->id))->exists()
            || $purchaseRequest->quotations()->whereHas('attachments', fn ($q) => $q->whereKey($attachment->id))->exists();
        abort_unless($allowed && Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function checkView(Request $request, PurchaseRequest $purchaseRequest): void
    {
        abort_unless(ProcuraChatAccess::canView($request->user(), $purchaseRequest), 403);
    }

    private function summary(PurchaseRequest $purchaseRequest): array
    {
        return ['id' => $purchaseRequest->id, 'request_number' => $purchaseRequest->request_number,
            'title' => $purchaseRequest->title, 'status' => $purchaseRequest->status->value,
            'requestor' => $purchaseRequest->requestor?->name, 'category' => $purchaseRequest->request_category,
            'team' => $purchaseRequest->requested_team, 'campaign' => $purchaseRequest->department_campaign,
            'responsible_role' => $purchaseRequest->responsibleRole?->name, 'total_amount' => $purchaseRequest->total_amount,
            'purpose' => $purchaseRequest->purpose, 'required_date' => $purchaseRequest->required_date?->toDateString(),
            'delivery_location' => $purchaseRequest->delivery_location];
    }

    private function messageData(Request $request, ChatMessage $message): array
    {
        return ['id' => $message->id, 'user_id' => $message->user_id, 'user_name' => $message->user?->name ?? 'PROCURA',
            'kind' => $message->kind, 'body' => $message->body, 'created_at' => $message->created_at->toIso8601String(),
            'attachment' => $message->attachment_path ? ['name' => $message->attachment_name, 'size' => $message->attachment_size,
                'url' => route($request->is('api/mobile/*') ? 'mobile.attachment' : 'chat.attachment', $message)] : null];
    }

    private function fileData(Request $request, PurchaseRequest $purchaseRequest, Attachment $file): array
    {
        return ['id' => $file->id, 'name' => $file->original_name, 'size' => $file->size,
            'url' => route($request->is('api/mobile/*') ? 'mobile.workflow-attachment' : 'chat.workflow-attachment', [$purchaseRequest, $file])];
    }
}
