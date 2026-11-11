import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const artifacts = path.join(root, 'storage/app/private');
mkdirSync(artifacts, { recursive: true });
const chrome = [process.env.CHROME_BIN, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((candidate) => candidate && existsSync(candidate));
assert.ok(chrome, 'Set CHROME_BIN to a Chrome or Edge executable.');
const profile = mkdtempSync(path.join(artifacts, 'attachment-fields-chrome-'));
const markup = `<meta name="attachment-limits" data-max-files="20" data-max-bytes="41943040"><form id="uploads"><h2>Attachments</h2><input id="files" type="file" name="files[]" multiple required accept=".png,.pdf" data-max-files="5"><input id="csv-import" type="file" data-single-import accept=".csv"><label data-payment-dropzone><input id="payment" type="file" data-payment-receipts multiple accept=".png,.pdf" data-max-files="5"></label><ul data-payment-receipt-list></ul></form>`;
const checks = String.raw`
let assertions = 0;
function check(condition, message) { assertions++; if (!condition) throw new Error(message); }
const tick = () => new Promise((resolve) => setTimeout(resolve, 10));
const input = document.getElementById('files');
const box = document.querySelector('[data-paste-files]');
const field = input.closest('[data-attachment-field]');
const feedback = field.querySelector('[role="status"]');
const png = new Uint8Array([137,80,78,71,13,10,26,10]);
const image = () => new File([png], 'image.png', { type: 'image/png' });
function paste(files) {
    const event = new Event('paste', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'clipboardData', { value: { items: files.map((file) => ({ kind: 'file', getAsFile: () => file })), files: [] } });
    box.dispatchEvent(event);
}
try {
    check(box.isContentEditable, 'Paste box is an editable screenshot target');
    check(!document.getElementById('csv-import').closest('[data-attachment-field]'), 'CSV import retains its separate single-file behavior');
    paste([image()]);
    check(input.files.length === 1, 'Clipboard items work even when clipboardData.files is empty');
    check(input.files[0].name.startsWith('Screenshot-'), 'Pasted screenshot gets a usable filename');
    await tick(); paste([image()]);
    check(input.files.length === 2, 'Further screenshots append without replacing earlier files');
    const pdf = new File(['%PDF-1.4'], 'quotation.pdf', { type: 'application/pdf' });
    const transfer = new DataTransfer(); transfer.items.add(pdf); input.files = transfer.files; input.dispatchEvent(new Event('change', { bubbles: true }));
    check(input.files.length === 3, 'File picker adds to pasted screenshots');
    check(field.querySelectorAll('[data-file-preview] > div').length === 3, 'Every file has its own preview row');
    input.files = transfer.files; input.dispatchEvent(new Event('change', { bubbles: true }));
    check(input.files.length === 3, 'Choosing the same file does not duplicate it');
    field.querySelector('[aria-label^="Remove "]').click();
    check(input.files.length === 2 && input.files[1].name === 'quotation.pdf', 'Remove deletes only the selected file');
    paste([new File(['bad'], 'script.exe', { type: 'application/octet-stream' })]);
    check(input.files.length === 2 && feedback.textContent.includes('unsupported'), 'Unsupported pasted files are rejected');
    paste([new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'large.png', { type: 'image/png' })]);
    check(input.files.length === 2 && feedback.textContent.includes('exceeds'), 'Oversized files are rejected');
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { read: async () => [{ types: ['image/png'], getType: async () => new Blob([png], { type: 'image/png' }) }] } });
    field.querySelector('[data-paste-button]').click(); await tick();
    check(input.files.length === 3, 'Paste button reads a copied screenshot');
    const payment = document.getElementById('payment');
    const paymentControls = payment.closest('[data-payment-dropzone]').nextElementSibling;
    check(!payment.closest('[data-payment-dropzone]').contains(paymentControls.querySelector('[data-paste-button]')), 'Payment paste controls are outside the invisible file-input overlay');
    paymentControls.querySelector('[data-paste-button]').click(); await tick();
    check(payment.files.length === 1, 'Payment evidence supports pasted screenshots');
    check(document.querySelector('[data-payment-receipt-list]').hidden, 'Receipt evidence is listed only once');
    paste([new File(['1'], 'one.pdf', { type: 'application/pdf' }), new File(['2'], 'two.pdf', { type: 'application/pdf' }), new File(['3'], 'three.pdf', { type: 'application/pdf' })]);
    check(input.files.length === 5 && feedback.textContent.includes('Up to 5'), 'Multiple-file limit is enforced without discarding earlier files');
    document.querySelector('meta[name="attachment-limits"]').dataset.maxFiles = '1';
    const submit = new Event('submit', { bubbles: true, cancelable: true }); document.getElementById('uploads').dispatchEvent(submit);
    check(submit.defaultPrevented && document.querySelector('[data-upload-limit-error]'), 'Whole-form limits prevent silent upload truncation');
    document.querySelector('meta[name="attachment-limits"]').dataset.maxFiles = '20';
    const clone = field.cloneNode(true); clone.querySelector('input').value = ''; document.getElementById('uploads').append(clone); await tick();
    const cloneBox = clone.querySelector('[data-paste-files]');
    const clonedPaste = new Event('paste', { bubbles: true, cancelable: true });
    const pasted = image(); Object.defineProperty(clonedPaste, 'clipboardData', { value: { items: [{ kind: 'file', getAsFile: () => pasted }], files: [] } }); cloneBox.dispatchEvent(clonedPaste);
    check(clone.querySelector('input').files.length === 1, 'Cloned fields support pasting');
    check(input.files.length === 5, 'Pasting in one field leaves other fields unchanged');
    while (field.querySelector('[aria-label^="Remove "]')) field.querySelector('[aria-label^="Remove "]').click();
    check(input.files.length === 0 && !input.checkValidity(), 'Required evidence stays required after removing all files');
    check(clone.querySelector('input').files.length === 1, 'Removing all files in one field preserves others');
    document.getElementById('test-result').textContent = JSON.stringify({ passed: true, assertions });
} catch (error) { document.getElementById('test-result').textContent = JSON.stringify({ passed: false, error: error.message, assertions }); }
`;
const source = readFileSync(path.join(root, 'resources/js/attachment-fields.js'), 'utf8');
const css = readFileSync(path.join(root, 'resources/css/attachment-fields.css'), 'utf8');
const html = `<!doctype html><meta charset="utf-8"><title>Supplier picker browser check</title><style>body{font-family:Arial,sans-serif;background:#f5f6fc;color:#160d76;padding:32px}.quotation-entry-card{background:white;border:1px solid #d8dcf0;border-radius:16px;padding:24px;max-width:1050px;margin:auto}.supplier-picker{max-width:340px;margin-bottom:20px}label{display:block;font-size:13px;margin:14px 0 8px}.form-control{box-sizing:border-box;width:100%;padding:12px;border:1px solid #cfd3e6;border-radius:7px;font:14px Arial}textarea{min-height:80px}.form-text{font-size:12px;color:#65708b;margin-top:6px}h2{font-size:18px}h3{font-size:14px}#test-result{margin:24px;text-align:center}${css}</style>${markup}<pre id="test-result">Pending</pre><script type="module">${source}
${checks}</script>`;
const fixture = path.join(artifacts, 'attachment-fields-browser.html');
writeFileSync(fixture, html);
try {
    const result = spawnSync(chrome, ['--headless', '--disable-gpu', '--in-process-gpu', '--no-sandbox', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-extensions', `--user-data-dir=${profile}`, '--window-size=1400,1000', '--virtual-time-budget=6000', '--dump-dom', `--screenshot=${path.join(artifacts, 'attachment-fields-browser.png')}`, pathToFileURL(fixture).href], { encoding: 'utf8', windowsHide: true, timeout: 30000, maxBuffer: 5 * 1024 * 1024 });
    const match = result.stdout?.match(/<pre id="test-result">(.*?)<\/pre>/s);
    assert.ok(match, `Browser did not report a result: ${result.error?.message || result.stderr?.slice(-1000)}`);
    const summary = JSON.parse(match[1].replaceAll('&quot;', '"').replaceAll('&amp;', '&').replaceAll('&lt;', '<').replaceAll('&gt;', '>'));
    assert.equal(summary.passed, true, summary.error);
    console.log(`Attachment fields: ${summary.assertions} browser assertions passed.`);
} finally {
    // Delete only the unique test profile created beneath the artifact directory.
    assert.ok(path.resolve(profile).startsWith(path.resolve(artifacts) + path.sep));
    rmSync(profile, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 });
}
