document.addEventListener('DOMContentLoaded', () => {
    (() => {
        const preview = document.getElementById('purchase-order-review-modal');
        if (! preview || ! window.bootstrap?.Modal) return;

        const loading = preview.querySelector('[data-po-preview-loading]');
        const error = preview.querySelector('[data-po-preview-error]');
        const content = preview.querySelector('[data-po-preview-content]');
        const reference = preview.querySelector('[data-po-preview-reference]');
        const details = preview.querySelector('[data-po-preview-details]');
        const documentPane = preview.querySelector('[data-po-preview-document]');
        const printButton = preview.querySelector('[data-export-purchase-order]');
        const editOpen = preview.querySelector('[data-po-task-edit-open]');
        const editModal = document.getElementById('purchase-order-task-edit-modal');
        const editBody = editModal?.querySelector('[data-po-task-edit-body]');
        let requestSequence = 0;
        let canPrint = false;
        let currentUrl = null;
        let reopenPreviewAfterEdit = false;

        function clearPreview() {
            details.replaceChildren();
            documentPane.replaceChildren();
            content.classList.add('d-none');
            error.classList.add('d-none');
            error.textContent = '';
            loading.classList.remove('d-none');
            canPrint = false;
            editOpen?.classList.add('d-none');
            preview.dataset.canPrint = 'false';
            printButton.disabled = true;
            reference.textContent = 'Loading saved record...';
            preview.querySelector('[data-po-export-feedback]')?.classList.add('d-none');
        }

        async function loadPreview(url) {
            if (!url) return;
            currentUrl = url;
            const sequence = ++requestSequence;
            clearPreview();
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || 'The Purchase Order preview could not be loaded.');
                if (sequence !== requestSequence) return;
                details.innerHTML = payload.details_html;
                if (payload.html) {
                    documentPane.innerHTML = payload.html;
                } else {
                    const unavailable = document.createElement('div');
                    unavailable.className = 'alert alert-warning';
                    unavailable.setAttribute('role', 'status');
                    unavailable.textContent = payload.template_error || 'No printable PO template is available.';
                    documentPane.replaceChildren(unavailable);
                }
                if (editOpen && editBody && payload.task_edit_html) {
                    editBody.innerHTML = payload.task_edit_html;
                    editOpen.textContent = payload.can_edit_task ? 'Edit' : 'Edit history';
                    editOpen.classList.remove('d-none');
                }
                canPrint = Boolean(payload.can_print);
                preview.dataset.canPrint = String(canPrint);
                reference.textContent = `${payload.po_number} · ${payload.status.replaceAll('_', ' ').toLowerCase()} saved record`;
                loading.classList.add('d-none');
                content.classList.remove('d-none');
                window.bootstrap.Tab.getOrCreateInstance(preview.querySelector(payload.html ? '#po-print-template-tab' : '#po-details-template-tab')).show();
                printButton.disabled = !canPrint || !preview.querySelector('.tab-pane.active .purchase-order-document');
            } catch (exception) {
                if (sequence !== requestSequence) return;
                loading.classList.add('d-none');
                error.textContent = exception.message || 'The Purchase Order preview could not be loaded.';
                error.classList.remove('d-none');
            }
        }

        const openPreview = (url) => {
            window.bootstrap.Modal.getOrCreateInstance(preview).show();
            loadPreview(url);
        };
        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('button[data-po-preview-url], [data-po-preview-row]');
            if (!trigger || preview.contains(trigger)) return;
            event.preventDefault();
            openPreview(trigger.dataset.poPreviewUrl);
        });
        document.addEventListener('keydown', (event) => {
            const row = event.target.closest?.('[data-po-preview-row]');
            if (!row || (event.key !== 'Enter' && event.key !== ' ')) return;
            event.preventDefault();
            openPreview(row.dataset.poPreviewUrl);
        });
        preview.addEventListener('hidden.bs.modal', () => {
            requestSequence++;
            if (reopenPreviewAfterEdit) return;
            clearPreview();
        });

        if (editOpen && editModal && editBody) {
            editOpen.addEventListener('click', () => {
                reopenPreviewAfterEdit = true;
                preview.addEventListener('hidden.bs.modal', () => window.bootstrap.Modal.getOrCreateInstance(editModal).show(), { once: true });
                window.bootstrap.Modal.getOrCreateInstance(preview).hide();
            });
            editModal.querySelector('[data-po-task-edit-back]')?.addEventListener('click', () => {
                editModal.addEventListener('hidden.bs.modal', () => openPreview(currentUrl), { once: true });
                window.bootstrap.Modal.getOrCreateInstance(editModal).hide();
            });
            editModal.addEventListener('hidden.bs.modal', () => { reopenPreviewAfterEdit = false; });

            editBody.addEventListener('submit', async (event) => {
                const form = event.target.closest('[data-po-task-edit-form]');
                if (!form) return;
                event.preventDefault();
                const errors = form.querySelector('[data-po-task-edit-errors]');
                const submit = form.querySelector('[type="submit"]');
                errors?.classList.add('d-none');
                if (submit) submit.disabled = true;
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const messages = payload.errors ? Object.values(payload.errors).flat() : [payload.message || 'The changes could not be saved.'];
                        throw new Error(messages.join(' '));
                    }
                    editBody.innerHTML = payload.task_edit_html;
                    const success = editBody.querySelector('[data-po-task-edit-success]');
                    if (success) {
                        success.textContent = payload.message;
                        success.classList.remove('d-none');
                    }
                } catch (exception) {
                    if (errors) {
                        errors.textContent = exception.message;
                        errors.classList.remove('d-none');
                    }
                    if (submit) submit.disabled = false;
                }
            });
        }
        preview.addEventListener('shown.bs.tab', () => {
            printButton.disabled = !canPrint || !preview.querySelector('.tab-pane.active .purchase-order-document');
        });
        if (preview.dataset.autoOpen === 'true') window.setTimeout(() => {
            window.bootstrap.Modal.getOrCreateInstance(preview).show();
            loadPreview(preview.dataset.poPreviewUrl);
        }, 120);
    })();

    (() => {
        const controls = document.querySelectorAll('[data-print-purchase-order], [data-export-purchase-order]');
        if (! controls.length) return;
        const preview = document.getElementById('purchase-order-review-modal');
        const feedback = document.querySelector('[data-po-export-feedback]');
        let printRoot = null;
        let printPageStyle = null;
        let printing = false;

        function removePrintCopy() {
            printRoot?.remove();
            printRoot = null;
            printPageStyle?.remove();
            printPageStyle = null;
        }

        async function createPrintCopy() {
            const source = preview?.querySelector('.tab-pane.active .purchase-order-document');
            if (! source) return false;

            removePrintCopy();
            printRoot = document.createElement('main');
            printRoot.id = 'purchase-order-print-root';
            printRoot.setAttribute('aria-hidden', 'true');
            printRoot.append(source.cloneNode(true));
            document.body.append(printRoot);
            printPageStyle = document.createElement('style');
            printPageStyle.textContent = '@page { size: A4 portrait; margin: 10mm; }';
            document.head.append(printPageStyle);

            try {
                await Promise.all([...printRoot.querySelectorAll('img')].map((image) => {
                    if (image.complete && image.naturalWidth > 0) return Promise.resolve();

                    return new Promise((resolve, reject) => {
                        image.addEventListener('load', resolve, { once: true });
                        image.addEventListener('error', reject, { once: true });
                    });
                }));
            } catch (error) {
                removePrintCopy();
                return false;
            }

            return true;
        }

        function resetControls(hideFeedback = true) {
            printing = false;
            controls.forEach((control) => {
                control.disabled = !preview?.querySelector('.tab-pane.active .purchase-order-document') || control.hasAttribute('data-export-purchase-order') && preview?.dataset.canPrint !== 'true';
                if (control.dataset.originalLabel) control.textContent = control.dataset.originalLabel;
            });
            if (hideFeedback) feedback?.classList.add('d-none');
        }

        controls.forEach((control) => control.addEventListener('click', async () => {
            if (printing) return;
            printing = true;
            controls.forEach((button) => { button.disabled = true; });
            if (control.hasAttribute('data-export-purchase-order')) {
                control.dataset.originalLabel = control.textContent;
                control.textContent = 'Preparing print...';
                if (feedback) {
                    feedback.textContent = 'Print this Purchase Order or choose Save as PDF.';
                    feedback.classList.remove('d-none');
                }
            }
            if (! await createPrintCopy()) {
                printing = false;
                resetControls(false);
                if (feedback) {
                    feedback.textContent = 'The Purchase Order document is unavailable for printing. Please reload and try again.';
                    feedback.classList.remove('d-none');
                    feedback.classList.remove('text-muted');
                    feedback.classList.add('text-danger');
                }
                return;
            }
            document.body.classList.add('print-purchase-order');
            try {
                window.print();
            } catch (error) {
                document.body.classList.remove('print-purchase-order');
                removePrintCopy();
                if (feedback) {
                    feedback.textContent = 'The PO export dialog could not be opened. Please try again.';
                    feedback.classList.remove('d-none');
                    feedback.classList.remove('text-muted');
                    feedback.classList.add('text-danger');
                }
                resetControls(false);
            }
        }));
        window.addEventListener('afterprint', () => {
            document.body.classList.remove('print-purchase-order');
            removePrintCopy();
            resetControls();
        });
    })();


});
