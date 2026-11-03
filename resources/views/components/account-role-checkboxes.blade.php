@props(['options', 'selected' => [], 'prefix' => 'account'])
<fieldset class="account-role-picker" data-role-picker>
    <legend class="form-label">Assigned roles <span class="text-danger">*</span></legend>
    <p class="small text-muted mb-2">Select all roles this person needs. They can switch workspaces from their account menu.</p>
    <input type="hidden" name="role_selection" value="1">
    <div class="account-role-options">
        @foreach($options as $option)
            <label class="account-role-option" for="{{ $prefix }}-role-{{ $option['id'] }}">
                <input type="checkbox" id="{{ $prefix }}-role-{{ $option['id'] }}" name="role_ids[]" value="{{ $option['id'] }}" @checked(in_array((string) $option['id'], array_map('strval', (array) $selected), true))>
                <span>{{ $option['label'] }}</span>
            </label>
        @endforeach
    </div>
    @error('role_ids')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror
    @error('role_ids.*')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror
    @error('role_id')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror
</fieldset>
@once
    @push('scripts')
    <script>
        document.querySelectorAll('[data-role-picker]').forEach(picker => {
            const boxes = Array.from(picker.querySelectorAll('input[type="checkbox"]'));
            const validate = () => boxes[0]?.setCustomValidity(boxes.some(box => box.checked) ? '' : 'Select at least one role.');
            boxes.forEach(box => box.addEventListener('change', validate));
            validate();
        });
    </script>
    @endpush
@endonce
