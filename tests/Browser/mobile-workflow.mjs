import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const artifacts = path.join(root, 'storage/app/private');
mkdirSync(artifacts, { recursive: true });
const chrome = [process.env.CHROME_BIN, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((candidate) => candidate && existsSync(candidate));
assert.ok(chrome, 'Set CHROME_BIN to Chrome or Edge.');
const profile = mkdtempSync(path.join(artifacts, 'mobile-workflow-chrome-'));
const manifest = JSON.parse(readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
const asset = (name) => pathToFileURL(path.join(root, 'public/build', manifest[name].file)).href;
const bootstrap = pathToFileURL(path.join(root, 'public/assets/vendor/bootstrap/bootstrap.min.css')).href;
const bootstrapJs = pathToFileURL(path.join(root, 'public/assets/vendor/bootstrap/bootstrap.bundle.min.js')).href;
const source = readFileSync(path.join(root, 'resources/js/mobile-workflow.js'), 'utf8');
const fixture = path.join(artifacts, 'mobile-workflow.html');
writeFileSync(fixture, `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="${bootstrap}"><link rel="stylesheet" href="${asset('resources/css/app.css')}"><link rel="stylesheet" href="${asset('resources/css/workspace-theme.css')}"><link rel="stylesheet" href="${asset('resources/css/mobile.css')}"></head>
<body class="workspace-theme"><aside class="offcanvas-lg offcanvas-start workspace-sidebar" id="workspaceSidebar" tabindex="-1"><div class="workspace-sidebar-brand"><strong>ASI PROCURA</strong><button class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#workspaceSidebar" aria-label="Close navigation"></button></div><div class="workspace-sidebar-role">Workspace<strong>Purchasing Team</strong></div><nav class="workspace-sidebar-links"><a class="workspace-sidebar-link is-active" href="#">Dashboard</a><a class="workspace-sidebar-link" href="#">My Actions</a></nav></aside>
<nav class="navbar navbar-expand-xl app-navbar app-navbar--workspace sticky-top"><div class="container-fluid"><button class="workspace-menu-toggle d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#workspaceSidebar" aria-label="Open navigation">&#9776;</button><div class="workspace-welcome">Welcome back, <strong>Sample Purchasing Team Member</strong><span class="workspace-date">Wed, 11 Nov 2026</span></div><div class="collapse navbar-collapse"><div class="navbar-tools"><button class="navbar-tool" aria-label="Notifications">&#9776;</button><button class="user-menu-button" aria-label="Account"><span class="user-avatar">PT</span><span class="user-menu-name">Sample Purchasing Team Member</span></button></div></div></div></nav>
<main class="app-main"><div class="dashboard-page"><header class="dashboard-page-header"><div class="dashboard-heading-block"><h1>My actions</h1><p>Review requests and keep procurement moving.</p></div></header><nav class="dashboard-creator-tabs"><a class="dashboard-creator-tab is-active" href="#">Canvassing</a><a class="dashboard-creator-tab" href="#">Purchase Orders</a><a class="dashboard-creator-tab" href="#">Final Budget Request</a></nav>
<section class="card"><div class="card-header"><h2 class="h5 mb-0">Requests waiting for you</h2></div><form class="table-toolbar"><div class="table-toolbar-field table-toolbar-search"><input class="form-control form-control-sm" name="search" aria-label="Search" placeholder="Search requests, suppliers?"></div><div class="table-toolbar-field"><select class="form-select form-select-sm" name="status" aria-label="Status"><option value="">All statuses</option><option value="pending">Pending</option></select></div><div class="table-toolbar-field table-toolbar-dates"><input class="form-control" type="date" name="from" aria-label="From"><span>to</span><input class="form-control" type="date" name="to" aria-label="To"></div><div class="table-toolbar-actions"><button type="submit" class="btn btn-primary">Filter</button></div></form>
<div class="table-responsive"><table data-mobile-cards class="table dashboard-table"><thead><tr><th>Request</th><th>Supplier</th><th>Amount</th><th>Actions</th></tr></thead><tbody><tr><td><strong>REQ00137</strong><div class="small text-muted">Laptop replacement for Operations</div></td><td>Long Supplier Company Name Philippines Incorporated</td><td class="text-end text-nowrap">&#8369; 25,756.00</td><td><button class="btn btn-primary" id="open-workflow" data-bs-toggle="modal" data-bs-target="#workflow-action-modal">Add quotation</button></td></tr></tbody></table></div></section>
<form class="table-toolbar" id="active-filters"><div class="table-toolbar-field table-toolbar-search"><input class="form-control" name="search" aria-label="Search history"></div><div class="table-toolbar-field"><input class="form-control" type="date" name="from" value="2026-11-11" aria-label="Updated from"></div><button class="btn btn-primary">Filter history</button></form><div class="table-responsive"><table data-mobile-cards class="table" id="bulk-list"><thead><tr><th><input type="checkbox" aria-label="Select all budget requests" id="select-all"></th><th>Budget request</th></tr></thead><tbody><tr><td><input type="checkbox" aria-label="Select budget request" class="bulk-row"></td><td>BR-001</td></tr></tbody></table></div></div></main>
<div class="modal fade" id="workflow-action-modal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable workflow-action-modal"><div class="modal-content"><div class="modal-header"><h2 class="modal-title" id="workflow-action-modal-title">Add quotation</h2><span data-workflow-request-code>REQ00137</span><div class="workflow-action-tools"><a class="btn btn-outline-secondary" data-workflow-action-open href="#">Open full page</a><button class="btn" data-workflow-action-expand>Expand</button><button class="btn btn-outline-secondary workflow-action-close" data-bs-dismiss="modal">Close</button></div></div><div class="modal-body p-0"><iframe class="workflow-action-frame" title="Quotation form" srcdoc="<meta name='viewport' content='width=device-width, initial-scale=1'><style>body{margin:16px;font-family:Arial;color:#160d76}input,button{box-sizing:border-box;width:100%;min-height:44px;font-size:16px;margin:8px 0 20px;padding:10px}label{display:block}</style><h2>Supplier quotation</h2><label>Supplier<input placeholder='Choose a supplier'></label><label>Amount<input type='number' placeholder='0.00'></label><button>Proceed to President review</button>"></iframe></div></div></div></div>
<script src="${bootstrapJs}"></script><script type="module">${source}
let submits = 0; document.querySelector('form.table-toolbar').addEventListener('submit', event => { event.preventDefault(); submits++; }); window.fixtureSubmits = () => submits; document.getElementById('select-all').addEventListener('change', event => { document.querySelector('.bulk-row').checked=event.target.checked; });
</script></body></html>`);

const browser = spawn(chrome, ['--headless', '--disable-gpu', '--in-process-gpu', '--no-sandbox', '--no-first-run', '--disable-background-networking', '--allow-file-access-from-files', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
let socket;
let assertions = 0;
const check = (condition, message) => { assertions++; assert.ok(condition, message); };
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
try {
    const portFile = path.join(profile, 'DevToolsActivePort');
    for (let i = 0; i < 100 && !existsSync(portFile); i++) await pause(50);
    check(existsSync(portFile), 'Browser started');
    const port = readFileSync(portFile, 'utf8').split('\n')[0];
    const targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
    socket = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let id = 0;
    const pending = new Map();
    socket.onmessage = ({ data }) => { const message = JSON.parse(data); const task = pending.get(message.id); if (!task) return; pending.delete(message.id); if (message.error) task.reject(Error(message.error.message)); else task.resolve(message.result); };
    const call = (method, params = {}) => new Promise((resolve, reject) => { const requestId = ++id; pending.set(requestId, { resolve, reject }); socket.send(JSON.stringify({ id: requestId, method, params })); });
    const evaluate = async expression => { const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (result.exceptionDetails) throw Error(result.exceptionDetails.text); return result.result.value; };
    await call('Page.enable');
    for (const width of [320, 360, 390, 768, 1440]) {
        const mobile = width < 768;
        await call('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 1, mobile });
        await call('Page.navigate', { url: pathToFileURL(fixture).href });
        let ready = false;
        for (let i = 0; i < 60; i++) { ready = await evaluate(`document.querySelector('table')?.classList.contains('mobile-card-table')`); if (ready) break; await pause(50); }
        check(ready, `Enhancements initialized at ${width}px`);
        const metrics = await evaluate(`({ width: innerWidth, scroll: document.documentElement.scrollWidth, display: getComputedStyle(document.querySelector('tbody tr')).display, inputSize: getComputedStyle(document.querySelector('[name=search]')).fontSize, buttonHeight: document.querySelector('.table-toolbar-actions button').getBoundingClientRect().height, labels: [...document.querySelector('table').querySelectorAll('tbody td')].map(cell => cell.dataset.mobileLabel), toggles: document.querySelectorAll('.mobile-filter-toggle').length, activeFilters: document.getElementById('active-filters').querySelector('button.mobile-filter-toggle').getAttribute('aria-expanded') })`);
        check(metrics.scroll <= width + 1, `No page overflow at ${width}px (${metrics.scroll})`);
        check(metrics.labels.join('|') === 'Request|Supplier|Amount|Actions', 'Card fields retain column labels');
        check(metrics.toggles === 2, 'Each filter form initializes exactly once');
        check(metrics.activeFilters === 'true', 'Existing filters remain visible');
        if (mobile) {
            check(metrics.display === 'grid', 'Rows stack as cards');
            check(metrics.inputSize === '16px', 'Inputs avoid phone auto zoom');
            check(metrics.buttonHeight >= 44, 'Filter button has a touch-sized target');
            check(await evaluate(`document.querySelector('.mobile-bulk-select').getBoundingClientRect().height >= 44`), 'Select all remains visible and touch sized');
            await evaluate(`document.querySelector('.mobile-select-all-label').click()`);
            check(await evaluate(`document.querySelector('.bulk-row').checked`), 'Select all keeps its original selection behavior');
            check(await evaluate(`getComputedStyle(document.querySelector('form.table-toolbar .table-toolbar-dates')).display === 'none'`), 'Advanced filters start collapsed');
            await evaluate(`document.querySelector('.mobile-filter-toggle').click()`);
            check(await evaluate(`getComputedStyle(document.querySelector('form.table-toolbar .table-toolbar-dates')).display !== 'none'`), 'Filter toggle reveals date controls');
            check(await evaluate(`[...document.querySelectorAll('.table-toolbar-dates input')].every(el=>el.getBoundingClientRect().width > 100)`), 'Date fields remain wide enough to use');
            await evaluate(`document.querySelector('[name=status]').value='pending'; document.querySelector('form.table-toolbar .table-toolbar-actions button').click()`);
            check(await evaluate(`fixtureSubmits()===1 && new FormData(document.querySelector('form.table-toolbar')).get('status')==='pending'`), 'Filtering retains the original form and values');
            await evaluate(`document.querySelector('.workspace-menu-toggle').click()`); await pause(400);
            check(await evaluate(`document.getElementById('workspaceSidebar').classList.contains('show')`), 'Navigation drawer opens');
            await evaluate(`bootstrap.Offcanvas.getInstance(document.getElementById('workspaceSidebar')).hide()`); await pause(400);
            await evaluate(`document.getElementById('open-workflow').click()`); await pause(400);
            const modal = await evaluate(`({ width: document.querySelector('#workflow-action-modal .modal-content').getBoundingClientRect().width, height: document.querySelector('#workflow-action-modal .modal-content').getBoundingClientRect().height, expand: getComputedStyle(document.querySelector('[data-workflow-action-expand]')).display, frameHeight: document.querySelector('iframe').getBoundingClientRect().height })`);
            check(Math.abs(modal.width - width) < 2 && modal.height === 844, 'Action window fills the phone viewport');
            check(modal.expand === 'none' && modal.frameHeight > 650, 'Mobile header stays simple and leaves room for the form');
            if (width === 390) { const screenshot = await call('Page.captureScreenshot', { format: 'png' }); writeFileSync(path.join(artifacts, 'mobile-workflow-action.png'), Buffer.from(screenshot.data, 'base64')); }
            await evaluate(`bootstrap.Modal.getInstance(document.getElementById('workflow-action-modal')).hide()`); await pause(400);
        } else check(metrics.display === 'table-row', 'Desktop and tablet retain table layout');
        await evaluate(`const row=document.querySelector('tbody tr').cloneNode(true); row.querySelector('td strong').textContent='REQ00138'; document.querySelector('tbody').append(row);`); await pause(50);
        check(await evaluate(`document.querySelector('tbody tr:last-child td').dataset.mobileLabel==='Request'`), 'Dynamically added rows keep readable labels');
        await evaluate(`document.querySelector('main').insertAdjacentHTML('beforeend', '<section class="card filter-panel"><div class="card-body"><form class="row g-2 request-list-filters"><div class="request-filter-search"><input class="form-control" name="search" aria-label="Search requests"></div><div class="request-filter-advanced"><select class="form-select" name="priority" aria-label="Priority"><option value="">All priorities</option><option value="HIGH">High</option></select></div><div class="request-filter-actions d-flex gap-2"><button class="btn btn-primary">Apply filters</button><a class="btn btn-outline-secondary" href="#">Clear</a></div></form></div></section>')`); await pause(80);
        check(await evaluate(`document.querySelectorAll('.request-list-filters .mobile-filter-toggle').length === 1`), 'Request list filters initialize once after loading');
        if (mobile) {
            check(await evaluate(`document.querySelector('.request-list-filters').getBoundingClientRect().height < 150`), 'Request list search and buttons stay compact');
            check(await evaluate(`getComputedStyle(document.querySelector('.request-filter-advanced')).display === 'none'`), 'Request list advanced filters start hidden');
            await evaluate(`document.querySelector('.request-list-filters .mobile-filter-toggle').click()`);
            check(await evaluate(`getComputedStyle(document.querySelector('.request-filter-advanced')).display !== 'none'`), 'Request list filters expand on demand');
        } else check(await evaluate(`getComputedStyle(document.querySelector('.request-filter-advanced')).display !== 'none'`), 'Desktop request filters remain available');
        await evaluate(`window.scrollTo(0,0)`); await pause(80);
        if (width === 390 || width === 1440) { const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true }); writeFileSync(path.join(artifacts, `mobile-workflow-${width}.png`), Buffer.from(screenshot.data, 'base64')); }
    }
    console.log(`Mobile workflow: ${assertions} browser assertions passed at 320, 360, 390, 768 and 1440px.`);
} finally {
    socket?.close();
    browser.kill();
    await new Promise(resolve => { if (browser.exitCode !== null) resolve(); else browser.once('exit', resolve); });
    assert.ok(path.resolve(profile).startsWith(path.resolve(artifacts) + path.sep));
    rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 300 });
}
