// Record payment: the receiving fields apply only when the goods are marked Received.
// Hidden fields are disabled so their "required" rules do not block a For receiving payment.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-receiving-status]').forEach((select) => {
        const fields = select.form?.querySelector('[data-receiving-fields]');
        if (!fields) return;

        const sync = () => {
            const received = select.value === 'RECEIVED';
            fields.hidden = !received;
            fields.disabled = !received;
            if (received) {
                fields.querySelectorAll('[data-signature-pad]').forEach((pad) => pad.dispatchEvent(new Event('signature-pad:refresh')));
            }
        };

        select.addEventListener('change', sync);
        sync();
    });

    // Record payment for several POs: ticking "Already received" on any PO shows the receiving signature.
    document.querySelectorAll('form').forEach((form) => {
        const boxes = [...form.querySelectorAll('[data-batch-received]')];
        const all = form.querySelector('[data-batch-received-all]');
        const fields = form.querySelector('[data-receiving-fields]');
        if (!boxes.length || !fields) return;

        const sync = () => {
            // Each ticked PO opens its own inventory details (quantity, unit, serial, location).
            boxes.forEach((box) => {
                const details = form.querySelector(`[data-batch-received-details="${box.value}"]`);
                if (!details) return;
                details.hidden = !box.checked;
                details.querySelector('fieldset').disabled = !box.checked;
            });
            const ticked = boxes.filter((box) => box.checked).length;
            fields.hidden = ticked === 0;
            fields.disabled = ticked === 0;
            if (all) {
                all.checked = ticked === boxes.length;
                all.indeterminate = ticked > 0 && ticked < boxes.length;
            }
            if (ticked) fields.querySelectorAll('[data-signature-pad]').forEach((pad) => pad.dispatchEvent(new Event('signature-pad:refresh')));
        };
        boxes.forEach((box) => box.addEventListener('change', sync));
        all?.addEventListener('change', () => {
            boxes.forEach((box) => { box.checked = all.checked; });
            sync();
        });
        sync();
    });

    document.querySelectorAll('[data-receiving-form]').forEach((form) => {
        const signature = form.querySelector('[data-signature-data]');
        const feedback = form.querySelector('[data-receiving-feedback]');
        const canvas = form.querySelector('[data-signature-canvas]');
        form.addEventListener('submit', (event) => {
            if (!signature?.value.trim()) {
                event.preventDefault();
                feedback.textContent = 'Draw your signature above before recording the goods as received.';
                feedback.hidden = false;
                canvas?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                canvas?.focus({ preventScroll: true });
                return;
            }

            feedback.classList.remove('alert-warning');
            feedback.classList.add('alert-info');
            feedback.textContent = 'Recording the receipt and adding the items to inventory…';
            feedback.hidden = false;
        });
        canvas?.addEventListener('pointerup', () => {
            queueMicrotask(() => {
                if (signature?.value.trim()) feedback.hidden = true;
            });
        });
    });
});
