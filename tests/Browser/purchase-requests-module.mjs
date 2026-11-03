const baseUrl = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const debugUrl = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9222';
const password = process.env.E2E_PASSWORD ?? 'Procura123!';
const marker = `EDGE-PR-${Date.now()}`;
const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function waitUntil(callback, description) {
    for (let attempt = 0; attempt < 160; attempt += 1) {
        try {
            if (await callback()) return;
        } catch {
            // Navigation temporarily replaces the execution context.
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

async function login(email) {
    await navigate('/login');
    await evaluate(`(() => {
        document.querySelector('#email').value = ${JSON.stringify(email)};
        document.querySelector('#password').value = ${JSON.stringify(password)};
        document.querySelector('form').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await pathName()) === '/dashboard', `${email} dashboard`);
}

async function logout() {
    await evaluate(`(() => {
        document.querySelector('form[action$="/logout"]').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await pathName()) === '/login', 'logout');
}

async function assertNoBrokenUi() {
    const result = await evaluate(`(() => ({
        rawSvg: /svg(?:Add|Attachments|Links)/i.test(document.body.innerText),
        rawMojibake: /â‚±|Â·/.test(document.body.innerText),
        icons: document.querySelectorAll('svg[aria-hidden="true"]').length,
        blankLabels: [...document.querySelectorAll('label')].filter(label => ! label.innerText.trim()).length,
    }))()`);
    if (result.rawSvg || result.rawMojibake || result.blankLabels) throw new Error(`Broken UI text detected: ${JSON.stringify(result)}`);
    if (result.icons < 2) throw new Error('Expected inline SVG icons were not rendered.');
}

await send('Page.enable');
await send('Runtime.enable');
await send('Network.enable');
await send('Network.clearBrowserCookies');

let requestPath;
let requestNumber;

try {
    await login('teamleader@example.com');
    await navigate('/purchase-requests');
    const listState = await evaluate(`(() => ({
        title: document.querySelector('h1')?.innerText,
        description: [...document.querySelectorAll('p')].map(node => node.innerText),
        headers: [...document.querySelectorAll('thead th')].map(node => node.innerText.trim()),
        cards: [...document.querySelectorAll('[data-summary-count]')].map(node => node.dataset.summaryCount),
        createVisible: [...document.querySelectorAll('a')].some(link => link.innerText.includes('Create Purchase Request')),
    }))()`);
    const expectedHeaders = ['RID', 'CAMPAIGN', 'DATE REQUESTED', 'ITEMS', 'STATUS', 'OM REVIEW', 'SMT APPROVAL', 'AMOUNT', 'CURRENT STAGE', 'UPDATED', 'ACTION'];
    if (listState.title !== 'Purchase Requests') throw new Error('Purchase Requests heading does not match the target layout.');
    if (JSON.stringify(listState.headers) !== JSON.stringify(expectedHeaders)) throw new Error(`Unexpected table columns: ${JSON.stringify(listState.headers)}`);
    if (JSON.stringify(listState.cards) !== JSON.stringify(['all', 'pending', 'approved', 'disapproved']) || ! listState.createVisible) throw new Error('Summary cards or create action are missing.');
    await assertNoBrokenUi();

    await navigate('/purchase-requests/create');
    const initialForm = await evaluate(`(() => ({
        rows: document.querySelectorAll('[data-item-row]').length,
        count: document.querySelector('#item-count').innerText,
        supplierField: Boolean(document.querySelector('[name="supplier_id"], [name^="supplier"]')),
        supplierNotice: document.body.innerText.includes('Supplier selection happens during Canvassing.'),
        attachment: Boolean(document.querySelector('[name="items[0][attachment]"]')),
        link: Boolean(document.querySelector('[name="items[0][reference_url]"]')),
    }))()`);
    if (initialForm.rows !== 1 || initialForm.count !== '1') throw new Error('Create form did not start with one unrestricted item row.');
    if (initialForm.supplierField || ! initialForm.supplierNotice) throw new Error('Initial request supplier handling violates the Canvassing rule.');
    if (! initialForm.attachment || ! initialForm.link) throw new Error('Attachment or link fields are missing.');
    await assertNoBrokenUi();

    for (const viewport of [
        { name: 'desktop', width: 1440, height: 900 },
        { name: 'tablet', width: 900, height: 1000 },
        { name: 'mobile', width: 390, height: 844 },
    ]) {
        await send('Emulation.setDeviceMetricsOverride', { width: viewport.width, height: viewport.height, deviceScaleFactor: 1, mobile: viewport.name === 'mobile' });
        await delay(120);
        const responsiveState = await evaluate(`(() => {
            const items = document.querySelector('.items-builder-card').getBoundingClientRect();
            const summary = document.querySelector('.builder-summary-card').getBoundingClientRect();
            const action = document.querySelector('.builder-action-bar').getBoundingClientRect();
            return {
                innerWidth: window.innerWidth,
                clientWidth: document.documentElement.clientWidth,
                scrollWidth: document.documentElement.scrollWidth,
                items: { left: items.left, right: items.right, top: items.top, bottom: items.bottom },
                summary: { left: summary.left, right: summary.right, top: summary.top, bottom: summary.bottom },
                overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
                controlsOutsideViewport: [...document.querySelectorAll('#purchase-request-form input, #purchase-request-form select, #purchase-request-form textarea, #purchase-request-form button')].some(control => {
                    const rect = control.getBoundingClientRect();
                    return rect.width > 0 && (rect.left < -1 || rect.right > window.innerWidth + 1);
                }),
                sideBySide: summary.left >= items.right - 2,
                stacked: summary.top >= items.bottom - 2,
                actionVisible: action.width > 0 && action.height > 0,
                mobileMenu: getComputedStyle(document.querySelector('.navbar-toggler')).display !== 'none',
            };
        })()`);
        if (responsiveState.overflow || responsiveState.controlsOutsideViewport || ! responsiveState.actionVisible) throw new Error(`${viewport.name} layout overflows or hides controls: ${JSON.stringify(responsiveState)}`);
        if (viewport.name === 'desktop' && ! responsiveState.sideBySide) throw new Error('Desktop request summary is not beside the item workspace.');
        if (viewport.name !== 'desktop' && ! responsiveState.stacked) throw new Error(`${viewport.name} request summary does not stack below the item workspace.`);
        if (viewport.name === 'mobile' && ! responsiveState.mobileMenu) throw new Error('Mobile navigation toggle is not available.');
    }
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

    await evaluate(`document.querySelector('#add-item').click()`);
    await evaluate(`document.querySelectorAll('[data-remove-item]')[1].click()`);
    const removedState = await evaluate(`(() => ({
        count: document.querySelectorAll('[data-item-row]').length,
        warningVisible: ! document.querySelector('#items-validity-feedback').classList.contains('d-none'),
        undoVisible: ! document.querySelector('#item-undo-toast').hidden,
        submitDisabled: document.querySelector('button[name="action"][value="submit"]').disabled,
    }))()`);
    if (removedState.count !== 1 || ! removedState.warningVisible || ! removedState.undoVisible || ! removedState.submitDisabled) throw new Error(`Item removal state is incorrect: ${JSON.stringify(removedState)}`);
    await evaluate(`document.querySelector('#undo-remove-item').click()`);
    const restoredState = await evaluate(`(() => ({
        count: document.querySelectorAll('[data-item-row]').length,
        numbers: [...document.querySelectorAll('.item-number-chip')].map(node => node.textContent.trim()),
        undoHidden: document.querySelector('#item-undo-toast').hidden,
    }))()`);
    if (restoredState.count !== 2 || JSON.stringify(restoredState.numbers) !== JSON.stringify(['1', '2']) || ! restoredState.undoHidden) throw new Error(`Item undo or renumbering failed: ${JSON.stringify(restoredState)}`);

    const requiredDate = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
    await evaluate(`(() => {
        const set = (selector, value) => {
            const input = document.querySelector(selector);
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        };
        document.querySelectorAll('[data-remove-item]')[1].click();
        set('#title', ${JSON.stringify(`${marker} Laptop Equipment`)});
        set('#department_campaign', 'Operations');
        set('#required_date', ${JSON.stringify(requiredDate)});
        set('#delivery_location', 'Main Office');
        set('#purpose', 'Purchase Requests module browser verification.');
        set('[name="items[0][description]"]', 'Business laptop');
        set('[name="items[0][quantity]"]', '2');
        set('[name="items[0][unit]"]', 'pc');
        set('[name="items[0][estimated_unit_price]"]', '100');
        set('[name="items[0][reference_url]"]', 'https://example.test/laptop');
        const file = new File(['%PDF-1.4 browser verification'], 'request-specification.pdf', { type: 'application/pdf' });
        const transfer = new DataTransfer();
        transfer.items.add(file);
        document.querySelector('[name="items[0][attachment]"]').files = transfer.files;
        return true;
    })()`);
    const totalState = await evaluate(`(() => ({
        count: document.querySelector('#item-count').innerText,
        itemTotals: [...document.querySelectorAll('.item-total')].map(output => output.textContent.trim()),
        grandTotal: document.querySelector('#request-total').innerText,
        warningHidden: document.querySelector('#items-validity-feedback').classList.contains('d-none'),
        submitEnabled: ! document.querySelector('button[name="action"][value="submit"]').disabled,
    }))()`);
    if (totalState.count !== '1' || JSON.stringify(totalState.itemTotals) !== JSON.stringify(['200.00']) || totalState.grandTotal !== '200.00' || ! totalState.warningHidden || ! totalState.submitEnabled) throw new Error(`Single-item submission state or totals are incorrect: ${JSON.stringify(totalState)}`);

    await evaluate(`(() => {
        window.confirm = () => true;
        document.querySelector('button[name="action"][value="submit"]').click();
        return true;
    })()`);
    await waitUntil(async () => /\/purchase-requests\/\d+$/.test(await pathName()), 'submitted request detail');
    requestPath = await pathName();
    requestNumber = await evaluate("document.querySelector('.request-breadcrumb [aria-current=page]').innerText");
    if (! /^REQ\d{5}(?:[A-Z]{2})?$/.test(requestNumber)) throw new Error(`Unexpected request number ${requestNumber}.`);
    const detailText = await bodyText();
    if (! detailText.includes('For Om Approval') || ! detailText.includes('200.00') || ! detailText.includes('request-specification.pdf')) throw new Error('Submitted request details are incomplete.');
    if (detailText.includes('Proceed to SMT') || detailText.includes('Approve for Finance')) throw new Error('Team Leader can see an approval action on their own request.');

    await navigate('/purchase-requests');
    const requestorList = await bodyText();
    for (const value of [requestNumber, 'Business laptop', 'Pending', 'Operations']) {
        if (! requestorList.includes(value)) throw new Error(`Requestor list is missing ${value}.`);
    }
    await logout();

    await login('approver@example.com');
    await navigate(requestPath);
    const omReviewText = await bodyText();
    if (! omReviewText.includes('Operations Manager Review') || ! omReviewText.includes('Proceed to SMT') || ! omReviewText.includes('Edit Request')) throw new Error('Operations Manager review or edit actions are missing.');
    await evaluate(`(() => {
        document.querySelector('form[action$="/approve"]').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('For SMT Approval'), 'OM proceed to SMT');
    await logout();

    await login('teamleader@example.com');
    await navigate('/purchase-requests');
    const teamLeaderState = await evaluate(`(() => ({
        text: document.body.innerText,
        row: [...document.querySelectorAll('tbody tr')].find(row => row.innerText.includes(${JSON.stringify(requestNumber)}))?.innerText,
        rawSvg: /svg(?:Add|Attachments|Links)/i.test(document.body.innerText),
    }))()`);
    if (! teamLeaderState.row) throw new Error('Campaign-assigned Team Leader cannot see the submitted request.');
    if (! teamLeaderState.row.includes('Approved') || ! teamLeaderState.row.includes('Pending')) throw new Error(`Approval columns do not reflect actual records: ${teamLeaderState.row}`);
    if (teamLeaderState.rawSvg) throw new Error('Raw SVG text appeared for Team Leader.');
    await evaluate(`(() => {
        [...document.querySelectorAll('tbody tr')].find(row => row.innerText.includes(${JSON.stringify(requestNumber)})).querySelector('a').click();
        return true;
    })()`);
    await waitUntil(async () => (await pathName()) === requestPath, 'Team Leader request detail authorization');
    await logout();

    await login('it@example.com');
    await navigate('/request-flow?stage=it');
    const screeningQueue = await bodyText();
    if (! screeningQueue.includes(requestNumber) || ! screeningQueue.includes('Claim as IT')) throw new Error('IT did not receive the OM-approved request for classification.');
    await navigate(requestPath);
    const screeningDetail = await bodyText();
    if (! screeningDetail.includes('IT / Premises Classification') || ! screeningDetail.includes('Claim as IT')) throw new Error('IT/Premises classification actions are missing.');

    console.log(JSON.stringify({
        result: 'EDGE_PURCHASE_REQUESTS_MODULE_OK',
        marker,
        requestNumber,
        requestPath,
        verified: [
            'team-leader-login', 'target-list-layout', 'summary-cards', 'single-item-submission',
            'desktop-tablet-mobile-layout', 'remove-undo-renumbering', 'item-and-estimated-total-calculation', 'attachment-and-links', 'request-submission',
            'request-number-format', 'team-leader-ownership', 'team-leader-no-self-approval',
            'om-review-and-edit', 'om-proceed-to-smt', 'smt-queue-visibility',
            'database-approval-status', 'inline-svg-rendering', 'no-concatenated-labels',
        ],
    }));
} finally {
    socket.close();
    await fetch(`${debugUrl}/json/close/${target.id}`).catch(() => {});
}
