<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.required' => 'Enter your email address or username.'];
    }

    protected function prepareForValidation(): void
    {
        $identifier = $this->input('email');
        if (is_string($identifier)) {
            $this->merge(['email' => strtolower(trim($identifier))]);
        }
    }
}
