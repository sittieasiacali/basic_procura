const baseUrl = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8012';
const debugUrl = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9232';
const adminPassword = process.env.E2E_PASSWORD ?? 'Procura123!';
const matchingPassword = 'EdgeCampaign123!';
const marker = `edge-campaign-${Date.now()}`;
const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function waitUntil(callback, description) {
    for (let attempt = 0; attempt < 150; attempt += 1) {
        try {
            if (await callback()) return;
        } catch {
            // Navigation temporarily replaces the active JavaScript context.
        }
        await delay(100);
    }
    throw new Error(`Timed out waiting for ${description}.`);
}

await waitUntil(async () => (await fetch(`${debugUrl}/json/version`)).ok, 'Microsoft Edge debugging endpoint');
const target = await (await fetch(`${debugUrl}/json/new?${encodeURIComponent(`${baseUrl}/login`)}`, { method: 'PUT' })).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
const pending = new Map();
let commandId = 0;

await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});

socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (! message.id || ! pending.has(message.id)) return;
    const { resolve, reject } = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) reject(new Error(message.error.message));
    else resolve(message.result);
});

const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++commandId;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
});

const evaluate = async (expression) => {
    const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);
    return result.result.value;
};

const bodyText = () => evaluate('document.body.innerText');
const pathName = () => evaluate('location.pathname');

async function navigate(path) {
    await send('Page.navigate', { url: `${baseUrl}${path}` });
    await waitUntil(async () => (await pathName()) === path.split('?')[0], `${path} navigation`);
    await waitUntil(async () => (await evaluate('document.readyState')) === 'complete', `${path} document load`);
}

async function login() {
    await navigate('/login');
    await evaluate(`(() => {
        document.querySelector('#email').value = 'admin@example.com';
        document.querySelector('#password').value = ${JSON.stringify(adminPassword)};
        document.querySelector('form').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await pathName()) === '/dashboard', 'Admin dashboard');
}

async function verifyPasswordToggles() {
    const result = await evaluate(`(() => {
        const buttons = [...document.querySelectorAll('[data-password-target]')];
        const before = buttons.map(button => document.getElementById(button.dataset.passwordTarget).type);
        buttons.forEach(button => button.click());
        const shown = buttons.map(button => ({
            type: document.getElementById(button.dataset.passwordTarget).type,
            pressed: button.getAttribute('aria-pressed'),
            label: button.getAttribute('aria-label'),
        }));
        buttons.forEach(button => button.click());
        const after = buttons.map(button => document.getElementById(button.dataset.passwordTarget).type);
        return { count: buttons.length, before, shown, after };
    })()`);

    if (result.count !== 2) {
        const context = await evaluate(`(() => ({ path: location.pathname, title: document.title, text: document.body.innerText.slice(0, 500) }))()`);
        throw new Error(`Expected 2 password toggles, found ${result.count} at ${context.path} (${context.title}): ${context.text}`);
    }
    if (! result.before.every(type => type === 'password') || ! result.after.every(type => type === 'password')) throw new Error('Password fields did not return to password type.');
    if (! result.shown.every(item => item.type === 'text' && item.pressed === 'true' && item.label.startsWith('Hide'))) throw new Error('Password visibility controls are not accessible or functional.');
}

await send('Page.enable');
await send('Runtime.enable');
await send('Network.enable');
await send('Network.clearBrowserCookies');

let campaignPath;
let accountPath;
const campaignName = `${marker} Campaign`;
const updatedCampaignName = `${marker} Updated`;
const accountEmail = `${marker}@example.com`;

try {
    await login();
    await navigate('/admin/campaigns');
    if (! (await bodyText()).includes('Create Campaign')) throw new Error('Campaign Management is missing from the Admin page.');

    await navigate('/admin/campaigns/create');
    await evaluate(`(() => {
        const form = document.querySelector('main form[action$="/admin/campaigns"]');
        form.querySelector('#name').value = ${JSON.stringify(campaignName)};
        form.querySelector('#description').value = 'Created during Microsoft Edge verification.';
        form.querySelector('#is_active').checked = true;
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => /\/admin\/campaigns\/\d+$/.test(await pathName()), 'campaign detail');
    campaignPath = await pathName();
    if (! (await bodyText()).includes('Campaign Created')) throw new Error('Campaign creation audit is missing.');

    await navigate(`${campaignPath}/edit`);
    await evaluate(`(() => {
        const form = document.querySelector('input[name="_method"][value="PUT"]').form;
        form.querySelector('#name').value = ${JSON.stringify(updatedCampaignName)};
        form.querySelector('#description').value = 'Edited during Microsoft Edge verification.';
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await pathName()) === campaignPath, 'edited campaign detail');
    if (! (await bodyText()).includes(updatedCampaignName) || ! (await bodyText()).includes('Campaign Updated')) throw new Error('Campaign edit or audit was not saved.');

    await evaluate(`(() => {
        window.confirm = () => true;
        document.querySelector('form[action$="/status"]').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('Campaign Deactivated'), 'campaign deactivation');
    await evaluate(`(() => {
        window.confirm = () => true;
        document.querySelector('form[action$="/status"]').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('Campaign Activated'), 'campaign reactivation');

    await navigate('/admin/accounts/create');
    await verifyPasswordToggles();
    await evaluate(`(() => {
        const form = document.querySelector('main form[action$="/admin/accounts"]');
        form.querySelector('#name').value = ${JSON.stringify(`${marker} Requestor`)};
        form.querySelector('#email').value = ${JSON.stringify(accountEmail)};
        const role = form.querySelector('#role_id');
        role.value = [...role.options].find(option => option.text.trim() === 'Requestor').value;
        role.dispatchEvent(new Event('change'));
        const campaign = form.querySelector('#campaign_id');
        campaign.value = [...campaign.options].find(option => option.text.trim() === ${JSON.stringify(updatedCampaignName)}).value;
        form.querySelector('#password').value = 'FirstPassword123!';
        form.querySelector('#password_confirmation').value = 'DifferentPassword123!';
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('The password confirmation does not match the password.'), 'password mismatch validation');
    const mismatchState = await evaluate(`(() => ({
        name: document.querySelector('#name').value,
        email: document.querySelector('#email').value,
        password: document.querySelector('#password').value,
        confirmation: document.querySelector('#password_confirmation').value,
        buttonEnabled: !document.querySelector('main form[action$="/admin/accounts"] button[type="submit"]').disabled,
    }))()`);
    if (mismatchState.name !== `${marker} Requestor` || mismatchState.email !== accountEmail) throw new Error('Safe account input was not preserved after validation.');
    if (mismatchState.password !== '' || mismatchState.confirmation !== '') throw new Error('Password values were repopulated after validation.');
    if (! mismatchState.buttonEnabled) throw new Error('Create Account button became unusable after validation.');

    await evaluate(`(() => {
        const form = document.querySelector('main form[action$="/admin/accounts"]');
        form.querySelector('#password').value = ${JSON.stringify(matchingPassword)};
        form.querySelector('#password_confirmation').value = ${JSON.stringify(matchingPassword)};
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => /\/admin\/accounts\/\d+$/.test(await pathName()), 'matching-password account creation');
    accountPath = await pathName();
    const accountText = await bodyText();
    if (! accountText.includes(accountEmail) || ! accountText.includes(updatedCampaignName) || ! accountText.includes('Account Created')) throw new Error('Matching passwords did not create the assigned account.');
    if (accountText.includes(matchingPassword)) throw new Error('Password appeared in browser-visible account details.');

    await navigate(`${accountPath}/edit`);
    await verifyPasswordToggles();
    const editPasswordState = await evaluate(`(() => ({
        password: document.querySelector('#password').value,
        confirmation: document.querySelector('#password_confirmation').value,
        labels: [...document.querySelectorAll('label')].map(label => label.innerText),
    }))()`);
    if (editPasswordState.password !== '' || editPasswordState.confirmation !== '') throw new Error('Edit form exposed an existing password value.');
    if (! editPasswordState.labels.includes('New password (optional)') || ! editPasswordState.labels.includes('Confirm new password')) throw new Error('Edit password labels are unclear.');

    console.log(JSON.stringify({
        result: 'EDGE_CAMPAIGN_PASSWORD_WORKFLOW_OK',
        marker,
        campaignPath,
        accountPath,
        verified: [
            'campaign-list', 'campaign-create', 'campaign-edit', 'campaign-deactivate',
            'campaign-reactivate', 'campaign-audits', 'password-show-hide-create',
            'password-mismatch-message', 'safe-old-input', 'passwords-not-repopulated',
            'matching-password-account-create', 'password-show-hide-edit', 'saved-password-not-exposed',
        ],
    }));
} finally {
    socket.close();
    await fetch(`${debugUrl}/json/close/${target.id}`).catch(() => {});
}
