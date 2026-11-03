const baseUrl = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8011';
const debugUrl = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9231';
const adminPassword = process.env.E2E_PASSWORD ?? 'Procura123!';
const accountPassword = 'EdgeAccount123!';
const marker = `edge${Date.now()}`;
const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function waitUntil(callback, description) {
    for (let attempt = 0; attempt < 150; attempt += 1) {
        try {
            if (await callback()) return;
        } catch {
            // Navigation temporarily replaces the JavaScript context.
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

async function login(email, password, expectSuccess = true) {
    await navigate('/login');
    await evaluate(`(() => {
        document.querySelector('#email').value = ${JSON.stringify(email)};
        document.querySelector('#password').value = ${JSON.stringify(password)};
        document.querySelector('form').requestSubmit();
        return true;
    })()`);

    if (expectSuccess) {
        await waitUntil(async () => (await pathName()) === '/dashboard', `${email} dashboard`);
    } else {
        await waitUntil(async () => (await bodyText()).includes('invalid or the account is inactive'), `${email} inactive-login rejection`);
        if ((await pathName()) !== '/login') throw new Error('Inactive account was allowed to leave the login page.');
    }
}

async function logout() {
    await evaluate(`(() => {
        document.querySelector('form[action$="/logout"]').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await pathName()) === '/login', 'logout');
}

async function createAccount(roleName, email, fullName) {
    await navigate('/admin/accounts/create');
    await evaluate(`(() => {
        const form = document.querySelector('main form[action$="/admin/accounts"]');
        form.querySelector('#name').value = ${JSON.stringify(fullName)};
        form.querySelector('#email').value = ${JSON.stringify(email)};
        form.querySelector('#password').value = ${JSON.stringify(accountPassword)};
        form.querySelector('#password_confirmation').value = ${JSON.stringify(accountPassword)};
        const role = form.querySelector('#role_id');
        role.value = [...role.options].find(option => option.text.trim() === ${JSON.stringify(roleName)}).value;
        role.dispatchEvent(new Event('change'));
        const campaign = form.querySelector('#campaign_id');
        campaign.value = [...campaign.options].find(option => option.text.trim() === 'Operations').value;
        form.querySelector('#is_active').checked = true;
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => /\/admin\/accounts\/\d+$/.test(await pathName()), `${roleName} account detail`);
    const text = await bodyText();
    for (const value of [fullName, email, roleName, 'Operations', 'Active', 'Account Created']) {
        if (! text.includes(value)) throw new Error(`${roleName} account detail is missing ${value}.`);
    }

    return { path: await pathName(), email, fullName };
}

await send('Page.enable');
await send('Runtime.enable');
await send('Network.enable');
await send('Network.clearBrowserCookies');

const requestorEmail = `${marker}.requestor@example.com`;
const leaderEmail = `${marker}.leader@example.com`;

try {
    await login('admin@example.com', adminPassword);
    const dashboard = await bodyText();
    if (! dashboard.includes('ASI PROCURA Dashboard') || ! dashboard.includes('Manage Accounts')) {
        throw new Error('ASI PROCURA branding or the Admin menu is missing.');
    }
    if (! (await evaluate('document.title.includes("ASI PROCURA")'))) throw new Error('Browser title is missing ASI PROCURA.');

    await navigate('/admin/accounts');
    const accountList = await bodyText();
    for (const heading of ['Full name', 'Email', 'Role', 'Assigned campaign', 'Status', 'Date created', 'Actions']) {
        if (! accountList.includes(heading)) throw new Error(`Manage Accounts is missing ${heading}.`);
    }

    const requestor = await createAccount('Requestor', requestorEmail, `${marker} Requestor`);
    const teamLeader = await createAccount('Team Leader', leaderEmail, `${marker} Team Leader`);

    await navigate(`${requestor.path}/edit`);
    await evaluate(`(() => {
        const form = document.querySelector('input[name="_method"][value="PUT"]').form;
        form.querySelector('#name').value = ${JSON.stringify(`${marker} Updated Requestor`)};
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await pathName()) === requestor.path, 'edited account detail');
    if (! (await bodyText()).includes(`${marker} Updated Requestor`)) throw new Error('Edited account name was not saved.');

    await evaluate(`(() => {
        window.confirm = () => true;
        document.querySelector('form[action$="/status"]').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('Inactive'), 'account deactivation');
    if (! (await bodyText()).includes('Account Deactivated')) throw new Error('Deactivation audit entry is missing.');

    await logout();
    await login(requestorEmail, accountPassword, false);

    await login('admin@example.com', adminPassword);
    await navigate(requestor.path);
    await evaluate(`(() => {
        window.confirm = () => true;
        document.querySelector('form[action$="/status"]').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('This account can currently sign in.'), 'account reactivation');
    if (! (await bodyText()).includes('Account Activated')) throw new Error('Activation audit entry is missing.');

    await logout();
    await login(requestorEmail, accountPassword);
    const access = await evaluate(`fetch('/admin/accounts', { redirect: 'manual' }).then(response => response.status)`);
    if (access !== 403) throw new Error(`Non-admin Manage Accounts response was ${access}, expected 403.`);
    const requestorMenu = await bodyText();
    if (requestorMenu.includes('Manage Accounts')) throw new Error('Requestor can see the Admin Manage Accounts menu.');
    if (! requestorMenu.includes('Create Request') || ! requestorMenu.includes('My Requests')) throw new Error('Requestor menu is incomplete.');
    await logout();

    console.log(JSON.stringify({
        result: 'EDGE_ADMIN_ACCOUNTS_WORKFLOW_OK',
        marker,
        accounts: [requestor, teamLeader],
        verified: [
            'asi-procura-branding', 'admin-menu', 'account-list', 'requestor-creation',
            'team-leader-creation', 'campaign-assignment', 'account-edit', 'deactivation',
            'reactivation', 'inactive-login-block', 'non-admin-403', 'role-based-sidebar', 'account-audit-history',
        ],
    }));
} finally {
    socket.close();
    await fetch(`${debugUrl}/json/close/${target.id}`).catch(() => {});
}
