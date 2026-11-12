<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAccountRoles;
use App\Support\AccountRoleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAccountRequest extends FormRequest
{
    use ValidatesAccountRoles;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('account')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $requiresRecipients = $this->roleRequiresActivityRecipients();

        return [
            'name' => ['required', 'string', 'max:255'],
            'employee_id' => [Rule::requiredIf(filled($this->route('account')?->employee_id)), 'nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', Rule::unique('users', 'employee_id')->ignore($this->route('account'))],
            'username' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', Rule::unique('users', 'username')->ignore($this->route('account'))],
            'team_id' => ['nullable', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('account')),
            ],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'om_notification_email' => [Rule::excludeIf(! $requiresRecipients), Rule::requiredIf($requiresRecipients), 'email', 'max:255'],
            'smt_notification_email' => [Rule::excludeIf(! $requiresRecipients), Rule::requiredIf($requiresRecipients), 'email', 'max:255'],
            'password_confirmation' => ['nullable', 'required_with:password', 'string'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->whereIn('slug', array_keys(AccountRoleCatalog::labels()))->orWhere('id', $this->route('account')?->role_id))],
            'role_ids' => ['required', 'array', 'min:1', 'max:20'],
            'role_ids.*' => ['required', 'integer', 'distinct', Rule::exists('roles', 'id')->where(fn ($query) => $query->whereIn('slug', array_keys(AccountRoleCatalog::labels()))->orWhereIn('id', $this->route('account')->assignedRoles()->pluck('id')))],
            'campaign_id' => [
                Rule::requiredIf($this->roleRequiresCampaign()),
                'nullable',
                'integer',
                Rule::exists('campaigns', 'id')->where(function ($query) {
                    $query->whereNull('deleted_at')->where(function ($query) {
                        $query->where('is_active', true)
                            ->orWhere('id', $this->route('account')?->campaign_id);
                    });
                }),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'The password confirmation does not match the new password.',
            'password_confirmation.required_with' => 'Please confirm the new password.',
            'om_notification_email.required' => 'Enter the assigned OM email for this Requestor / Team Leader account.',
            'smt_notification_email.required' => 'Enter the assigned SMT email for this Requestor / Team Leader account.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareRoleSelection();
        $employeeId = $this->input('employee_id', $this->route('account')?->employee_id);
        $username = $this->input('username', $this->route('account')?->username);
        $teamId = $this->input('team_id', $this->route('account')?->team_id);
        $this->merge([
            'employee_id' => is_string($employeeId) ? strtoupper(trim($employeeId)) : $employeeId,
            'username' => is_string($username) ? strtolower(trim($username)) : $username,
            'team_id' => is_string($teamId) ? trim($teamId) : $teamId,
            'email' => strtolower(trim((string) $this->input('email'))),
            'is_active' => $this->boolean('is_active'),
            'campaign_id' => $this->filled('campaign_id') ? $this->input('campaign_id') : null,
            'om_notification_email' => is_string($this->input('om_notification_email')) ? strtolower(trim($this->input('om_notification_email'))) : $this->input('om_notification_email'),
            'smt_notification_email' => is_string($this->input('smt_notification_email')) ? strtolower(trim($this->input('smt_notification_email'))) : $this->input('smt_notification_email'),
        ]);
    }
}
