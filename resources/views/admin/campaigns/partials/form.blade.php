@php $editing = isset($campaign); @endphp

<x-ui.form-section eyebrow="Assignment group" title="Campaign Information" icon="campaign" class="mb-3">
    <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="name">Campaign name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $campaign->name ?? '') }}" maxlength="255" required autofocus>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label" for="account_number">Campaign account number</label>
                <input class="form-control @error('account_number') is-invalid @enderror" id="account_number" name="account_number" value="{{ old('account_number', $campaign->account_number ?? '') }}" maxlength="255" placeholder="Example: OPERATIONS-001">
                @error('account_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label" for="description">Description or remarks</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4" maxlength="5000">{{ old('description', $campaign->description ?? '') }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                @if($editing && $campaign->is_active && $campaign->active_users_count > 0)
                    <input name="is_active" type="hidden" value="1">
                @else
                    <input name="is_active" type="hidden" value="0">
                @endif
                <div class="form-check form-switch">
                    <input class="form-check-input" id="is_active" name="is_active" type="checkbox" value="1" @checked((bool) old('is_active', $campaign->is_active ?? true)) @disabled($editing && $campaign->is_active && $campaign->active_users_count > 0)>
                    <label class="form-check-label" for="is_active">Campaign is active and available for account assignment</label>
                </div>
                @if($editing && $campaign->is_active && $campaign->active_users_count > 0)
                    <div class="form-text text-warning">This campaign has {{ $campaign->active_users_count }} active assigned account(s). Reassign or deactivate those accounts before deactivating the campaign.</div>
                @endif
                @error('is_active')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
    </div>
</x-ui.form-section>



<div class="action-bar d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="{{ $editing ? route('admin.campaigns.show', $campaign) : route('admin.campaigns.index') }}">Cancel</a>
    <button class="btn btn-primary" type="submit">{{ $editing ? 'Save Changes' : 'Create Campaign' }}</button>
</div>
