@php
    $formKey = (string) ($supplier?->id ?? 'new');
    $retry = old('_supplier_form') === $formKey;
    $fieldValue = fn ($field, $default = null) => $retry ? old($field, $default) : $default;
    $selectedTeam = $supplier?->team ?? $fieldValue('team', $team ?? array_key_first($teams));
@endphp
<input type="hidden" name="_supplier_form" value="{{ $formKey }}">
<div class="row g-3">
    @if(! $supplier && count($teams) > 1)
        <div class="col-12"><label class="form-label" for="supplier-new-team">Team</label><select class="form-select" id="supplier-new-team" name="team" required>@foreach($teams as $value => $label)<option value="{{ $value }}" @selected($selectedTeam === $value)>{{ $label }}</option>@endforeach</select></div>
    @else
        <input type="hidden" name="team" value="{{ $selectedTeam }}">
    @endif
    @foreach(['name' => 'Supplier name', 'contact_person' => 'Contact person', 'email' => 'Email', 'phone' => 'Phone', 'tax_id' => 'Tax ID'] as $field => $label)
        <div class="col-md-6"><label class="form-label" for="supplier-{{ $formKey }}-{{ $field }}">{{ $label }}</label><input class="form-control" id="supplier-{{ $formKey }}-{{ $field }}" name="{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" maxlength="{{ $field === 'phone' ? 50 : 255 }}" value="{{ $fieldValue($field, $supplier?->{$field}) }}" @required($field === 'name')></div>
    @endforeach
    <div class="col-md-6"><label class="form-label" for="supplier-{{ $formKey }}-status">Availability</label><select class="form-select" name="is_active" id="supplier-{{ $formKey }}-status"><option value="1" @selected((bool) $fieldValue('is_active', $supplier?->is_active ?? true))>Active</option><option value="0" @selected(! (bool) $fieldValue('is_active', $supplier?->is_active ?? true))>Inactive</option></select></div>
    <div class="col-12"><label class="form-label" for="supplier-{{ $formKey }}-address">Address</label><textarea class="form-control" id="supplier-{{ $formKey }}-address" name="address" maxlength="2000">{{ $fieldValue('address', $supplier?->address) }}</textarea></div>
    <div class="col-12"><label class="form-label" for="supplier-{{ $formKey }}-bank-details">Bank details</label><textarea class="form-control" id="supplier-{{ $formKey }}-bank-details" name="bank_details" maxlength="2000" rows="3" placeholder="Bank name, account name, account number, and payment instructions">{{ $fieldValue('bank_details', $supplier?->bank_details) }}</textarea><div class="form-text">Used for quotation auto-fill when no previous quotation bank details are available.</div></div>
</div>
