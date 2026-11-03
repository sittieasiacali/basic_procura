import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';

const baseUrl = process.env.E2E_BASE_URL ?? 'http://localhost/basic%20procura/public';
const debugUrl = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9223';
const email = process.env.E2E_EMAIL ?? 'admin@example.com';
const password = process.env.E2E_PASSWORD ?? 'Procura123!';
const baseline = process.env.E2E_BASELINE === '1';
const desktopHeight = Number(process.env.E2E_VIEWPORT_HEIGHT ?? 900);

const targetResponse = await fetch(`${debugUrl}/json/new?about:blank`, { method: 'PUT' });
assert.ok(targetResponse.ok, 'Chrome debugging endpoint is unavailable');
const target = await targetResponse.json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
const pending = new Map();
let nextId = 0;

await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});
socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
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
const waitUntil = async (condition, label) => {
    for (let attempt = 0; attempt < 100; attempt += 1) {
        if (await condition()) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    throw new Error(`Timed out waiting for ${label}`);
};
const waitForPath = (suffix) => waitUntil(
    () => evaluate(`location.pathname.endsWith(${JSON.stringify(suffix)}) && document.readyState === 'complete'`),
    suffix,
);
const screenshot = async (name) => {
    await evaluate('document.fonts.ready');
    const capture = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    await writeFile(`storage/app/${name}.png`, Buffer.from(capture.data, 'base64'));
};
const measure = () => evaluate(`(() => {
    const rect = (selector) => {
        const box = document.querySelector(selector).getBoundingClientRect();
        return { x: box.x, y: box.y, width: box.width, height: box.height, right: box.right };
    };
    return {
        viewport: innerWidth,
        scrollWidth: document.documentElement.scrollWidth,
        navbar: rect('.app-navbar'),
        logo: rect('.app-brand-logo'),
        logoLoaded: document.querySelector('.app-brand-logo').naturalWidth > 0,
        logoFit: getComputedStyle(document.querySelector('.app-brand-logo')).objectFit,
        logoBackground: getComputedStyle(document.querySelector('.app-brand-logo')).backgroundColor,
        navbarBackground: getComputedStyle(document.querySelector('.app-navbar')).backgroundImage,
        primaryLinks: [...document.querySelectorAll('.navbar-nav > .nav-item > .nav-link')].map((link) => link.textContent.trim()),
        historyButtons: [...document.querySelectorAll('.dashboard-history-button')].map((button) => button.getAttribute('aria-label')),
        kpiLabels: [...document.querySelectorAll('.dashboard-kpi .kpi-label')].map((label) => label.textContent.trim()),
        pageTitle: document.querySelector('.dashboard-page-header h1')?.textContent.trim(),
    };
})()`);

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');
    await send('Network.clearBrowserCookies');
    await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: desktopHeight, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: `${baseUrl}/login` });
    await waitForPath('/login');
    await evaluate(`(() => {
        document.querySelector('#email').value = ${JSON.stringify(email)};
        document.querySelector('#password').value = ${JSON.stringify(password)};
        document.querySelector('form').requestSubmit();
    })()`);
    await waitForPath('/dashboard');
    const initial = await measure();
    assert.ok(initial.logoLoaded, 'navbar logo failed to load');
    await screenshot(baseline ? 'dashboard-header-before' : 'dashboard-header-desktop');
    console.log(`DASHBOARD_HEADER ${JSON.stringify(initial)}`);
    if (baseline) process.exitCode = 0;
    else {
        assert.ok(initial.primaryLinks.includes('Dashboard') && initial.primaryLinks.includes('Requests'));
        assert.ok(initial.primaryLinks.some((link) => link.includes('Workflows')));
        assert.deepEqual(initial.historyButtons, ['Back', 'Forward']);
        assert.equal(initial.logoFit, 'contain');
        assert.equal(initial.logoBackground, 'rgba(0, 0, 0, 0)');
        assert.ok(initial.navbarBackground.includes('gradient'));
        assert.ok(initial.navbar.height <= 75, 'navbar height increased too much');
        assert.ok(Math.abs((initial.logo.y + initial.logo.height / 2) - initial.navbar.height / 2) < 1, 'logo is not vertically centered');
        assert.ok(initial.scrollWidth <= 1366, 'desktop horizontal overflow');

        await evaluate("document.querySelector('.user-menu-button').click()");
        assert.equal(await evaluate("document.querySelector('.user-menu-button').getAttribute('aria-expanded')"), 'true');
        await evaluate("document.querySelector('.user-menu-button').click()");
        await evaluate("document.querySelector('.navbar-tool[aria-label=Notifications]').click()");
        assert.equal(await evaluate("document.querySelector('.navbar-tool[aria-label=Notifications]').getAttribute('aria-expanded')"), 'true');
        await evaluate("document.querySelector('.navbar-nav .dropdown-toggle').click()");
        assert.equal(await evaluate("document.querySelector('.navbar-nav .dropdown-toggle').getAttribute('aria-expanded')"), 'true');
        const rowMenu = await evaluate("document.querySelector('.dashboard-row-menu-button') !== null");
        if (rowMenu) {
            await evaluate("document.querySelector('.dashboard-row-menu-button').click()");
            assert.equal(await evaluate("document.querySelector('.dashboard-row-menu-button').getAttribute('aria-expanded')"), 'true');
            assert.equal(await evaluate("document.querySelector('.dashboard-action-table tbody tr:first-child .dropdown-item').href === document.querySelector('.dashboard-action-table tbody tr:first-child .request-number').href"), true);
            await screenshot('dashboard-row-menu-open');
            await evaluate("document.querySelector('.dashboard-row-menu-button').click()");
            const lastRowMenu = await evaluate("document.querySelector('.dashboard-action-table tbody tr:last-child .dashboard-row-menu-button') !== null");
            if (lastRowMenu) {
                await evaluate("document.querySelector('.dashboard-action-table tbody tr:last-child .dashboard-row-menu-button').click()");
                assert.equal(await evaluate("document.querySelector('.dashboard-action-table tbody tr:last-child .dashboard-row-menu-button').getAttribute('aria-expanded')"), 'true');
                assert.equal(await evaluate("document.querySelector('.dashboard-action-table tbody tr:last-child .dropdown').classList.contains('dropup')"), true);
            }
        }

        await evaluate("document.querySelector('.navbar-nav a[href$=purchase-requests]').click()");
        await waitForPath('/purchase-requests');
        await evaluate("document.querySelector('.navbar-nav a[href$=dashboard]').click()");
        await waitForPath('/dashboard');
        await evaluate("document.querySelector('[data-history-back]').click()");
        await waitForPath('/purchase-requests');
        await evaluate('history.back()');
        await waitForPath('/dashboard');
        await evaluate("document.querySelector('[data-history-forward]').click()");
        await waitForPath('/purchase-requests');
        console.log('DASHBOARD_HISTORY_OK');

        const workspacePages = [
            { name: 'requests', url: '/purchase-requests' },
            { name: 'workflow', url: '/request-flow?stage=purchase-order' },
            { name: 'budget', url: '/budget' },
            { name: 'accounts', url: '/admin/accounts' },
            { name: 'canvassing', url: '/admin/campaigns' },
        ];
        for (const page of workspacePages) {
            const pathname = new URL(page.url, baseUrl).pathname;
            await send('Page.navigate', { url: `${baseUrl}${page.url}` });
            await waitForPath(pathname);
            const pageMetrics = await evaluate(`(() => ({
                title: document.querySelector('h1')?.textContent.trim(),
                cards: document.querySelectorAll('.card').length,
                scrollWidth: document.documentElement.scrollWidth,
                viewport: innerWidth,
            }))()`);
            assert.ok(pageMetrics.title, `${page.name} page has no visible heading`);
            assert.ok(pageMetrics.cards > 0, `${page.name} page has no card surface`);
            assert.ok(pageMetrics.scrollWidth <= pageMetrics.viewport, `${page.name} page has desktop horizontal overflow`);
            await screenshot(`system-ui-${page.name}-desktop`);
            console.log(`SYSTEM_UI_DESKTOP_OK ${page.name} ${JSON.stringify(pageMetrics)}`);
        }

        for (const width of [768, 390, 320]) {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: true });
            await send('Page.navigate', { url: `${baseUrl}/dashboard` });
            await waitForPath('/dashboard');
            const metrics = await measure();
            assert.ok(metrics.scrollWidth <= width, `${width}px horizontal overflow`);
            assert.deepEqual(metrics.historyButtons, ['Back', 'Forward']);
            assert.ok(metrics.logoLoaded);
            await screenshot(`dashboard-header-${width}`);
            await evaluate("document.querySelector('.navbar-toggler').click()");
            assert.equal(await evaluate("document.querySelector('.navbar-toggler').getAttribute('aria-expanded')"), 'true');
            await waitUntil(() => evaluate("document.querySelector('.navbar-collapse').classList.contains('show')"), 'expanded navbar');
            await evaluate("document.querySelector('.user-menu-button').click()");
            assert.equal(await evaluate("document.querySelector('.user-menu-button').getAttribute('aria-expanded')"), 'true');
            assert.ok(await evaluate("document.querySelector('.user-menu-button + .dropdown-menu').getBoundingClientRect().right <= innerWidth"), 'profile menu overflows viewport');
            console.log(`DASHBOARD_RESPONSIVE_OK ${JSON.stringify(metrics)}`);
        }

        await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: true });
        await send('Page.navigate', { url: `${baseUrl}/purchase-requests` });
        await waitForPath('/purchase-requests');
        const mobileRequestMetrics = await evaluate(`(() => ({
            title: document.querySelector('h1')?.textContent.trim(),
            cards: document.querySelectorAll('.card').length,
            scrollWidth: document.documentElement.scrollWidth,
            viewport: innerWidth,
        }))()`);
        assert.ok(mobileRequestMetrics.title, 'mobile requests page has no visible heading');
        assert.ok(mobileRequestMetrics.scrollWidth <= mobileRequestMetrics.viewport, 'mobile requests page has horizontal overflow');
        await screenshot('system-ui-requests-mobile');
        console.log(`SYSTEM_UI_MOBILE_OK ${JSON.stringify(mobileRequestMetrics)}`);
    }
} finally {
    socket.close();
    await fetch(`${debugUrl}/json/close/${target.id}`).catch(() => {});
}
