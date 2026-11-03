<?php

namespace App\Http\Requests;

use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
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
            'required_date' => ['required', 'date'],
            'delivery_location' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:5000'],
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
            // Quotation, quantity and the approval email are needed to submit; a draft may leave them blank.
            'items.*.quantity' => ['required_if:action,submit', 'nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'items.*.unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'items.*.estimated_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'items.*.brand_specifications' => ['required_if:action,submit', 'nullable', 'string', 'max:5000'],
            'items.*.reference_url' => ['nullable', 'url', 'max:2048'],
            'items.*.attachment' => ['nullable', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(5 * 1024)],
            'items.*.picture' => ['nullable', File::types(['jpg', 'jpeg', 'png', 'webp'])->max(5 * 1024)],
        ];
    }

    /** An item already holding its approval email or picture may be submitted without uploading another. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('action') !== 'submit') {
                return;
            }

            $requiredFiles = [
                'attachment' => PurchaseRequestItem::ATTACHMENT_EMAIL_APPROVAL,
                'picture' => PurchaseRequestItem::ATTACHMENT_PICTURE,
            ];
            foreach ($requiredFiles as $field => $category) {
                $itemIdsWithFile = PurchaseRequestItem::query()
                    ->where('purchase_request_id', $this->route('purchaseRequest')->id)
                    ->whereHas('attachments', fn ($query) => $query->where('category', $category))
                    ->pluck('id')
                    ->all();

                foreach ((array) $this->input('items', []) as $index => $item) {
                    $hasStoredFile = isset($item['id']) && in_array((int) $item['id'], $itemIdsWithFile, true);
                    if (! $hasStoredFile && ! $this->hasFile("items.$index.$field")) {
                        $validator->errors()->add("items.$index.$field", PurchaseRequestItem::submissionMessages()["items.*.$field.required"]);
                    }
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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...PurchaseRequestItem::submissionMessages(),
            'items.min' => 'Add at least one item to the purchase request.',
            'department_campaign.in' => 'Use the campaign assigned to your account.',
        ];
    }
}
