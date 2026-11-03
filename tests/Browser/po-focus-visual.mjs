import { writeFile } from 'node:fs/promises';

const base = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const debug = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9222';
const email = process.env.E2E_EMAIL ?? 'purchasing@example.com';
const password = process.env.E2E_PASSWORD ?? 'Procura123!';
const requestId = process.env.E2E_REQUEST_ID ?? '68';
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
    if (! pending.has(result.id)) return;
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
const waitFor = async (test) => {
    for (let i = 0; i < 100; i += 1) {
        if (await test()) return;
        await delay(100);
    }
    throw new Error('Page did not reach the expected state.');
};

try {
    await send('Page.enable');
    await send('Page.navigate', { url: `${base}/login` });
    await waitFor(async () => await evaluate('document.readyState') === 'complete');
    if (await evaluate('document.querySelector("#email") !== null')) {
        await evaluate(`(() => {
            document.querySelector('#email').value = ${JSON.stringify(email)};
            document.querySelector('#password').value = ${JSON.stringify(password)};
            document.querySelector('form').requestSubmit();
        })()`);
    }
    await waitFor(async () => (await evaluate('location.pathname')) === '/dashboard');
    await send('Page.navigate', { url: `${base}/purchase-requests/${requestId}?from_stage=purchase-order` });
    await waitFor(async () => (await evaluate('document.querySelector(".po-focus-page") !== null')));
    for (const [label, width, height] of [['desktop', 1680, 1000], ['mobile', 390, 844]]) {
        await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: label === 'mobile' });
        await delay(350);
        const metrics = await evaluate(`(() => {
            const form = document.querySelector('.po-focus-form');
            const button = document.querySelector('.po-focus-submit');
            const rect = (element) => { const r = element.getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width,height:r.height}; };
            return {
                viewport: innerWidth, documentWidth: document.documentElement.scrollWidth,
                title: document.querySelector('h1')?.innerText,
                cards: document.querySelectorAll('.po-focus-card').length,
                poForms: document.querySelectorAll('form[action*="purchase-orders/"]').length,
                poButtons: document.querySelectorAll('.po-focus-submit').length,
                form: form && rect(form), button: button && rect(button),
                hero: rect(document.querySelector('.po-focus-hero')),
                quotationsCollapsed: !document.querySelector('#po-all-quotations').open,
            };
        })()`);
        const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        await writeFile(new URL(`../../storage/app/po-visual-${label}.png`, import.meta.url), Buffer.from(shot.data, 'base64'));
        console.log(label, JSON.stringify(metrics));
    }
    await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
    const printable = await evaluate(`(() => ({
        documentVisible: getComputedStyle(document.querySelector('.po-print-summary')).display,
        printControl: document.querySelector('.po-focus-extra-actions button')?.textContent?.trim(),
        requestNumber: document.querySelector('.po-print-summary').textContent.includes('REQ-SAMPLE-PO-QUEUE-001'),
    }))()`);
    const pdf = await send('Page.printToPDF', { printBackground: true, preferCSSPageSize: true, displayHeaderFooter: false });
    const pdfBuffer = Buffer.from(pdf.data, 'base64');
    const pdfFile = new URL('../../storage/app/po-request-summary.pdf', import.meta.url);
    await writeFile(pdfFile, pdfBuffer);
    const pageCount = (pdfBuffer.toString('latin1').match(/\/Type\s*\/Page\b/g) ?? []).length;
    console.log('print', JSON.stringify({ ...printable, pageCount, bytes: pdfBuffer.length }));
    if (pageCount !== 1) throw new Error(`Expected one printed page; got ${pageCount}.`);
    await send('Emulation.setDeviceMetricsOverride', { width: 1000, height: 1350, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: pdfFile.href });
    await delay(1200);
    const pdfShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    await writeFile(new URL('../../storage/app/po-request-summary-preview.png', import.meta.url), Buffer.from(pdfShot.data, 'base64'));
} finally {
    socket.close();
    await fetch(`${debug}/json/close/${target.id}`);
}
