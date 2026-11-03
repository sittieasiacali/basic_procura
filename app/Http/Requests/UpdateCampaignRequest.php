<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('campaign')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('campaigns', 'name')->ignore($this->route('campaign')),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'account_number' => ['nullable', 'string', 'max:255', Rule::unique('campaigns', 'account_number')->ignore($this->route('campaign'))],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'account_number' => filled($this->input('account_number')) ? trim((string) $this->input('account_number')) : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
