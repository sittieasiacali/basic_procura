<div class="row g-2 mb-2 align-items-end" data-source-account-row>
    <div class="col-md-7"><label class="form-label" for="budget-source-{{ $sourceIndex }}">Campaign source account</label>
        <select class="form-select" id="budget-source-{{ $sourceIndex }}" name="source_accounts[{{ $sourceIndex }}][campaign_id]" data-source-campaign required>
            <option value="">Select campaign source account</option>
            @foreach($campaigns as $campaign)<option value="{{ $campaign->id }}" @selected((string) ($source['campaign_id'] ?? '') === (string) $campaign->id)>{{ $campaign->name }} &mdash; {{ $campaign->account_number }}</option>@endforeach
        </select>
        @error("source_accounts.$sourceIndex.campaign_id")<div class="text-danger small">{{ $message }}</div>@enderror
    </div>
    <div class="col-8 col-md-3"><label class="form-label" for="budget-source-amount-{{ $sourceIndex }}">Contribution (PHP)</label><input class="form-control" id="budget-source-amount-{{ $sourceIndex }}" name="source_accounts[{{ $sourceIndex }}][amount]" type="number" min="0.01" max="9999999999999.99" step="0.01" value="{{ $source['amount'] ?? '' }}" data-source-amount required>
        @error("source_accounts.$sourceIndex.amount")<div class="text-danger small">{{ $message }}</div>@enderror
    </div>
    <div class="col-4 col-md-2"><button class="btn btn-outline-secondary w-100" type="button" data-remove-source-account>Remove</button></div>
</div>
