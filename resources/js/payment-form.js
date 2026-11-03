// Record payment window: show only the action card the window was opened for,
// with a toggle to bring back the rest of the request page.
function initializeEmbeddedFocus() {
    const root = document.documentElement;
    if (!root.classList.contains('is-embedded') || !window.location.hash) return;

    let anchor;
    try {
        anchor = document.querySelector(window.location.hash);
    } catch {
        return;
    }
    const card = anchor?.closest('[data-embedded-focus]');
    const main = document.querySelector('.app-main');
    if (!card || !main) return;

    // Preview dialogs (e.g. the PO preview) may sit inside sections about to be hidden.
    main.querySelectorAll('.modal').forEach((dialog) => document.body.append(dialog));

    // Hide every sibling on the path from the card up to <main>, so only the card remains.
    const hidden = [];
    for (let node = card; node && node !== main; node = node.parentElement) {
        [...(node.parentElement?.children ?? [])].forEach((sibling) => {
            if (sibling === node || sibling.hidden || sibling.matches('script, style, .modal, .alert')) return;
            sibling.hidden = true;
            hidden.push(sibling);
        });
    }
    root.classList.add('is-action-focus');

    const focusLabel = `Show ${card.dataset.embeddedFocus || 'this step'} only`;
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'btn btn-sm btn-outline-secondary embedded-focus-toggle';
    toggle.textContent = 'Show full request';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.addEventListener('click', () => {
        const showAll = toggle.getAttribute('aria-expanded') === 'false';
        hidden.forEach((element) => { element.hidden = !showAll; });
        toggle.setAttribute('aria-expanded', String(showAll));
        toggle.textContent = showAll ? focusLabel : 'Show full request';
        card.scrollIntoView({ block: 'start' });
    });
    card.before(toggle);
    window.scrollTo(0, 0);
}

// Live amount, balance and receipt feedback on the payment form.
function initializePaymentForm() {
    document.querySelectorAll('[data-payment-form]').forEach((form) => {
        const balance = Number(form.dataset.balance) || 0;
        const amount = form.querySelector('[data-payment-amount]');
        const remaining = form.querySelector('[data-payment-remaining]');
        const summary = form.querySelector('[data-payment-summary]');
        const submit = form.querySelector('[data-payment-submit]');
        const fill = form.querySelector('[data-payment-fill]');
        const partialNote = form.querySelector('[data-payment-partial-note]');
        const choices = [...form.querySelectorAll('[data-receiving-choice]')];
        const receivingFields = form.querySelector('[data-receiving-fields]');
        const receipts = form.querySelector('[data-payment-receipts]');
        const receiptList = form.querySelector('[data-payment-receipt-list]');
        const dropzone = form.querySelector('[data-payment-dropzone]');
        const peso = (value) => `₱ ${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

        const sync = () => {
            const value = Math.max(0, Number(amount?.value) || 0);
            const left = Math.max(0, Math.round((balance - value) * 100) / 100);
            const over = value > balance + 0.005;
            const partial = value > 0 && !over && left > 0.005;

            if (remaining) {
                remaining.textContent = over
                    ? ` · More than the balance of ${peso(balance)}`
                    : partial ? ` · ${peso(left)} will remain` : ' · Clears the balance';
                remaining.classList.toggle('text-danger', over);
            }
            if (fill) fill.hidden = Math.abs(value - balance) < 0.005;
            if (summary) {
                summary.textContent = value > 0
                    ? `Paying ${peso(value)}${partial ? ` · partial, ${peso(left)} left` : ' · full balance'}`
                    : 'Enter the amount paid';
            }
            if (submit) submit.textContent = partial ? 'Record partial payment' : 'Record payment';

            // Delivery is recorded with the final payment only.
            if (partialNote) partialNote.hidden = !partial;
            choices.forEach((choice) => {
                choice.disabled = partial;
                choice.closest('.payment-choice-option')?.classList.toggle('is-disabled', partial);
            });
            const received = !partial && choices.some((choice) => choice.checked && choice.value === 'RECEIVED');
            if (receivingFields) {
                const wasHidden = receivingFields.hidden;
                receivingFields.hidden = !received;
                receivingFields.disabled = !received;
                if (received && wasHidden) {
                    receivingFields.querySelectorAll('[data-signature-pad]').forEach((pad) => pad.dispatchEvent(new Event('signature-pad:refresh')));
                }
            }
        };

        const listReceipts = () => {
            if (!receipts || !receiptList) return;
            receiptList.replaceChildren(...[...receipts.files].map((file) => {
                const item = document.createElement('li');
                const size = file.size >= 1048576 ? `${(file.size / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(file.size / 1024))} KB`;
                item.textContent = `${file.name} · ${size}`;
                if (file.size > 10 * 1048576) {
                    item.classList.add('text-danger');
                    item.textContent += ' · too large (10 MB max)';
                }
                return item;
            }));
            dropzone?.classList.toggle('has-files', receipts.files.length > 0);
        };

        amount?.addEventListener('input', sync);
        fill?.addEventListener('click', () => {
            amount.value = balance.toFixed(2);
            sync();
            amount.focus();
        });
        choices.forEach((choice) => choice.addEventListener('change', sync));
        receipts?.addEventListener('change', listReceipts);
        if (dropzone && receipts) {
            ['dragenter', 'dragover'].forEach((type) => dropzone.addEventListener(type, (event) => {
                event.preventDefault();
                dropzone.classList.add('is-dragging');
            }));
            ['dragleave', 'drop'].forEach((type) => dropzone.addEventListener(type, () => dropzone.classList.remove('is-dragging')));
            dropzone.addEventListener('drop', (event) => {
                event.preventDefault();
                if (!event.dataTransfer?.files.length) return;
                receipts.files = event.dataTransfer.files;
                listReceipts();
            });
        }
        sync();
        listReceipts();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initializeEmbeddedFocus();
    initializePaymentForm();
});
