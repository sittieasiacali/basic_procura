<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAccountRoles;
use App\Models\User;
use App\Support\AccountCampaignCatalog;
use App\Support\AccountRoleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAccountRequest extends FormRequest
{
    use ValidatesAccountRoles;

    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $requiresRecipients = $this->roleRequiresActivityRecipients();

        return [
            'name' => ['required', 'string', 'max:255'],
            'employee_id' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', Rule::unique('users', 'employee_id')],
            'username' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', Rule::unique('users', 'username')],
            'team_id' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'om_notification_email' => [Rule::excludeIf(! $requiresRecipients), Rule::requiredIf($requiresRecipients), 'email', 'max:255'],
            'smt_notification_email' => [Rule::excludeIf(! $requiresRecipients), Rule::requiredIf($requiresRecipients), 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(8)],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereIn('slug', array_keys(AccountRoleCatalog::labels()))],
            'role_ids' => ['required', 'array', 'min:1', 'max:20'],
            'role_ids.*' => ['required', 'integer', 'distinct', Rule::exists('roles', 'id')->whereIn('slug', array_keys(AccountRoleCatalog::labels()))],
            'account_origin' => ['nullable', Rule::in(['dashboard', 'accounts'])],
            'campaign_id' => [
                Rule::requiredIf($this->roleRequiresCampaign()),
                'nullable',
                'integer',
                Rule::exists('campaigns', 'id')->where(fn ($query) => $query->where('is_active', true)
                    ->whereNull('deleted_at')->whereIn('name', AccountCampaignCatalog::NAMES)),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_id.regex' => 'Use letters, numbers, dots, hyphens, or underscores for the Employee ID.',
            'username.regex' => 'Use letters, numbers, dots, hyphens, or underscores for the username.',
            'om_notification_email.required' => 'Enter the assigned OM email for this Requestor / Team Leader account.',
            'smt_notification_email.required' => 'Enter the assigned SMT email for this Requestor / Team Leader account.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareRoleSelection();
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'employee_id' => is_string($this->input('employee_id')) ? strtoupper(trim($this->input('employee_id'))) : $this->input('employee_id'),
            'username' => is_string($this->input('username')) ? strtolower(trim($this->input('username'))) : $this->input('username'),
            'team_id' => is_string($this->input('team_id')) ? trim($this->input('team_id')) : $this->input('team_id'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
            'campaign_id' => $this->filled('campaign_id') ? $this->input('campaign_id') : null,
            'om_notification_email' => is_string($this->input('om_notification_email')) ? strtolower(trim($this->input('om_notification_email'))) : $this->input('om_notification_email'),
            'smt_notification_email' => is_string($this->input('smt_notification_email')) ? strtolower(trim($this->input('smt_notification_email'))) : $this->input('smt_notification_email'),
        ]);
    }
}
