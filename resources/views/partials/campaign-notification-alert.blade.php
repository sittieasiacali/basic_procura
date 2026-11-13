@auth
    @if(! ($hideNavigation ?? false) && \App\Support\ProcuraChatAccess::isCampaignRole(auth()->user()))
        <dialog class="campaign-notification-alert" data-campaign-notification-alert aria-labelledby="campaign-alert-heading" aria-describedby="campaign-alert-message">
            <div class="campaign-notification-alert-heading">
                <x-ui.icon name="notification" size="28" />
                <h2 id="campaign-alert-heading">New campaign update</h2>
            </div>
            <div class="campaign-notification-alert-body">
                <strong data-campaign-alert-title></strong>
                <p id="campaign-alert-message" data-campaign-alert-message></p>
                <div class="d-flex flex-wrap justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" type="button" data-campaign-alert-dismiss>Got it</button>
                    <a class="btn btn-primary" data-campaign-alert-open hidden>Open conversation</a>
                </div>
            </div>
        </dialog>
        <dialog class="campaign-notification-alert" data-campaign-alert-consent @if(request()->routeIs('chat.index')) data-consent-auto-open @endif aria-labelledby="campaign-consent-heading" aria-describedby="campaign-consent-message">
            <div class="campaign-notification-alert-heading">
                <x-ui.icon name="notification" size="28" />
                <h2 id="campaign-consent-heading">Enable ringtone and vibration?</h2>
            </div>
            <div class="campaign-notification-alert-body">
                <p id="campaign-consent-message">Receive a ringtone and vibration for new campaign updates while Procura is open. Once confirmed, campaign alerts stay enabled in Procura.</p>
                <p class="small text-muted" data-campaign-consent-support></p>
                <div class="d-flex flex-wrap justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" type="button" data-campaign-consent-decline>Not now</button>
                    <button class="btn btn-primary" type="button" data-campaign-consent-enable>Enable alerts</button>
                </div>
            </div>
        </dialog>
    @endif
@endauth