@if(\App\Support\ProcuraChatAccess::isCampaignRole(auth()->user()))
    <div class="campaign-alert-controls">
        <label class="d-inline-flex align-items-center gap-2">
            <input class="form-check-input m-0" type="checkbox" data-campaign-vibrate>
            <span>Vibrate</span>
        </label>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-campaign-test-alert>Test alert</button>
        <span class="small text-muted" data-campaign-vibration-status role="status"></span>
    </div>
@endif