const baseUrl = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const debugUrl = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9223';
const email = process.env.E2E_EMAIL ?? 'teamleader@example.com';
const password = process.env.E2E_PASSWORD ?? 'Procura123!';
const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

for (let attempt = 0; attempt < 80; attempt += 1) {
    try {
        if ((await fetch(`${debugUrl}/json/version`)).ok) break;
    } catch {
        // Edge may still be starting.
    }
    if (attempt === 79) throw new Error('Browser debugging endpoint did not become available.');
    await delay(100);
}

const target = await (await fetch(`${debugUrl}/json/new?${encodeURIComponent(`${baseUrl}/login`)}`, { method: 'PUT' })).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
const pending = new Map();
const browserErrors = [];
let commandId = 0;

await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});

socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (message.method === 'Runtime.exceptionThrown') browserErrors.push(message.params.exceptionDetails.text);
    if (message.method === 'Log.entryAdded' && message.params.entry.level === 'error') browserErrors.push(message.params.entry.text);
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

async function waitUntil(callback, description) {
    for (let attempt = 0; attempt < 120; attempt += 1) {
        if (await callback()) return;
        await delay(100);
    }
    throw new Error(`Timed out waiting for ${description}.`);
}

async function navigate(path) {
    await send('Page.navigate', { url: `${baseUrl}${path}` });
    await waitUntil(async () => (await evaluate('document.readyState')) === 'complete', `${path} load`);
    await waitUntil(async () => (await evaluate('location.pathname')) === path, `${path} navigation`);
}

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Log.enable');
    await send('Network.enable');
    await send('Network.clearBrowserCookies');
    await send('Page.navigate', { url: `${baseUrl}/login` });
    await waitUntil(async () => (await evaluate('location.pathname')) === '/login', 'login navigation');
    await waitUntil(async () => (await evaluate('document.readyState')) === 'complete', 'login page');

    await evaluate(`(() => {
        document.querySelector('#email').value = ${JSON.stringify(email)};
        document.querySelector('#password').value = ${JSON.stringify(password)};
        document.querySelector('form').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await evaluate('location.pathname')) === '/dashboard', 'Team Leader dashboard');

    const viewportResults = [];
    for (const width of [1920, 1366, 1024]) {
        await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
        await navigate('/dashboard');
        viewportResults.push(await evaluate(`(() => {
            const primary = document.querySelector('.dashboard-primary-action');
            const secondary = document.querySelector('.dashboard-secondary-action');
            const primaryStyle = getComputedStyle(primary);
            const secondaryStyle = getComputedStyle(secondary);
            const a = primary.getBoundingClientRect();
            const b = secondary.getBoundingClientRect();
            const overlaps = !(a.right <= b.left || b.right <= a.left || a.bottom <= b.top || b.bottom <= a.top);
            return {
                width: innerWidth,
                requestorDashboard: document.querySelector('.dashboard-page--requestor') !== null,
                kpis: document.querySelectorAll('.dashboard-kpi').length,
                primary: { height: a.height, background: primaryStyle.backgroundColor, radius: primaryStyle.borderRadius },
                secondary: { height: b.height, background: secondaryStyle.backgroundColor, borderWidth: secondaryStyle.borderTopWidth, radius: secondaryStyle.borderRadius },
                overlaps,
                overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
                hasActions: document.querySelector('#my-actions-heading') !== null,
                hasQuickLinks: document.querySelector('#quick-links-heading') !== null,
            };
        })()`));
    }

    for (const result of viewportResults) {
        if (! result.requestorDashboard || result.kpis !== 6 || ! result.hasActions || ! result.hasQuickLinks) throw new Error(`Dashboard content failed at ${result.width}px: ${JSON.stringify(result)}`);
        if (result.overlaps || result.overflow) throw new Error(`Responsive layout failed at ${result.width}px: ${JSON.stringify(result)}`);
        if (result.primary.height < 38 || result.primary.height > 41 || result.primary.background !== 'rgb(64, 148, 217)') throw new Error(`Primary action styling failed at ${result.width}px: ${JSON.stringify(result.primary)}`);
        if (result.secondary.height < 38 || result.secondary.height > 41 || result.secondary.borderWidth !== '1px') throw new Error(`Secondary action styling failed at ${result.width}px: ${JSON.stringify(result.secondary)}`);
    }

    const destinations = await evaluate(`(() => ({
        create: new URL(document.querySelector('.dashboard-primary-action').href).pathname,
        control: new URL(document.querySelector('.dashboard-secondary-action').href).pathname,
        requests: new URL([...document.querySelectorAll('.app-navbar .nav-link')].find(link => link.textContent.trim() === 'My Requests').href).pathname,
        history: new URL([...document.querySelectorAll('.app-navbar .nav-link')].find(link => link.textContent.trim() === 'Request History').href).pathname,
        identity: document.querySelector('.dashboard-identity-grid').innerText,
    }))()`);
    const expectedDestinations = { create: '/purchase-requests/create', control: '/request-flow', requests: '/purchase-requests', history: '/request-flow' };
    for (const [key, path] of Object.entries(expectedDestinations)) {
        if (destinations[key] !== path) throw new Error(`${key} points to ${destinations[key]} instead of ${path}.`);
        await navigate(path);
    }
    const identityText = destinations.identity.toLowerCase();
    for (const label of ['role', 'assigned campaign / team', 'department', 'account status']) {
        if (! identityText.includes(label)) throw new Error(`Dashboard identity is missing ${label}: ${JSON.stringify(destinations.identity)}`);
    }
    if (browserErrors.length) throw new Error(`Browser errors: ${browserErrors.join(' | ')}`);

    console.log(JSON.stringify({ result: 'DASHBOARD_UI_SMOKE_OK', viewportResults, destinations }));
} finally {
    socket.close();
    await fetch(`${debugUrl}/json/close/${target.id}`).catch(() => {});
}
