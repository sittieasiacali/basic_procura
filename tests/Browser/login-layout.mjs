import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';

const baseUrl = process.env.E2E_BASE_URL ?? 'http://localhost/basic%20procura/public';
const debugUrl = process.env.E2E_DEBUG_URL ?? 'http://127.0.0.1:9222';

const targetResponse = await fetch(`${debugUrl}/json/new?about:blank`, { method: 'PUT' });
assert.equal(targetResponse.ok, true, 'Chrome debugging endpoint is unavailable');
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

const waitUntil = async (condition, description) => {
    for (let attempt = 0; attempt < 60; attempt += 1) {
        if (await condition()) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    throw new Error(`Timed out waiting for ${description}`);
};

const inspect = () => evaluate(`(() => {
    const bounds = (selector) => {
        const rect = document.querySelector(selector).getBoundingClientRect();
        return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom, width: rect.width, height: rect.height };
    };
    return {
        viewport: innerWidth,
        scrollWidth: document.documentElement.scrollWidth,
        form: bounds('.auth-form-panel'),
        heading: bounds('#sign-in-title'),
        email: bounds('#email'),
        submit: bounds('.auth-submit'),
        page: bounds('.auth-page'),
        backgroundImage: getComputedStyle(document.querySelector('.auth-page')).backgroundImage,
        cardBackground: getComputedStyle(document.querySelector('.auth-form-panel')).backgroundImage,
        logoLoaded: document.querySelector('.auth-logo').naturalWidth > 0,
        headingFont: getComputedStyle(document.querySelector('h1')).fontFamily,
        headingSize: parseFloat(getComputedStyle(document.querySelector('h1')).fontSize),
        formFont: getComputedStyle(document.querySelector('#email')).fontFamily,
        rememberVisible: getComputedStyle(document.querySelector('.auth-remember')).display !== 'none',
    };
})()`);

try {
    await send('Page.enable');
    await send('Runtime.enable');

    for (const size of [
        { name: 'desktop', width: 1366, height: 768, mobile: false },
        { name: 'mobile', width: 390, height: 844, mobile: true },
    ]) {
        await send('Emulation.setDeviceMetricsOverride', {
            width: size.width,
            height: size.height,
            deviceScaleFactor: 1,
            mobile: size.mobile,
        });
        await send('Page.navigate', { url: `${baseUrl}/login` });
        await waitUntil(() => evaluate("document.readyState === 'complete' && !!document.querySelector('.auth-form-panel')"), 'login page');
        await evaluate('document.fonts.ready.then(() => Promise.all(Array.from(document.images, image => image.decode().catch(() => {}))))');
        const layout = await inspect();
        assert.equal(layout.viewport, size.width);
        assert.ok(layout.scrollWidth <= size.width, `${size.name} has horizontal overflow: ${JSON.stringify(layout)}`);
        assert.ok(layout.email.right <= size.width && layout.submit.right <= size.width, `${size.name} form overflows`);
        assert.ok(layout.logoLoaded, `${size.name} logo asset failed to load`);
        assert.ok(layout.backgroundImage.includes('Allianz.png'), `${size.name} building background is missing`);
        assert.ok(layout.cardBackground.includes('gradient'), `${size.name} glass card styling is missing`);
        assert.ok(layout.headingFont.includes('Cardo') && layout.formFont.includes('Open Sans'));
        assert.ok(layout.headingSize >= 32 && layout.headingSize <= 40, `${size.name} heading is out of range`);
        assert.ok(layout.email.height >= 46 && layout.email.height <= 50, `${size.name} input height is out of range`);
        assert.ok(layout.submit.height >= 46 && layout.submit.height <= 50, `${size.name} button height is out of range`);
        assert.equal(layout.rememberVisible, true);
        if (! size.mobile) {
            assert.ok(layout.form.width >= 430 && layout.form.width <= 470, 'desktop form width is out of range');
            assert.ok(Math.abs((layout.form.top + layout.form.bottom) / 2 - size.height / 2) < 10, 'desktop form is not vertically centered');
            assert.ok(Math.abs((layout.form.left + layout.form.right) / 2 - size.width / 2) < 10, 'desktop form is not horizontally centered');
        }
        const screenshot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        await writeFile(`storage/app/login-${size.name}-verified.png`, Buffer.from(screenshot.data, 'base64'));
        console.log(`${size.name.toUpperCase()}_LAYOUT_OK ${JSON.stringify(layout)}`);
    }

    assert.equal(await evaluate("document.querySelector('#toggle-password')"), null);
    await evaluate("document.querySelector('#password').value = 'ExamplePassword123!'");
    assert.equal(await evaluate("document.querySelector('#password').type"), 'password');
    const maskedScreenshot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    await writeFile('storage/app/login-password-masked.png', Buffer.from(maskedScreenshot.data, 'base64'));

    await evaluate("document.querySelector('form').requestSubmit()");
    await waitUntil(() => evaluate("!!document.querySelector('.auth-field-error')"), 'validation error');
    assert.equal(await evaluate("document.querySelector('#email').getAttribute('aria-describedby')"), 'email-error');
    console.log('LOGIN_INTERACTIONS_OK');
} finally {
    socket.close();
    await fetch(`${debugUrl}/json/close/${target.id}`).catch(() => {});
}
