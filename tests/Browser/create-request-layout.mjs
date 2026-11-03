import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';

const baseUrl = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const debugUrl = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9223';
const email = process.env.E2E_EMAIL ?? 'teamleader@example.com';
const password = process.env.E2E_PASSWORD ?? 'Procura123!';
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const target = await (await fetch(`${debugUrl}/json/new?about:blank`, { method: 'PUT' })).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
const pending = new Map();
const errors = [];
let nextId = 0;

await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});
socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    if (! message.id || ! pending.has(message.id)) return;
    const { resolve, reject } = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) reject(new Error(message.error.message));
    else resolve(message.result);
});
const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++nextId;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
});
const evaluate = async (expression) => {
    const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
    return result.result.value;
};
const waitFor = async (expression) => {
    for (let attempt = 0; attempt < 100; attempt += 1) {
        try { if (await evaluate(expression)) return; } catch { /* Page navigation replaces the context. */ }
        await delay(100);
    }
    throw new Error(`Timed out: ${expression}`);
};
const capture = async (name) => {
    await evaluate('document.fonts.ready');
    const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    await writeFile(`storage/app/${name}.png`, Buffer.from(result.data, 'base64'));
};
const metrics = () => evaluate(`(() => ({
    width: innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    items: document.querySelectorAll('[data-item-row]').length,
    title: document.querySelector('h1')?.textContent.trim(),
    summary: document.querySelector('#request-summary-heading')?.textContent.trim(),
    fieldsRemoved: ['quantity', 'unit', 'estimated_unit_price'].every(field => !document.querySelector('[name="items[0][' + field + ']"]')),
    submitDisabled: document.querySelector('[name="action"][value="submit"]').disabled,
}))()`);

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.clearBrowserCookies');
    await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 768, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: `${baseUrl}/login` });
    await waitFor(`location.pathname === '/login' && document.readyState === 'complete'`);
    await evaluate(`(() => {
        document.querySelector('#email').value = ${JSON.stringify(email)};
        document.querySelector('#password').value = ${JSON.stringify(password)};
        document.querySelector('form').requestSubmit();
    })()`);
    await waitFor(`location.pathname === '/dashboard'`);

    for (const width of [1366, 390]) {
        await send('Emulation.setDeviceMetricsOverride', { width, height: 768, deviceScaleFactor: 1, mobile: width < 768 });
        await send('Page.navigate', { url: `${baseUrl}/purchase-requests/create` });
        await waitFor(`location.pathname === '/purchase-requests/create' && document.readyState === 'complete'`);
        const initial = await metrics();
        assert.equal(initial.title, 'Create Purchase Request');
        assert.equal(initial.summary, 'Request Summary');
        assert.equal(initial.items, 1);
        assert.ok(initial.fieldsRemoved);
        assert.ok(initial.submitDisabled);
        assert.ok(initial.scrollWidth <= width, `${width}px horizontal overflow`);
        await capture(`create-request-${width}`);
        await evaluate(`document.querySelector('#requested-items-heading').scrollIntoView({ block: 'start' })`);
        await delay(150);
        await capture(`create-request-items-${width}`);

        await evaluate(`document.querySelector('#add-item').click()`);
        assert.equal((await metrics()).items, 2);
        await evaluate(`document.querySelectorAll('[data-remove-item]')[1].click()`);
        assert.equal((await metrics()).items, 1);
        await evaluate(`document.querySelector('[name="items[0][description]"]').value = 'Conference display'; document.querySelector('[name="items[0][description]"]').dispatchEvent(new Event('input', { bubbles: true }))`);
        assert.equal((await metrics()).submitDisabled, false);
        console.log(`CREATE_REQUEST_LAYOUT_OK ${JSON.stringify(initial)}`);
    }
    assert.deepEqual(errors, []);
} finally {
    socket.close();
    await fetch(`${debugUrl}/json/close/${target.id}`).catch(() => {});
}
