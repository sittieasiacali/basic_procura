import { writeFile } from 'node:fs/promises';

const base = process.env.E2E_BASE_URL ?? 'http://localhost/basic%20procura/public';
const debug = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9222';
const password = process.env.E2E_PASSWORD ?? 'Procura123!';
const requestId = process.env.E2E_REQUEST_ID ?? '18';
const sessionCookie = process.env.E2E_SESSION_COOKIE;
const checkedSession = process.env.E2E_CHECKED_SESSION;
const preparedSession = process.env.E2E_PREPARED_SESSION;
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const target = await (await fetch(`${debug}/json/new?${encodeURIComponent(`${base}/login`)}`, { method: 'PUT' })).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
const pending = new Map();
let sequence = 0;
await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});
socket.addEventListener('message', (event) => {
    const result = JSON.parse(event.data);
    if (!pending.has(result.id)) return;
    const { resolve, reject } = pending.get(result.id);
    pending.delete(result.id);
    result.error ? reject(new Error(result.error.message)) : resolve(result.result);
});
const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++sequence;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
});
const evaluate = async (expression) => {
    const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
    return result.result.value;
};
const waitFor = async (test, label) => {
    for (let i = 0; i < 150; i += 1) {
        if (await test()) return;
        await delay(100);
    }
    throw new Error(`Timed out waiting for ${label}.`);
};
const login = async (email) => {
    await send('Page.navigate', { url: `${base}/login` });
    await waitFor(async () => await evaluate('document.readyState') === 'complete', 'login page');
    await evaluate(`(() => {
        document.querySelector('#email').value = ${JSON.stringify(email)};
        document.querySelector('#password').value = ${JSON.stringify(password)};
        document.querySelector('form').requestSubmit();
    })()`);
    await waitFor(async () => (await evaluate('location.href')).includes('/dashboard'), `${email} login`);
};
const useSession = async (sessionId, expectedUser) => {
    await send('Network.enable');
    await send('Network.clearBrowserCookies');
    const result = await send('Network.setCookie', { name: sessionCookie, value: sessionId, url: base });
    if (!result.success) throw new Error(`Could not install browser session for ${expectedUser}.`);
    await send('Page.navigate', { url: `${base}/dashboard` });
    await waitFor(async () => await evaluate('document.readyState') === 'complete', `${expectedUser} dashboard`);
    await waitFor(async () => await evaluate(`document.querySelector('.user-menu-name')?.textContent.trim() === ${JSON.stringify(expectedUser)}`), `${expectedUser} session`);
};
const openRequest = async () => {
    await send('Page.navigate', { url: `${base}/purchase-requests/${requestId}?from_stage=purchase-order` });
    await waitFor(async () => await evaluate('document.readyState') === 'complete', 'request page');
    await waitFor(async () => await evaluate('document.querySelector("[data-po-signature-stage]") !== null'), 'PO signature history');
};

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });

    if (sessionCookie && checkedSession) await useSession(checkedSession, 'PO Checked UAT');
    else await login('po.checked@example.com');
    const checkerDashboardHasAction = await evaluate(`[...document.querySelectorAll('.dashboard-actions-panel a.request-number')].some((link) => link.href.includes('/purchase-requests/${requestId}'))`);
    if (checkerDashboardHasAction) throw new Error('Checker dashboard exposed Request #18 before Prepared By completed.');
    await openRequest();
    const checker = await evaluate(`(() => ({
        user: document.querySelector('.user-menu-name')?.textContent.trim(),
        warning: [...document.querySelectorAll('.alert')].map((node) => node.textContent.replace(/\\s+/g, ' ').trim()).find((text) => text.includes('signature required')),
        canvases: document.querySelectorAll('[data-signature-canvas]').length,
        preparedSave: document.body.textContent.includes('Save Prepared By Signature'),
        checkedSave: document.body.textContent.includes('Save Checked By Signature'),
        lockedMessage: document.body.textContent.includes('Your Checked By signature box will be available after the Prepared By signature is saved.'),
    }))()`);
    if (checker.user !== 'PO Checked UAT') throw new Error(`Unexpected checker account: ${checker.user}`);
    if (!checker.warning?.includes('Prepared By signature required')) throw new Error(`Missing Prepared By blocker: ${checker.warning}`);
    if (checker.canvases !== 0 || checker.preparedSave || checker.checkedSave) throw new Error('Checker was incorrectly offered a signature pad before Prepared By signed.');
    if (!checker.lockedMessage) throw new Error('Checker did not receive the locked-stage explanation.');

    if (sessionCookie && preparedSession) await useSession(preparedSession, 'PO Prepared UAT');
    else {
        await evaluate(`document.querySelector('form[action$="/logout"]').requestSubmit()`);
        await waitFor(async () => (await evaluate('location.href')).includes('/login'), 'logout');
        await login('po.prepared@example.com');
    }
    const preparerDashboardHasAction = await evaluate(`[...document.querySelectorAll('.dashboard-actions-panel a.request-number')].some((link) => link.href.includes('/purchase-requests/${requestId}'))`);
    if (! preparerDashboardHasAction) throw new Error('Preparer dashboard did not expose Request #18 for the missing Prepared By signature.');
    await openRequest();
    await evaluate(`document.querySelector('[data-po-signature-stage="PO_PREPARED_BY"] [data-signature-canvas]')?.scrollIntoView({ block: 'center' })`);
    await delay(300);
    const prepared = await evaluate(`(() => {
        const stage = document.querySelector('[data-po-signature-stage="PO_PREPARED_BY"]');
        const canvas = stage?.querySelector('[data-signature-canvas]');
        const rect = canvas?.getBoundingClientRect();
        return {
            user: document.querySelector('.user-menu-name')?.textContent.trim(),
            saveAction: stage?.textContent.includes('Save Prepared By Signature'),
            canvasVisible: !!canvas && getComputedStyle(canvas).display !== 'none' && rect.width > 0 && rect.height > 0,
            rect: rect && { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
        };
    })()`);
    if (prepared.user !== 'PO Prepared UAT') throw new Error(`Unexpected preparer account: ${prepared.user}`);
    if (!prepared.saveAction || !prepared.canvasVisible) throw new Error(`Prepared By canvas is not visible: ${JSON.stringify(prepared)}`);

    const x = prepared.rect.x + 35;
    const y = prepared.rect.y + 35;
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: x + 100, y: y + 25, button: 'left', buttons: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: x + 100, y: y + 25, button: 'left', clickCount: 1 });
    const usable = await evaluate(`(() => {
        const stage = document.querySelector('[data-po-signature-stage="PO_PREPARED_BY"]');
        const value = stage?.querySelector('[data-signature-data]')?.value ?? '';
        return value.startsWith('data:image/png;base64,') && value.length > 100;
    })()`);
    if (!usable) throw new Error('The visible Prepared By canvas did not initialize or capture a stroke.');

    const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    await writeFile(new URL('../../storage/app/po-signature-request18-prepared.png', import.meta.url), Buffer.from(shot.data, 'base64'));
    console.log(JSON.stringify({ requestId, checkerDashboardHasAction, preparerDashboardHasAction, checker, prepared, usable, submitted: false }, null, 2));
} finally {
    socket.close();
    await fetch(`${debug}/json/close/${target.id}`);
}