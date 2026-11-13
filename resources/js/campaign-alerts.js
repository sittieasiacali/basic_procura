import '../css/campaign-alerts.css';

export function vibrateCampaignAlert(device = globalThis.navigator) {
    if (typeof device?.vibrate !== 'function') return false;
    try { return device.vibrate([200, 100, 200]); } catch { return false; }
}

export function initializeCampaignAlerts(root = document, browser = window, device = globalThis.navigator, storage) {
    const dialog = root.querySelector('[data-campaign-notification-alert]');
    if (!dialog) return;
    const user = root.querySelector('meta[name="notification-sound-user"]')?.content;
    const key = 'asi-procura:campaign-alerts:' + user;
    let vibrate = false, decision = null;
    try {
        storage ??= globalThis.localStorage;
        const saved = JSON.parse(storage.getItem(key));
        decision = saved?.decision || null;
    } catch { /* Session-only fallback. */ }
    const consent = root.querySelector('[data-campaign-alert-consent]');
    const persist = () => {
        try { storage.setItem(key, JSON.stringify({ decision, vibrate })); } catch { /* Session-only fallback. */ }
    };
    const showConsent = () => {
        if (!consent || consent.open || root.hidden) return;
        if (typeof consent.showModal === 'function') consent.showModal();
        else consent.setAttribute('open', '');
    };
    const closeConsent = () => {
        if (typeof consent?.close === 'function') consent.close();
        else consent?.removeAttribute('open');
    };
    const testKey = 'asi-procura:alert-test:' + user;
    let testPlayed = false;
    try { testPlayed = storage.getItem(testKey) === '1'; } catch { /* Session-only fallback. */ }
    const testButtons = root.querySelectorAll('[data-campaign-test-alert]');
    const supported = typeof device?.vibrate === 'function';
    vibrate = decision === 'enabled' && supported;
    const toggles = root.querySelectorAll('[data-campaign-vibrate]');
    const statuses = root.querySelectorAll('[data-campaign-vibration-status]');
    const dismiss = dialog.querySelector('[data-campaign-alert-dismiss]');
    const open = dialog.querySelector('[data-campaign-alert-open]');
    const status = message => statuses.forEach(node => { node.textContent = message; });
    const update = () => {
        testButtons.forEach(button => { button.hidden = decision === 'enabled' || testPlayed; });
        toggles.forEach(toggle => { toggle.checked = vibrate; toggle.disabled = decision === 'enabled' || !supported; });
        status(supported ? 'Vibration depends on your phone settings.' : 'Vibration is unavailable in this browser.');
    };
    const stop = () => {
        if (supported) { try { device.vibrate(0); } catch { /* Device may reject vibration. */ } }
        browser.dispatchEvent(new Event('procura:stop-notification-alert'));
    };
    const show = notification => {
        if (root.hidden || decision !== 'enabled') return;
        dialog.querySelector('[data-campaign-alert-title]').textContent = notification.title || 'Campaign update';
        dialog.querySelector('[data-campaign-alert-message]').textContent = notification.message || 'A new update is available in Procura Chat.';
        open.hidden = !notification.url;
        if (notification.url) open.href = notification.url;
        else open.removeAttribute('href');
        if (!dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (vibrate && supported && !vibrateCampaignAlert(device)) status('Your phone blocked vibration. Check its notification settings.');
    };
    const close = () => {
        stop();
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
    };
    toggles.forEach(toggle => toggle.addEventListener('change', () => {
        toggle.checked = vibrate;
        if (decision !== 'enabled') showConsent();
    }));
    testButtons.forEach(button => button.addEventListener('click', () => {
        if (!testPlayed && decision !== 'enabled') showConsent();
    }));
    dismiss.addEventListener('click', close);
    open.addEventListener('click', stop);
    dialog.addEventListener('cancel', stop);
    dialog.addEventListener('close', stop);
    browser.addEventListener('procura:new-notification', event => show(event.detail));
    browser.addEventListener('procura:alert-test-completed', () => { testPlayed = true; update(); });
    browser.addEventListener('storage', event => {
        if (event.key === testKey) { testPlayed = event.newValue === '1'; update(); return; }
        if (event.key !== key) return;
        try {
            const saved = JSON.parse(event.newValue);
            const previousDecision = decision;
            decision = saved?.decision || null;
            vibrate = decision === 'enabled' && supported;
            update();
            if (previousDecision !== decision) browser.dispatchEvent(new CustomEvent('procura:alert-consent', { detail: { enabled: decision === 'enabled', preview: false } }));
            if (!vibrate) stop();
            if (decision) closeConsent();
        } catch { /* Ignore invalid preferences. */ }
    });
    if (consent) {
        consent.querySelector('[data-campaign-consent-support]').textContent = supported
            ? 'Tap Enable alerts to activate sound and vibration on this browser.'
            : 'This browser does not support website vibration. You can still enable the ringtone.';
        consent.querySelector('[data-campaign-consent-enable]').addEventListener('click', () => {
            decision = 'enabled';
            vibrate = supported;
            persist();
            update();
            closeConsent();
            // This event stays inside the tap so mobile browsers can activate audio.
            browser.dispatchEvent(new CustomEvent('procura:alert-consent', { detail: { enabled: true, preview: true } }));
            if (vibrate && !testPlayed) vibrateCampaignAlert(device);
        });
        const decline = () => {
            decision = 'declined';
            vibrate = false;
            persist();
            update();
            closeConsent();
            browser.dispatchEvent(new CustomEvent('procura:alert-consent', { detail: { enabled: false } }));
            stop();
        };
        consent.querySelector('[data-campaign-consent-decline]').addEventListener('click', decline);
        consent.addEventListener('cancel', event => { event.preventDefault(); decline(); });
        browser.addEventListener('procura:request-alert-consent', showConsent);
        const autoPrompt = () => { if (!decision && consent.hasAttribute('data-consent-auto-open')) showConsent(); };
        root.addEventListener?.('visibilitychange', autoPrompt);
        autoPrompt();
    }
    update();
}

initializeCampaignAlerts();