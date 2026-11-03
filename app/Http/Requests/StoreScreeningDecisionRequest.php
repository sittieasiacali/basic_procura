<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreScreeningDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('screen', $this->route('purchaseRequest')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $available = $this->input('decision') === 'AVAILABLE';

        return [
            'decision' => ['required', Rule::in(['AVAILABLE', 'UNAVAILABLE'])],
            // The screening decision is signed like every other workflow hand-off.
            'signature_name' => ['required', 'string', 'max:255'],
            'signature_data' => ['required', 'string', 'max:500000'],
            'evidence' => ['nullable', 'string', 'max:5000'],
            'quotation_evidence' => [
                'nullable',
                File::types(['pdf', 'jpg', 'jpeg', 'png', 'csv', 'xls', 'xlsx'])->max(10 * 1024),
            ],
            'premises_import' => ['nullable', File::types(['csv'])->max(5 * 1024)],
            'assets' => [
                Rule::requiredIf($available && ! $this->hasFile('premises_import')),
                'nullable',
                'array',
                'min:1',
                'max:100',
            ],
            'assets.*.asset_tag' => ['required', 'string', 'max:255'],
            'assets.*.item_code' => ['required', 'string', 'max:255'],
            'assets.*.description' => ['required', 'string', 'max:2000'],
            'assets.*.inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'assets.*.quantity' => [$available ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'assets.*.serial_number' => ['nullable', 'string', 'max:255'],
            'assets.*.location' => ['required', 'string', 'max:255'],
            'assets.*.source' => ['required', Rule::in(['IT_SELF_INVENTORY', 'PREMISES_EXCEL_IMPORT', 'MANUAL'])],
            'assets.*.release_date' => ['required', 'date'],
            'assets.*.recipient' => ['required', 'string', 'max:255'],
        ];
    }
}
