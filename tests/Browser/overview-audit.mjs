import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';

// Receives short-lived audit sessions created separately; never writes cookies to reports.
const directory = process.env.PROCURA_AUDIT_DIR;
if (!directory) throw new Error('Set PROCURA_AUDIT_DIR to a private audit directory.');
const base = process.env.E2E_BASE_URL ?? 'http://localhost:18473';
const debug = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9339';
const sessionData = JSON.parse(await readFile(process.env.PROCURA_AUDIT_SESSIONS ?? path.join(directory, 'sessions.json'), 'utf8'));
const allAccounts = Array.isArray(sessionData) ? sessionData : Object.values(sessionData.accounts);
const accounts = process.env.PROCURA_AUDIT_ONLY ? allAccounts.filter(account => String(account.id) === process.env.PROCURA_AUDIT_ONLY) : allAccounts;
const stress = process.env.PROCURA_AUDIT_STRESS === '1';
const target = await (await fetch(`${debug}/json/new?about:blank`, { method: 'PUT' })).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
const pending = new Map();
const errors = [];
let id = 0;
await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});
socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    const item = pending.get(message.id);
    if (!item) return;
    clearTimeout(item.timer);
    pending.delete(message.id);
    if (message.error) item.reject(new Error(message.error.message));
    else item.resolve(message.result);
});
const send = (method, params = {}) => new Promise((resolve, reject) => {
    const number = ++id;
    const timer = setTimeout(() => { pending.delete(number); reject(new Error(`Timed out: ${method}`)); }, 20000);
    pending.set(number, { resolve, reject, timer });
    socket.send(JSON.stringify({ id: number, method, params }));
});
const evaluate = async (expression) => {
    const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
    return result.result.value;
};
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const results = [];
try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');
    await mkdir(path.join(directory, 'screenshots'), { recursive: true });
    for (const account of accounts) {
        await send('Network.clearBrowserCookies');
        await send('Network.setCookie', { name: account.cookie_name, value: account.cookie_value, url: base, httpOnly: true });
        for (const width of [1920, 1366, 1024, 768, 390]) {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
            await send('Page.navigate', { url: `${base}/dashboard?audit=${account.id}-${width}` });
            await pause(150);
            for (let attempt = 0; attempt < 100; attempt++) {
                if (await evaluate(`document.readyState === 'complete' && location.search.includes('audit=')`)) break;
                if (await evaluate(`document.readyState === 'complete' && ['/change-password','/login'].includes(location.pathname)`)) break;
                await pause(100);
            }
            await evaluate('document.fonts.ready.then(() => true)');
            await pause(100);
            const currentPath = await evaluate('location.pathname');
            if (currentPath === '/login') throw new Error(`Audit session is not authenticated for account ${account.id}`);
            if (stress) await evaluate(`(() => {
                document.querySelectorAll('.dashboard-context-details dd').forEach(el => { el.textContent = 'LongAccountSpecificValue'.repeat(8); });
                const greeting = document.querySelector('.dashboard-greeting');
                if (greeting) greeting.textContent = 'Good morning, ' + 'LongAccountName'.repeat(15);
                return true;
            })()`);
            const measurement = await evaluate(`(() => {
                const visible = el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
                const rect = el => { const r = el.getBoundingClientRect(); return { x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom }; };
                const collisions=[];
                for (const selector of ['.dashboard-context-details','.dashboard-kpi-grid','.dashboard-page-header','.dashboard-creator-tabs']) {
                    const container=document.querySelector(selector);
                    if (!container) continue;
                    const children=[...container.children].filter(visible);
                    for(let i=0;i<children.length;i++) for(let j=i+1;j<children.length;j++) {
                        const a=rect(children[i]),b=rect(children[j]);
                        if(Math.min(a.right,b.right)-Math.max(a.x,b.x)>2 && Math.min(a.bottom,b.bottom)-Math.max(a.y,b.y)>2) collisions.push({selector,first:i,second:j});
                    }
                }
                const clipped=[...document.querySelectorAll('.dashboard-context-details dd,.kpi-label,.dashboard-greeting,.dashboard-creator-tab-label')].filter(visible).filter(el=>el.scrollWidth>el.clientWidth+2).map(el=>({text:el.textContent.trim(),width:el.clientWidth,content:el.scrollWidth,overflow:getComputedStyle(el).overflowX}));
                return {path:location.pathname,title:document.querySelector('h1')?.textContent.trim(),viewport:innerWidth,documentWidth:document.documentElement.scrollWidth,horizontalOverflow:document.documentElement.scrollWidth>innerWidth+2,collisions,clipped,role:document.querySelector('.dashboard-context-details')?.innerText,styles:[...document.styleSheets].map(s=>s.href).filter(Boolean),headings:[...document.querySelectorAll('h1,h2,h3')].filter(visible).map(el=>el.textContent.trim()),kpis:[...document.querySelectorAll('[data-kpi]')].map(el=>el.textContent.trim())};
            })()`);
            results.push({ account: account.id, ...measurement });
            if ((stress || [8,11,27,28].includes(account.id)) && [1366,390].includes(width)) {
                const capture = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
                await writeFile(path.join(directory, 'screenshots', `${stress ? 'stress-' : ''}account-${account.id}-${width}.png`), Buffer.from(capture.data, 'base64'));
            }
        }
        process.stdout.write(`Checked account ${account.id}\n`);
    }
    await writeFile(path.join(directory, stress ? 'overview-stress-results.json' : 'overview-results.json'), JSON.stringify({ base, stress, results, errors }, null, 2));
    console.log(JSON.stringify({ checked:results.length,overflows:results.filter(r=>r.horizontalOverflow).map(r=>({account:r.account,width:r.viewport,document:r.documentWidth})),collisions:results.filter(r=>r.collisions.length),clipped:results.filter(r=>r.clipped.length),errors },null,2));
} finally {
    socket.close();
    await fetch(`${debug}/json/close/${target.id}`).catch(() => {});
}
