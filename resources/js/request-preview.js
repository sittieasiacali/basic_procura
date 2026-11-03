// Read-only preview of a processed purchase request, opened from the dashboard's
// "My processed requests" rows. Mirrors the Purchase Order preview.
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('request-preview-modal');
    if (!modal || !window.bootstrap?.Modal) return;

    const loading = modal.querySelector('[data-request-preview-loading]');
    const error = modal.querySelector('[data-request-preview-error]');
    const content = modal.querySelector('[data-request-preview-content]');
    const reference = modal.querySelector('[data-request-preview-reference]');
    const printLink = modal.querySelector('[data-request-preview-print]');
    let sequence = 0;

    const reset = () => {
        content.replaceChildren();
        content.classList.add('d-none');
        error.classList.add('d-none');
        error.textContent = '';
        loading.classList.remove('d-none');
        reference.textContent = 'Loading saved record…';
        printLink.classList.add('disabled');
        printLink.setAttribute('aria-disabled', 'true');
        printLink.setAttribute('href', '#');
    };

    const load = async (url) => {
        const current = ++sequence;
        reset();
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'The request preview could not be loaded.');
            if (current !== sequence) return;
            content.innerHTML = payload.html;
            reference.textContent = `${payload.request_number} · ${payload.status.replaceAll('_', ' ').toLowerCase()}`;
            printLink.setAttribute('href', payload.print_url);
            printLink.classList.remove('disabled');
            printLink.removeAttribute('aria-disabled');
            loading.classList.add('d-none');
            content.classList.remove('d-none');
        } catch (exception) {
            if (current !== sequence) return;
            loading.classList.add('d-none');
            error.textContent = exception.message || 'The request preview could not be loaded.';
            error.classList.remove('d-none');
        }
    };

    const open = (url) => {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
        load(url);
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-request-preview-url]');
        if (!trigger || modal.contains(trigger)) return;
        event.preventDefault();
        open(trigger.dataset.requestPreviewUrl);
    });
    document.addEventListener('keydown', (event) => {
        const row = event.target.closest?.('tr[data-request-preview-url]');
        if (!row || (event.key !== 'Enter' && event.key !== ' ')) return;
        event.preventDefault();
        open(row.dataset.requestPreviewUrl);
    });
    modal.addEventListener('hidden.bs.modal', () => { sequence++; reset(); });
});
