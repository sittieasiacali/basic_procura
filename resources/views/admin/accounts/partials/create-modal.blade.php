@php
    $newUserSelectedRoles = old('role_ids', old('role_selection') ? [] : [old('role_id', $newUserRoles->firstWhere('slug', \App\Enums\RoleSlug::Requestor->value)['id'] ?? $newUserRoles->first()['id'] ?? '')]);
    $newUserRequestorIds = $newUserRoles->whereIn('slug', [\App\Enums\RoleSlug::Requestor->value, \App\Enums\RoleSlug::TeamLeader->value])->pluck('id')->map(fn ($id) => (string) $id)->all();
    $newUserIsRequestor = count(array_intersect((array) $newUserSelectedRoles, $newUserRequestorIds)) > 0;
    $newUserHasErrors = old('create_account_modal') && $errors->any();
@endphp
<div class="modal fade new-user-modal" id="add-new-user-modal" tabindex="-1" aria-labelledby="add-new-user-title" aria-hidden="true" @if(request()->boolean('create_user') || $newUserHasErrors) data-new-user-auto-open @endif>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="POST" action="{{ route('admin.accounts.store') }}" autocomplete="off" data-new-user-form>
            @csrf
            <input type="hidden" name="create_account_modal" value="1">
            <input type="hidden" name="account_origin" value="{{ request()->routeIs('dashboard') ? 'dashboard' : 'accounts' }}">
            <input type="hidden" name="is_active" value="1">
            <div class="modal-header">
                <h2 class="modal-title" id="add-new-user-title">Add New User</h2>
                <button class="new-user-close" type="button" data-bs-dismiss="modal" aria-label="Close Add New User"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                @if($newUserHasErrors)<div class="new-user-error" role="alert">Please correct the highlighted fields.</div>@endif
                <div class="new-user-fields">
                    <div>
                        <label for="new-user-employee-id">Employee ID <span>*</span></label>
                        <input class="form-control @if($newUserHasErrors && $errors->has('employee_id')) is-invalid @endif" id="new-user-employee-id" name="employee_id" value="{{ old('employee_id') }}" placeholder="e.g. ALL000100" maxlength="100" required>
                        @if($newUserHasErrors) @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                    </div>
                    <div>
                        <label for="new-user-username">Username</label>
                        <input class="form-control @if($newUserHasErrors && $errors->has('username')) is-invalid @endif" id="new-user-username" name="username" value="{{ old('username') }}" placeholder="Optional" maxlength="100">
                        @if($newUserHasErrors) @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                    </div>
                    <div class="new-user-full-width">
                        <label for="new-user-name">Full Name <span>*</span></label>
                        <input class="form-control @if($newUserHasErrors && $errors->has('name')) is-invalid @endif" id="new-user-name" name="name" value="{{ old('name') }}" placeholder="e.g. Cristina Alferez" maxlength="255" required>
                        @if($newUserHasErrors) @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                    </div>
                    <div class="new-user-full-width">
                        <label for="new-user-email">Email (sign-in) <span>*</span></label>
                        <input class="form-control @if($newUserHasErrors && $errors->has('email')) is-invalid @endif" type="email" id="new-user-email" name="email" value="{{ old('email') }}" placeholder="name@example.com" maxlength="255" required>
                        @if($newUserHasErrors) @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                    </div>
                    <div class="new-user-full-width">
                        <x-account-role-checkboxes :options="$newUserRoles" :selected="$newUserSelectedRoles" prefix="new-user" />
                    </div>
                    <div>
                        <label for="new-user-team-id">Team ID</label>
                        <input class="form-control @if($newUserHasErrors && $errors->has('team_id')) is-invalid @endif" id="new-user-team-id" name="team_id" value="{{ old('team_id') }}" placeholder="Optional" maxlength="100">
                        @if($newUserHasErrors) @error('team_id')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                    </div>
                </div>
                <fieldset class="new-user-requestor-fields" data-new-user-requestor-fields @disabled(! $newUserIsRequestor)>
                    <legend>TL workflow email recipients</legend>
                    <p class="small text-muted mb-2">OM and SMT email addresses are required for TL / REQUESTOR accounts. Both receive updates as the TL's request moves through the workflow.</p>
                    <label for="new-user-campaign">Campaign <span>*</span></label>
                    <select class="form-select" id="new-user-campaign" name="campaign_id" @required($newUserIsRequestor)>
                        <option value="">Select a campaign</option>
                        @foreach($newUserCampaigns as $newUserCampaign)<option value="{{ $newUserCampaign->id }}" @selected((string) old('campaign_id') === (string) $newUserCampaign->id)>{{ $newUserCampaign->name }}</option>@endforeach
                    </select>
                    @if($newUserHasErrors) @error('campaign_id')<div class="new-user-field-error">{{ $message }}</div>@enderror @endif
                    <label for="new-user-om-email">OM notification email <span>*</span></label>
                    <input class="form-control @if($newUserHasErrors && $errors->has('om_notification_email')) is-invalid @endif" type="email" id="new-user-om-email" name="om_notification_email" value="{{ old('om_notification_email') }}" maxlength="255" placeholder="Assigned OM email" @required($newUserIsRequestor)>
                    @if($newUserHasErrors) @error('om_notification_email')<div class="new-user-field-error">{{ $message }}</div>@enderror @endif
                    <label for="new-user-smt-email">SMT notification email <span>*</span></label>
                    <input class="form-control @if($newUserHasErrors && $errors->has('smt_notification_email')) is-invalid @endif" type="email" id="new-user-smt-email" name="smt_notification_email" value="{{ old('smt_notification_email') }}" maxlength="255" placeholder="Assigned SMT email" @required($newUserIsRequestor)>
                    @if($newUserHasErrors) @error('smt_notification_email')<div class="new-user-field-error">{{ $message }}</div>@enderror @endif
                </fieldset>
                <div class="new-user-password-field">
                    <label for="new-user-password">Password <span>*</span></label>
                    <div class="new-user-password-input">
                        <input class="form-control @if($newUserHasErrors && $errors->has('password')) is-invalid @endif" type="password" id="new-user-password" name="password" placeholder="Min 8 characters" minlength="8" required autocomplete="new-password">
                        <button type="button" data-new-user-toggle-password aria-label="Show password" aria-pressed="false"><x-ui.icon name="eye" size="17" /></button>
                    </div>
                    @if($newUserHasErrors) @error('password')<div class="new-user-field-error">{{ $message }}</div>@enderror @endif
                    <button class="new-user-generate" type="button" data-new-user-generate><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m4 20 12-12 4 4L8 24M14 2v4M20 2v4M18 4h4M2 10h4M4 8v4"/></svg>Auto-generate</button>
                    <p class="new-user-password-note">New accounts must change this default password after their first sign-in.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="new-user-cancel" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn new-user-save" type="submit"><x-ui.icon name="save" size="13" />Save</button>
            </div>
        </form>
    </div>
</div>
@push('scripts')
<script>
(() => {
    const modal = document.getElementById('add-new-user-modal');
    if (!modal) return;
    const form = modal.querySelector('[data-new-user-form]');
    const password = modal.querySelector('#new-user-password');
    const toggle = modal.querySelector('[data-new-user-toggle-password]');
    const roles = modal.querySelectorAll('[data-role-picker] input[type="checkbox"]');
    const requestorFields = modal.querySelector('[data-new-user-requestor-fields]');
    const requestorRoleIds = @json($newUserRequestorIds);
    const syncRole = () => {
        const isRequestor = Array.from(roles).some(role => role.checked && requestorRoleIds.includes(role.value));
        requestorFields.disabled = !isRequestor;
        requestorFields.querySelectorAll('input,select').forEach(input => input.required = isRequestor);
    };
    roles.forEach(role => role.addEventListener('change', syncRole));
    syncRole();
    toggle.addEventListener('click', () => {
        const show = password.type === 'password';
        password.type = show ? 'text' : 'password';
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        toggle.setAttribute('aria-pressed', String(show));
    });
    modal.querySelector('[data-new-user-generate]').addEventListener('click', () => {
        const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
        const random = crypto.getRandomValues(new Uint32Array(16));
        password.value = Array.from(random, value => alphabet[value % alphabet.length]).join('');
        password.type = 'text';
        toggle.setAttribute('aria-label', 'Hide password');
        toggle.setAttribute('aria-pressed', 'true');
        password.dispatchEvent(new Event('input', { bubbles: true }));
    });
    modal.addEventListener('shown.bs.modal', () => (modal.querySelector('.is-invalid') || modal.querySelector('#new-user-employee-id')).focus());
    modal.addEventListener('hidden.bs.modal', () => {
        const url = new URL(location.href);
        if (url.searchParams.has('create_user')) {
            url.searchParams.delete('create_user');
            history.replaceState(history.state, '', url);
        }
    });
    form.addEventListener('submit', () => {
        if (form.checkValidity()) form.querySelector('[type="submit"]').disabled = true;
    });
    if (modal.hasAttribute('data-new-user-auto-open')) bootstrap.Modal.getOrCreateInstance(modal).show();
})();
</script>
@endpush
