import { writeFile } from 'node:fs/promises';

const base = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const debug = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9223';
const requestId = process.env.E2E_REQUEST_ID ?? '49';
const stage = process.env.E2E_STAGE ?? 'canvassing';
const email = process.env.E2E_EMAIL ?? 'purchasing@example.com';
const password = process.env.E2E_PASSWORD ?? 'Procura123!';
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const target = await (await fetch(`${debug}/json/new?${encodeURIComponent(`${base}/login`)}`, { method: 'PUT' })).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
const requests = new Map();
let nextId = 0;
await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});
socket.addEventListener('message', (event) => {
    const reply = JSON.parse(event.data);
    if (! requests.has(reply.id)) return;
    const { resolve, reject } = requests.get(reply.id);
    requests.delete(reply.id);
    reply.error ? reject(new Error(reply.error.message)) : resolve(reply.result);
});
const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++nextId;
    requests.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
});
const evaluate = async (expression) => {
    const reply = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (reply.exceptionDetails) throw new Error(reply.exceptionDetails.text);
    return reply.result.value;
};
const until = async (predicate) => {
    for (let i = 0; i < 100; i += 1) {
        if (await predicate()) return;
        await wait(100);
    }
    throw new Error('Page did not load.');
};

try {
    await send('Page.enable');
    await send('Page.navigate', { url: `${base}/login` });
    await until(async () => await evaluate('document.readyState') === 'complete');
    if (await evaluate('document.querySelector("#email") !== null')) {
        await evaluate(`(() => {
            document.querySelector('#email').value = ${JSON.stringify(email)};
            document.querySelector('#password').value = ${JSON.stringify(password)};
            document.querySelector('form').requestSubmit();
        })()`);
    }
    await until(async () => await evaluate('location.pathname') === '/dashboard');
    await send('Page.navigate', { url: `${base}/purchase-requests/${requestId}?from_stage=${stage}` });
    await until(async () => await evaluate('document.querySelector(".purchase-request-page-header") !== null'));
    await evaluate("document.documentElement.style.scrollBehavior = 'auto'");
    for (const [name, width, height] of [['desktop', 1680, 1000], ['mobile', 390, 844]]) {
        await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: name === 'mobile' });
        await wait(300);
        for (const location of ['top', 'action']) {
            await evaluate(location === 'top' ? 'scrollTo(0, 0)' : 'document.querySelector("#canvassing-actions")?.scrollIntoView()');
            await wait(300);
            const metrics = await evaluate(`(() => ({ viewport: innerWidth, documentWidth: document.documentElement.scrollWidth, scrollY,
                title: document.querySelector('h1')?.textContent?.trim(),
                stage: document.querySelector('.workflow-flow-title')?.textContent?.trim(),
                actionForms: document.querySelectorAll('#canvassing-actions form').length,
                actionButtons: document.querySelectorAll('#canvassing-actions [type="submit"]').length,
            }))()`);
            const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
            await writeFile(new URL(`../../storage/app/request-flow-${requestId}-${name}-${location}.png`, import.meta.url), Buffer.from(shot.data, 'base64'));
            console.log(name, location, JSON.stringify(metrics));
        }
    }
    const interaction = await evaluate(`(() => {
        const quoteLink = document.querySelector('a[href="#supplier-quotations"]');
        if (quoteLink) quoteLink.click();
        const open = document.querySelector('#supplier-quotations-panel')?.open ?? null;
        const add = document.querySelector('#add-quotation');
        const before = document.querySelectorAll('[data-quotation-row]').length;
        if (add) add.click();
        return { quotationsOpen: open, quotationRowsBefore: before, quotationRowsAfter: document.querySelectorAll('[data-quotation-row]').length,
            documentWidth: document.documentElement.scrollWidth, viewport: innerWidth };
    })()`);
    console.log('interactions', JSON.stringify(interaction));
    if (interaction.quotationsOpen === false || (interaction.quotationRowsBefore > 0 && interaction.quotationRowsAfter !== interaction.quotationRowsBefore + 1)) {
        throw new Error('Quotation disclosure or Add quotation did not respond.');
    }
    await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
    const pdf = await send('Page.printToPDF', { printBackground: true, preferCSSPageSize: true, displayHeaderFooter: false });
    const buffer = Buffer.from(pdf.data, 'base64');
    const pages = (buffer.toString('latin1').match(/\/Type\s*\/Page\b/g) ?? []).length;
    await writeFile(new URL(`../../storage/app/request-flow-${requestId}-summary.pdf`, import.meta.url), buffer);
    console.log('print', JSON.stringify({ pages, bytes: buffer.length }));
    if (pages !== 1) throw new Error(`Expected one printed page; got ${pages}.`);
} finally {
    socket.close();
    await fetch(`${debug}/json/close/${target.id}`);
}
