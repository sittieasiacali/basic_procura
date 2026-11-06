<?php

namespace App\Http\Requests;

use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PurchaseRequest::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
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
            'required_date' => ['required', 'date', 'after_or_equal:today'],
            'delivery_location' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            // The requestor rates how urgent the need is; screening may still adjust it.
            'priority_level' => ['required_if:action,submit', 'nullable', Rule::in(array_keys(PurchaseRequest::PRIORITY_LEVELS))],
            'signature_name' => ['required_if:action,submit', 'nullable', 'string', 'max:255'],
            'signature_data' => ['required_if:action,submit', 'nullable', 'string', 'max:500000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:2000'],
            // Quantity, a quotation (typed or as a file) and the approval email are needed to
            // submit; a draft may leave them blank. The item picture is optional.
            'items.*.quantity' => ['required_if:action,submit', 'nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'items.*.unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'items.*.estimated_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'items.*.brand_specifications' => ['nullable', 'string', 'max:5000'],
            'items.*.reference_url' => ['nullable', 'url', 'max:2048'],
            'items.*.attachment' => ['required_if:action,submit', 'nullable', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(5 * 1024)],
            'items.*.picture' => ['nullable', File::types(['jpg', 'jpeg', 'png', 'webp'])->max(5 * 1024)],
            'items.*.quotation_file' => ['nullable', File::types(PurchaseRequestItem::quotationFileTypes())->max(5 * 1024)],
        ];
    }

    /** A quotation may be typed, uploaded as a file, or both. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('action') !== 'submit') {
                return;
            }

            foreach ((array) $this->input('items', []) as $index => $item) {
                if (blank($item['brand_specifications'] ?? null) && ! $this->hasFile("items.$index.quotation_file")) {
                    $validator->errors()->add("items.$index.brand_specifications", PurchaseRequestItem::submissionMessages()['items.*.brand_specifications.required']);
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

        if ($this->user()?->hasAnyRole([RoleSlug::Requestor->value, RoleSlug::TeamLeader->value])) {
            $this->merge([
                'department_campaign' => $this->user()->campaign?->name,
            ]);
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'department_campaign' => 'department or campaign',
            'requested_team' => 'screening team',
            'request_category' => 'request title category',
            'items.*.description' => 'item description',
            'items.*.estimated_unit_price' => 'estimated unit price',
            'items.*.brand_specifications' => 'quotation',
            'items.*.reference_url' => 'link or reference',
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
            'department_campaign.in' => 'Use the campaign assigned to your account.',
            'department_campaign.required' => 'Your account must have an assigned Campaign before submitting a purchase request.',
        ];
    }
}
