@php
    $editing = isset($account);
    $campaignRoleIds = $roles->whereIn('slug', [\App\Enums\RoleSlug::Requestor->value, \App\Enums\RoleSlug::TeamLeader->value])->pluck('id')->values();
    $managementRoleIds = $roles->whereIn('slug', ['om', 'om-smts-approver', 'smt'])->pluck('id')->values();
    $selectedRoles = old('role_ids', old('role_selection') ? [] : (isset($account) ? $account->assignedRoles()->pluck('id')->all() : []));
    $requiresActivityRecipients = $campaignRoleIds->intersect((array) $selectedRoles)->isNotEmpty();
    $passwordError = $errors->first('password');
    $confirmationMismatch = $passwordError && str_contains(strtolower($passwordError), 'confirmation');
@endphp

<x-ui.form-section eyebrow="Identity and access" title="Account Information" icon="users" class="mb-3">
    <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="employee_id">Employee ID</label>
                <input class="form-control @error('employee_id') is-invalid @enderror" id="employee_id" name="employee_id" value="{{ old('employee_id', $account->employee_id ?? '') }}" maxlength="100" @required(filled($account->employee_id ?? null))>
                @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label" for="username">Username (optional)</label>
                <input class="form-control @error('username') is-invalid @enderror" id="username" name="username" value="{{ old('username', $account->username ?? '') }}" maxlength="100">
                @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label" for="team_id">Team ID (optional)</label>
                <input class="form-control @error('team_id') is-invalid @enderror" id="team_id" name="team_id" value="{{ old('team_id', $account->team_id ?? '') }}" maxlength="100">
                @error('team_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="name">Full name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $account->name ?? '') }}" maxlength="255" required autofocus>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email">Account email (sign-in)</label>
                <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email', $account->email ?? '') }}" maxlength="255" required autocomplete="off">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Workflow emails go to the TL's assigned OM and SMT recipients.</div>
            </div>
            <div class="col-12">
                <x-account-role-checkboxes :options="$roles->map(fn ($role) => ['id' => $role->id, 'label' => \App\Support\AccountRoleCatalog::label($role)])" :selected="$selectedRoles" prefix="edit-account" />
            </div>
            <div class="col-md-6">
                <label class="form-label" for="campaign_id">Campaign <span class="text-muted" id="campaign-requirement">(required for TL / Requestor, OM and SMT)</span></label>
                <select class="form-select @error('campaign_id') is-invalid @enderror" id="campaign_id" name="campaign_id">
                    <option value="">Not assigned</option>
                    @foreach($campaigns as $campaign)<option value="{{ $campaign->id }}" @selected((string) old('campaign_id', $account->campaign_id ?? '') === (string) $campaign->id)>{{ $campaign->name }}</option>@endforeach
                </select>
                @error('campaign_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="password">{{ $editing ? 'New password' : 'Password' }} @if($editing)<span class="text-muted">(optional)</span>@endif</label>
                <div class="input-group">
                    <input class="form-control {{ $errors->has('password') && ! $confirmationMismatch ? 'is-invalid' : '' }}" id="password" name="password" type="password" {{ $editing ? '' : 'required' }} autocomplete="new-password">
                    <button class="btn btn-outline-secondary password-toggle" type="button" data-password-target="password" aria-label="Show {{ $editing ? 'new ' : '' }}password" aria-pressed="false" title="Show password"><x-ui.icon name="eye" size="18" data-eye-show /><x-ui.icon class="d-none" name="eye-off" size="18" data-eye-hide /></button>
                </div>
                @if($passwordError && ! $confirmationMismatch)<div class="text-danger small mt-1">{{ $passwordError }}</div>@endif
                @if($editing)<div class="form-text">Leave blank to preserve the existing password. A replacement is temporary and must be changed at the user's next sign-in.</div>@endif
            </div>
            <div class="col-md-6">
                <label class="form-label" for="password_confirmation">{{ $editing ? 'Confirm new password' : 'Confirm password' }}</label>
                <div class="input-group">
                    <input class="form-control {{ $confirmationMismatch || $errors->has('password_confirmation') ? 'is-invalid' : '' }}" id="password_confirmation" name="password_confirmation" type="password" {{ $editing ? '' : 'required' }} autocomplete="new-password">
                    <button class="btn btn-outline-secondary password-toggle" type="button" data-password-target="password_confirmation" aria-label="Show {{ $editing ? 'new password confirmation' : 'password confirmation' }}" aria-pressed="false" title="Show password"><x-ui.icon name="eye" size="18" data-eye-show /><x-ui.icon class="d-none" name="eye-off" size="18" data-eye-hide /></button>
                </div>
                @if($confirmationMismatch)<div class="text-danger small mt-1">{{ $passwordError }}</div>@endif
                @error('password_confirmation')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <input name="is_active" type="hidden" value="0">
                <div class="form-check form-switch">
                    <input class="form-check-input" id="is_active" name="is_active" type="checkbox" value="1" @checked((bool) old('is_active', $account->is_active ?? true))>
                    <label class="form-check-label" for="is_active">Account is active and may sign in</label>
                </div>
                @error('is_active')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
    </div>
</x-ui.form-section>

<div id="tl-notification-fields" @if(! $requiresActivityRecipients) hidden @endif>
    <x-ui.form-section eyebrow="Activity notifications" title="Assigned OM and SMT" icon="notification" class="mb-3">
        <p class="text-muted mb-3">Both email addresses are required for Requestor / Team Leader accounts. Only the assigned OM and SMT receive updates when the TL submits or withdraws a request and when staff advance it through screening, canvassing, PO approvals, budget, payment, and receiving.</p>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="om_notification_email">OM notification email <span class="text-danger">*</span></label>
                <input class="form-control @error('om_notification_email') is-invalid @enderror" id="om_notification_email" name="om_notification_email" type="email" value="{{ old('om_notification_email', $account->om_notification_email ?? '') }}" maxlength="255" autocomplete="off" @required($requiresActivityRecipients) @disabled(! $requiresActivityRecipients)>
                @error('om_notification_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Email address of this TL's assigned Operations Manager.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="smt_notification_email">SMT notification email <span class="text-danger">*</span></label>
                <input class="form-control @error('smt_notification_email') is-invalid @enderror" id="smt_notification_email" name="smt_notification_email" type="email" value="{{ old('smt_notification_email', $account->smt_notification_email ?? '') }}" maxlength="255" autocomplete="off" @required($requiresActivityRecipients) @disabled(! $requiresActivityRecipients)>
                @error('smt_notification_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Email address of this TL's assigned SMT recipient.</div>
            </div>
        </div>
    </x-ui.form-section>
</div>

<div class="action-bar d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="{{ $editing ? route('admin.accounts.show', $account) : route('admin.accounts.index') }}">Cancel</a>
    <button class="btn btn-primary" type="submit">{{ $editing ? 'Save Changes' : 'Create Account' }}</button>
</div>

@push('scripts')
<script>
    (() => {
        const roles = document.querySelectorAll('.procura-form-page [data-role-picker] input[type="checkbox"]');
        const campaign = document.getElementById('campaign_id');
        const requiredRoleIds = @json($campaignRoleIds);
        const managementRoleIds = @json($managementRoleIds);
        const syncCampaignRequirement = () => {
            const requiresRecipients = Array.from(roles).some(role => role.checked && requiredRoleIds.includes(Number(role.value)));
            campaign.required = requiresRecipients || Array.from(roles).some(role => role.checked && managementRoleIds.includes(Number(role.value)));
            document.getElementById('tl-notification-fields').hidden = ! requiresRecipients;
            ['om_notification_email', 'smt_notification_email'].forEach((id) => {
                const input = document.getElementById(id);
                input.required = requiresRecipients;
                input.disabled = ! requiresRecipients;
            });
        };
        roles.forEach(role => role.addEventListener('change', syncCampaignRequirement));
        syncCampaignRequirement();

        document.querySelectorAll('[data-password-target]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.passwordTarget);
                const showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                button.setAttribute('aria-pressed', String(! showing));
                button.setAttribute('aria-label', `${showing ? 'Show' : 'Hide'} ${button.dataset.passwordTarget === 'password' ? '{{ $editing ? 'new password' : 'password' }}' : '{{ $editing ? 'new password confirmation' : 'password confirmation' }}'}`);
                button.title = showing ? 'Show password' : 'Hide password';
                button.querySelector('[data-eye-show]').classList.toggle('d-none', ! showing);
                button.querySelector('[data-eye-hide]').classList.toggle('d-none', showing);
            });
        });
    })();
</script>
@endpush
