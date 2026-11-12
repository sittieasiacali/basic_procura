<?php

namespace App\Http\Requests;

use App\Support\WorkflowSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class OmDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reviewOm', $this->route('purchaseRequest')) ?? false;
    }

    public function rules(): array
    {
        return ['remarks' => [$this->routeIs('*.return') ? 'required' : 'nullable', 'string', 'max:5000'],
            ...WorkflowSignature::rules()];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                try {
                    WorkflowSignature::assertValid($this, $this->only(['signature_name', 'signature_data']));
                } catch (ValidationException $error) {
                    foreach ($error->errors() as $key => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($key, $message);
                        }
                    }
                }
            }
        });
    }
}
