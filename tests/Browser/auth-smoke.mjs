const baseUrl = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const debugUrl = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9222';
const email = process.env.E2E_EMAIL ?? 'admin@example.com';
const password = process.env.E2E_PASSWORD ?? 'Procura123!';

const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function waitForDebuggingEndpoint() {
    for (let attempt = 0; attempt < 40; attempt += 1) {
        try {
            const response = await fetch(`${debugUrl}/json/version`);

            if (response.ok) return;
        } catch {
            // The browser may still be starting.
        }

        await delay(250);
    }

    throw new Error('Browser debugging endpoint did not become available.');
}

async function waitUntil(evaluate, description) {
    for (let attempt = 0; attempt < 80; attempt += 1) {
        if (await evaluate()) return;
        await delay(100);
    }

    throw new Error(`Timed out waiting for ${description}.`);
}

await waitForDebuggingEndpoint();

const targetResponse = await fetch(`${debugUrl}/json/new?${encodeURIComponent(`${baseUrl}/login`)}`, {
    method: 'PUT',
});
const target = await targetResponse.json();
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
    const result = await send('Runtime.evaluate', { expression, returnByValue: true });

    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);

    return result.result.value;
};

try {
    await send('Page.enable');
    await send('Runtime.enable');

    await waitUntil(
        async () => (await evaluate('location.pathname')).endsWith('/login'),
        'the login page',
    );

    const loginTitle = await evaluate('document.title');
    if (! loginTitle.includes('Sign in')) throw new Error(`Unexpected login title: ${loginTitle}`);

    await evaluate(`(() => {
        document.querySelector('#email').value = ${JSON.stringify(email)};
        document.querySelector('#password').value = ${JSON.stringify(password)};
        document.querySelector('form').requestSubmit();
        return true;
    })()`);

    await waitUntil(
        async () => (await evaluate('location.pathname')).endsWith('/dashboard'),
        'the authenticated dashboard',
    );

    const dashboardText = await evaluate('document.body.innerText');
    if (! dashboardText.includes('System Administrator') || ! dashboardText.includes('Admin')) {
        throw new Error('Dashboard did not show the seeded admin identity and role.');
    }

    await evaluate("document.querySelector('form[action$=\"/logout\"]').requestSubmit(); true");

    await waitUntil(
        async () => (await evaluate('location.pathname')).endsWith('/login'),
        'logout to return to login',
    );

    console.log('BROWSER_AUTH_SMOKE_OK');
} finally {
    socket.close();
    await fetch(`${debugUrl}/json/close/${target.id}`).catch(() => {});
}
