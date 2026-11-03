const baseUrl = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const debugUrl = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9222';
const password = process.env.E2E_PASSWORD ?? 'Procura123!';
const marker = `EDGE-WORKFLOW-${Date.now()}`;
const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function waitUntil(callback, description) {
    for (let attempt = 0; attempt < 200; attempt += 1) {
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

async function createRequest() {
    await navigate('/purchase-requests/create');
    const requiredDate = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
    await evaluate(`(() => {
        const set = (selector, value) => {
            const input = document.querySelector(selector);
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        };
        document.querySelectorAll('[data-remove-item]')[2].click();
        set('#title', ${JSON.stringify(`${marker} IT Procurement Request`)});
        set('#department_campaign', 'Operations');
        set('#request_type', 'IT');
        set('#required_date', ${JSON.stringify(requiredDate)});
        set('#delivery_location', 'Main Office');
        set('#purpose', 'End-to-end browser verification for the procurement workflow.');
        set('[name="items[0][description]"]', 'Laptop');
        set('[name="items[0][quantity]"]', '2');
        set('[name="items[0][unit]"]', 'unit');
        set('[name="items[0][estimated_unit_price]"]', '100');
        set('[name="items[0][reference_url]"]', 'https://example.test/laptop');
        set('[name="items[1][description]"]', 'Monitor');
        set('[name="items[1][quantity]"]', '2');
        set('[name="items[1][unit]"]', 'piece');
        set('[name="items[1][estimated_unit_price]"]', '200');
        document.querySelector('#add-item').click();
        set('[name="items[3][description]"]', 'Docking Station');
        set('[name="items[3][quantity]"]', '1');
        set('[name="items[3][unit]"]', 'piece');
        set('[name="items[3][estimated_unit_price]"]', '150');
        set('[name="items[3][reference_url]"]', 'https://example.test/docking');
        const file = new File(['%PDF-1.4 browser verification'], 'request-specification.pdf', { type: 'application/pdf' });
        const transfer = new DataTransfer();
        transfer.items.add(file);
        document.querySelector('[name="items[0][attachment]"]').files = transfer.files;
        window.confirm = () => true;
        document.querySelector('button[name="action"][value="submit"]').click();
        return true;
    })()`);
    await waitUntil(async () => /\/purchase-requests\/\d+$/.test(await pathName()), 'submitted request detail');
    return await pathName();
}

await send('Page.enable');
await send('Runtime.enable');
await send('Network.enable');
await send('Network.clearBrowserCookies');

let requestPath;

try {
    await login('teamleader@example.com');
    requestPath = await createRequest();
    await logout();

    await login('approver@example.com');
    await navigate(requestPath);
    await evaluate(`(() => {
        document.querySelector('form[action$="/approve"]').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('For SMT Approval'), 'OM approval');
    await logout();

    await login('smt@example.com');
    await navigate(requestPath);
    await evaluate(`(() => {
        const form = document.querySelector('form[action$="/approve"]');
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => {
        const text = await bodyText();
        return text.includes('For It Screening')
            || text.includes('IT Screening')
            || text.includes('team screening')
            || text.includes('For Premises Screening')
            || text.includes('Procurement');
    }, 'SMT routed request to screening');
    await logout();

    await login('it@example.com');
    await navigate(requestPath);
    await evaluate(`(() => {
        const trigger = document.querySelector('button[data-bs-target="#unavailableModal"]');
        if (trigger) trigger.click();
        const modalForm = document.querySelector('#unavailableModal form');
        const evidence = document.getElementById('unavailable-evidence');
        evidence.value = 'Item not available in IT inventory.';
        const file = new File(['IT inventory verification'], 'inventory-evidence.pdf', { type: 'application/pdf' });
        const transfer = new DataTransfer();
        transfer.items.add(file);
        document.getElementById('quotation-evidence').files = transfer.files;
        modalForm.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('Canvassing') || (await bodyText()).includes('Procurement'), 'screening routed to procurement');
    await logout();

    await login('purchasing@example.com');
    await navigate(requestPath);
    await evaluate(`(() => {
        const addButton = document.getElementById('add-quotation');
        while (document.querySelectorAll('[data-quotation-row]').length < 3) {
            addButton.click();
        }
        const quoteRows = [...document.querySelectorAll('[data-quotation-row]')];
        quoteRows.forEach((row, index) => {
            row.querySelector('[name$="[supplier_name]"]').value = 'Supplier ' + (index + 1);
            row.querySelector('[name$="[quoted_amount]"]').value = String(1000 + (index * 500));
            row.querySelector('[name$="[delivery_lead_time]"]').value = (index + 2) + ' weeks';
            row.querySelector('[name$="[warranty]"]').value = '12 months';
            row.querySelector('[name$="[quotation_url]"]').value = 'https://example.test/' + (index + 1);
            row.querySelector('[name$="[remarks]"]').value = 'Competitive pricing';
            if (index === 0) {
                row.querySelector('[name$="[recommended]"]').checked = true;
            }
        });
        document.querySelector('form[action$="/quotations"]').requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('President Supplier Review') || (await bodyText()).includes('Supplier Review'), 'supplier review stage');
    await logout();

    await login('president@example.com');
    await navigate(requestPath);
    await evaluate(`(() => {
        const form = document.querySelector('form[action$="/supplier-decision"]');
        const select = form.querySelector('#supplier_id');
        select.value = select.options[1].value;
        form.querySelector('textarea[name="remarks"]').value = 'Approved supplier after review.';
        form.querySelector('button[name="decision"][value="APPROVE"]').click();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('Budget Maker'), 'president supplier approval');
    await logout();

    await login('purchasing@example.com');
    await navigate(requestPath);
    await evaluate(`(() => {
        const form = document.querySelector('form[action*="/purchase-orders/"]');
        form.querySelector('textarea[name="remarks"]').value = 'Purchase Order created for budget processing.';
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('Budget Request / Budget Maker'), 'purchase order generation');
    await logout();

    await login('budget-maker@example.com');
    await navigate(requestPath);
    await evaluate(`(() => {
        const form = document.querySelector('form[action$="/maker"]');
        form.querySelector('textarea[name="remarks"]').value = 'Budget approved by maker.';
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('Budget Checker'), 'budget maker stage complete');
    await logout();

    await login('budget-checker@example.com');
    await navigate(requestPath);
    await evaluate(`(() => {
        const form = document.querySelector('form[action$="/checker"]');
        form.querySelector('textarea[name="remarks"]').value = 'Budget validated by checker.';
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('President Final Approval'), 'budget checker routed to president final approval');
    await logout();

    await login('president@example.com');
    await navigate(requestPath);
    await evaluate(`(() => {
        const form = document.querySelector('form[action$="/final-approval"]');
        form.querySelector('textarea[name="remarks"]').value = 'Approved for payment.';
        form.querySelector('button[name="decision"][value="APPROVE"]').click();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('Payment'), 'president final approval granted');
    await logout();

    await login('finance-payment@example.com');
    await navigate(requestPath);
    const requestId = requestPath.split('/').pop();
    await evaluate(`(() => {
        const form = document.querySelector('form[action$="/payments/${requestId}"]');
        form.querySelector('select[name="payment_method"]').value = 'ONLINE';
        form.querySelector('input[name="amount"]').value = '1000';
        form.querySelector('input[name="reference_number"]').value = 'REF-1001';
        form.querySelector('textarea[name="details"]').value = 'Payment recorded by browser verification.';
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('Receiving') || (await bodyText()).includes('Record Receiving'), 'payment moved to receiving');
    await logout();

    await login('inventory-clerk@example.com');
    await navigate(requestPath);
    await evaluate(`(() => {
        const form = document.querySelector('form[action$="/receiving/${requestId}"]');
        form.querySelector('input[name="received_at"]').value = new Date().toISOString().slice(0, 10);
        form.querySelector('input[name="quantity"]').value = '2';
        form.querySelector('input[name="description"]').value = 'Laptop equipment received';
        form.querySelector('input[name="unit"]').value = 'unit';
        form.querySelector('input[name="serial_number"]').value = 'SN-1001';
        form.querySelector('input[name="location"]').value = 'Inventory Room';
        form.querySelector('textarea[name="remarks"]').value = 'Inventory logged via browser verification.';
        form.requestSubmit();
        return true;
    })()`);
    await waitUntil(async () => (await bodyText()).includes('Completed'), 'request completion');

    const finalState = await bodyText();
    if (! finalState.includes('Completed')) throw new Error('Request did not reach the Completed stage after the full workflow.');

    console.log(JSON.stringify({
        result: 'EDGE_PROCUREMENT_WORKFLOW_OK',
        marker,
        requestPath,
        verified: [
            'team-leader-submission', 'om-approval', 'smt-screening-routing', 'inventory-unavailable-screening',
            'quotation-capturing', 'president-supplier-selection', 'budget-maker', 'budget-checker',
            'president-final-approval', 'payment', 'receiving', 'completed'
        ],
    }));
} finally {
    socket.close();
    await fetch(`${debugUrl}/json/close/${target.id}`).catch(() => {});
}
