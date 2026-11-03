<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use App\Enums\PurchaseRequestStatus;

class OmDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reviewOm', $this->route('purchaseRequest')) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $purchaseRequest = $this->route('purchaseRequest');
        $signatureRequired = $purchaseRequest
            ? $purchaseRequest->status === PurchaseRequestStatus::ForOmApproval
            : false;

        return [
            'remarks' => ['nullable', 'string', 'max:5000'],
            'signature_name' => $signatureRequired
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'],
            'signature_data' => $signatureRequired
                ? ['required', 'string', 'max:500000']
                : ['nullable', 'string', 'max:500000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'signature_name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('signature_name'))) ?? '',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $signedName = mb_strtolower((string) $this->input('signature_name'));
            $accountName = mb_strtolower(trim((string) $this->user()?->name));

            if ($signedName !== '' && $signedName !== $accountName) {
                $validator->errors()->add('signature_name', 'This signature does not belong to the signed-in account.');
            }

            $signature = (string) $this->input('signature_data');
            $prefix = 'data:image/png;base64,';
            $encoded = str_starts_with($signature, $prefix) ? substr($signature, strlen($prefix)) : false;
            $image = $encoded === false ? false : base64_decode($encoded, true);

            if ($image === false || strlen($image) < 64) {
                $validator->errors()->add('signature_data', 'Draw your signature before confirming this decision.');
            }
        });
    }
}