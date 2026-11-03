document.addEventListener('DOMContentLoaded', () => {
    const modalElement = document.getElementById('payment-view-modal');
    if (!modalElement || !window.bootstrap?.Modal) return;

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
    const sheetTab = modalElement.querySelector('[data-payment-sheet-tab]');
    const sheetBody = modalElement.querySelector('[data-payment-sheet-body]');
    const printLink = modalElement.querySelector('[data-payment-sheet-print]');
    const detailsTab = modalElement.querySelector('#payment-view-details-tab');
    const status = modalElement.querySelector('[data-payment-status]');
    let sheetRequest = 0;

    // The payment's online / cashout deposit / check transaction sheet loads into its own tab.
    const loadSheet = async (url) => {
        const current = ++sheetRequest;
        if (sheetTab) sheetTab.hidden = !url;
        if (printLink) {
            printLink.hidden = !url;
            if (url) printLink.href = `${url}?print=1`;
        }
        if (!url || !sheetBody) return;
        sheetBody.innerHTML = '<div class="payment-view-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Loading transaction sheet…</div>';
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) throw new Error(response.status);
            const html = await response.text();
            if (current === sheetRequest) sheetBody.innerHTML = html;
        } catch {
            if (current === sheetRequest) sheetBody.textContent = 'The transaction sheet could not be loaded.';
        }
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-payment-view]');
        if (!trigger) return;

        let payment;
        try {
            payment = JSON.parse(trigger.dataset.paymentView);
        } catch {
            return;
        }

        modalElement.querySelectorAll('[data-payment-field]').forEach((field) => {
            field.textContent = payment[field.dataset.paymentField] ?? '';
        });
        if (status) status.dataset.state = String(payment.status ?? '').toLowerCase();
        const receipts = modalElement.querySelector('[data-payment-receipts]');
        if (receipts) {
            receipts.replaceChildren();
            const files = Array.isArray(payment.receipts) ? payment.receipts : [];
            if (files.length === 0) {
                const empty = document.createElement('li');
                empty.className = 'text-muted';
                empty.textContent = 'No receipt uploaded';
                receipts.append(empty);
            }
            files.forEach((file) => {
                const item = document.createElement('li');
                const link = document.createElement('a');
                link.href = file.url;
                link.textContent = file.name;
                item.append(link);
                receipts.append(item);
            });
        }
        if (detailsTab) window.bootstrap.Tab?.getOrCreateInstance(detailsTab).show();
        loadSheet(payment.sheet_url);
        modal.show(trigger);
    });
});
