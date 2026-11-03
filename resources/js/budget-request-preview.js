document.addEventListener('DOMContentLoaded', () => {
    let preview = document.getElementById('budget-request-preview-modal');
    if (!preview) {
        // The purchase request detail uses a different layout, so mount the same preview there.
        document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="budget-request-preview-modal" tabindex="-1" aria-labelledby="budget-request-preview-heading" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable po-preview-modal br-preview-modal"><div class="modal-content">
                <div class="modal-header"><div><h2 class="modal-title fs-5" id="budget-request-preview-heading">Budget Request Preview</h2><p class="small text-muted mb-0" data-br-preview-reference>Loading saved record...</p></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body"><div class="d-flex align-items-center gap-2 py-5 justify-content-center" data-br-preview-loading><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Loading Budget Request...</span></div><div class="alert alert-danger d-none" role="alert" data-br-preview-error></div><div class="d-none" data-br-preview-content><div class="br-preview-sheet" data-br-preview-transaction></div></div></div>
                <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button><a class="btn btn-primary" data-br-preview-print href="#" target="_blank" rel="noopener">Print / Save as PDF</a></div>
            </div></div></div>`);
        preview = document.getElementById('budget-request-preview-modal');
    }
    if (!window.bootstrap?.Modal) return;

    const loading = preview.querySelector('[data-br-preview-loading]');
    const error = preview.querySelector('[data-br-preview-error]');
    const content = preview.querySelector('[data-br-preview-content]');
    const reference = preview.querySelector('[data-br-preview-reference]');
    const printLink = preview.querySelector('[data-br-preview-print]');
    const sheet = preview.querySelector('[data-br-preview-transaction]');
    let sequence = 0;

    const requestCard = document.querySelector('.budget-request-document');
    const requestLink = requestCard?.querySelector('a[href*="/budget-requests/"][href$="/document"]');
    if (requestLink) {
        requestLink.textContent = 'Preview Budget Request';
        requestLink.removeAttribute('target');
        requestLink.dataset.brPreviewUrl = requestLink.href;
        const oldPrintButton = requestCard.querySelector('[data-print-budget-request]');
        if (oldPrintButton) {
            oldPrintButton.textContent = 'Preview / Print';
            oldPrintButton.dataset.brPreviewUrl = requestLink.href;
        }
    }

    async function openPreview(url) {
        const requestId = ++sequence;
        window.bootstrap.Modal.getOrCreateInstance(preview).show();
        loading.classList.remove('d-none');
        content.classList.add('d-none');
        error.classList.add('d-none');
        sheet.replaceChildren();
        reference.textContent = 'Loading saved record...';
        printLink.classList.add('disabled');
        printLink.removeAttribute('href');
        const previewUrl = new URL(url, window.location.origin);
        previewUrl.searchParams.set('preview', '1');
        try {
            const response = await fetch(previewUrl, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'The Budget Request could not be loaded.');
            if (requestId !== sequence) return;
            sheet.innerHTML = payload.transaction_html;
            reference.textContent = `${payload.number} · ${payload.transaction_type}`;
            const printUrl = new URL(url, window.location.origin);
            printUrl.searchParams.set('mode', 'print');
            printLink.href = printUrl.toString();
            printLink.classList.remove('disabled');
            loading.classList.add('d-none');
            content.classList.remove('d-none');
        } catch (exception) {
            if (requestId !== sequence) return;
            loading.classList.add('d-none');
            error.textContent = exception.message || 'The Budget Request could not be loaded.';
            error.classList.remove('d-none');
        }
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-br-preview-url]');
        if (!trigger) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        openPreview(trigger.dataset.brPreviewUrl);
    }, true);
    preview.addEventListener('hidden.bs.modal', () => { sequence++; });
});
