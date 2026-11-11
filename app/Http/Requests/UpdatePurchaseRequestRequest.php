<?php

namespace App\Http\Requests;

use App\Enums\RoleSlug;
use App\Models\Attachment;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Support\AttachmentFiles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('purchaseRequest')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $purchaseRequest = $this->route('purchaseRequest');
        $campaignRules = ['required', 'string', 'max:255'];
        if ($this->user()?->hasAnyRole([RoleSlug::Requestor->value, RoleSlug::TeamLeader->value])) {
            $campaignRules[] = Rule::in(array_filter([$this->user()->campaign?->name]));
        }

        return [
            'action' => ['required', Rule::in(['save_draft', 'submit'])],
            'purchase_mode' => ['nullable', Rule::in(['manual', 'bulk'])],
            // The form sends only a category and the title is taken from it (prepareForValidation).
            'title' => ['required_without:request_category', 'nullable', 'string', 'max:255'],
            'department_campaign' => $campaignRules,
            'request_type' => ['prohibited'],
            // The team decides which screening queue receives the request on submission.
            'requested_team' => ['required_with:request_category', 'nullable', Rule::in(array_keys(PurchaseRequest::REQUESTED_TEAMS))],
            'request_category' => [
                'required_without:title',
                'nullable',
                Rule::in(array_keys(PurchaseRequest::categoriesForTeam(is_string($this->input('requested_team')) ? $this->input('requested_team') : null))),
            ],
            'purpose' => ['required', 'string', 'max:5000'],
            'required_date' => ['nullable', 'date'],
            'delivery_location' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            // Preserve the priority assigned by IT / Premises during screening.
            'priority_level' => ['missing'],
            'signature_name' => ['required_if:action,submit', 'nullable', 'string', 'max:255'],
            'signature_data' => ['required_if:action,submit', 'nullable', 'string', 'max:500000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => [
                'nullable',
                Rule::exists('purchase_request_items', 'id')->where(fn ($query) => $query
                    ->where('purchase_request_id', $purchaseRequest->id)
                    ->whereNull('deleted_at')),
            ],
            'items.*.description' => ['required', 'string', 'max:2000'],
            // Quantity and email approval are needed to submit; quotation files and pictures are optional.
            'items.*.quantity' => ['required_if:action,submit', 'nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'items.*.unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'items.*.estimated_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'items.*.brand_specifications' => ['nullable', 'string', 'max:5000'],
            'items.*.reference_url' => ['nullable', 'url', 'max:2048'],
            'items.*.attachment' => ['nullable', new AttachmentFiles(['pdf', 'jpg', 'jpeg', 'png', 'webp', 'eml', 'msg'])],
            'items.*.picture' => ['nullable', new AttachmentFiles(['jpg', 'jpeg', 'png', 'webp'])],
            'items.*.quotation_file' => ['nullable', new AttachmentFiles(PurchaseRequestItem::quotationFileTypes())],
        ];
    }

    /**
     * An item already holding its approval email may be submitted without uploading another.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('action') !== 'submit') {
                return;
            }

            // One query for every stored file category on this request's items.
            $storedCategories = Attachment::query()
                ->where('attachable_type', PurchaseRequestItem::class)
                ->whereIn('attachable_id', PurchaseRequestItem::query()
                    ->where('purchase_request_id', $this->route('purchaseRequest')->id)
                    ->select('id'))
                ->get(['attachable_id', 'category'])
                ->groupBy('attachable_id')
                ->map(fn ($attachments) => $attachments->pluck('category')->all());
            $hasStored = fn (array $item, string $category): bool => isset($item['id'])
                && in_array($category, $storedCategories->get((int) $item['id'], []), true);

            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! $hasStored($item, PurchaseRequestItem::ATTACHMENT_EMAIL_APPROVAL) && ! $this->hasFile("items.$index.attachment")) {
                    $validator->errors()->add("items.$index.attachment", PurchaseRequestItem::submissionMessages()['items.*.attachment.required']);
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $team = is_string($this->input('requested_team')) ? $this->input('requested_team') : null;
        $category = is_string($this->input('request_category')) ? $this->input('request_category') : null;
        if (($title = PurchaseRequest::titleFor($team, $category)) !== null) {
            $this->merge(['title' => $title]);
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'requested_team' => 'screening team',
            'request_category' => 'request title category',
            'items.*.brand_specifications' => 'quotation',
            'items.*.attachment' => 'email approval',
            'items.*.picture' => 'item picture',
            'items.*.quotation_file' => 'quotation file',
            'priority_level' => 'priority',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...PurchaseRequestItem::submissionMessages(),
            'items.min' => 'Add at least one item to the purchase request.',
            'priority_level.missing' => 'Priority is assigned by IT / Premises during screening.',
            'department_campaign.in' => 'Use the campaign assigned to your account.',
        ];
    }
}
