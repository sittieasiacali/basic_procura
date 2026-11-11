// Indeterminate progress is intentional: the server cannot report a completion
// percentage for synchronous workflow transactions. Show time spent, never a fake %.
const progress = document.createElement('div');
progress.className = 'position-fixed bottom-0 end-0 m-3 p-3 bg-white border rounded shadow';
progress.style.zIndex = '1090';
progress.hidden = true;
progress.setAttribute('role', 'status');
progress.setAttribute('aria-live', 'polite');
progress.innerHTML = '<div class="d-flex gap-2 align-items-center"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span data-process-label>Processing…</span></div><progress class="w-100 mt-2" aria-label="Process in progress"></progress><small data-process-time></small>';
document.body.append(progress);
let pending = 0;
let timer;
function begin(label = 'Processing…') {
    pending += 1;
    progress.querySelector('[data-process-label]').textContent = label;
    if (pending > 1) return;
    progress.hidden = false;
    const started = Date.now();
    const update = () => { progress.querySelector('[data-process-time]').textContent = `${Math.floor((Date.now() - started) / 1000)} seconds elapsed`; };
    update();
    timer = setInterval(update, 1000);
}
function end() {
    pending = Math.max(0, pending - 1);
    if (pending) return;
    clearInterval(timer);
    progress.hidden = true;
}
const originalFetch = window.fetch.bind(window);
window.fetch = async (...args) => {
    const options = args[1] ?? {};
    const method = (options.method ?? (args[0] instanceof Request ? args[0].method : 'GET')).toUpperCase();
    const show = method !== 'GET' && method !== 'HEAD';
    if (show) begin('Saving changes…');
    try { return await originalFetch(...args); }
    finally { if (show) end(); }
};
document.addEventListener('submit', (event) => {
    if (event.defaultPrevented || event.target.target === '_blank') return;
    const form = event.target;
    queueMicrotask(() => {
        if (!event.defaultPrevented) begin(form.querySelector('input[type="file"]') ? 'Uploading files and processing…' : 'Processing…');
    });
});
window.addEventListener('pageshow', () => { pending = 1; end(); });
window.addEventListener('pagehide', () => { pending = 1; end(); });

// Refresh the exact unread count while the workspace is open, without reloading forms.
const bell = document.querySelector('[data-notification-count]');
let refreshingNotifications = false;
async function refreshNotificationCount() {
    if (!bell || document.hidden || refreshingNotifications) return;
    refreshingNotifications = true;
    try {
        const response = await originalFetch(bell.dataset.summaryUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (!response.ok) return;
        const data = await response.json();
        if (!Number.isInteger(data.unread_count)) return;
        bell.textContent = String(data.unread_count);
        bell.hidden = data.unread_count === 0;
        const label = document.querySelector('[data-notification-unread]');
        if (label) label.textContent = `${data.unread_count} unread`;
    } catch { /* Keep the last confirmed count if the network is unavailable. */ }
    finally { refreshingNotifications = false; }
}
if (bell) {
    setInterval(refreshNotificationCount, 30000);
    window.addEventListener('focus', refreshNotificationCount);
}
