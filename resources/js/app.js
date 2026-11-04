import './bootstrap';
import './purchase-order-preview';
import './budget-request-preview';
import './request-preview';
import './payment-view';
import './payment-receiving';
import './payment-form';
import './request-title';
import './request-void';
import './inventory';
import './inventory-sku';

function initializeBudgetRequestQueue() {
    const queue = document.querySelector('[data-budget-request-queue]');
    if (!queue) {
        return;
    }

    queue.querySelectorAll('[data-priority-select]').forEach((select) => {
        select.addEventListener('change', () => {
            select.disabled = true;
            select.closest('[data-priority-form]')?.requestSubmit();
        });
    });

}

document.addEventListener('DOMContentLoaded', initializeBudgetRequestQueue);

function initializeBudgetRequestCampaign() {
    document.querySelectorAll('[data-budget-campaign]').forEach((campaign) => {
        const row = campaign.closest('.budget-create-po-form, .po-budget-row, .card');
        const accountOutput = row?.querySelector('[data-campaign-account-output]');
        const sourceAccount = row?.querySelector('[data-source-bank-account]');
        if (!accountOutput) return;

        let previousCampaignAccount = '';
        const refreshAccount = () => {
            const accountNumber = campaign.selectedOptions[0]?.dataset.accountNumber || '';
            accountOutput.value = accountNumber;
            accountOutput.placeholder = accountNumber ? '' : 'Not configured';
            if (sourceAccount && (!sourceAccount.value.trim() || sourceAccount.value === previousCampaignAccount)) {
                sourceAccount.value = accountNumber;
            }
            previousCampaignAccount = accountNumber;
        };

        campaign.addEventListener('change', refreshAccount);
        refreshAccount();
    });
}

document.addEventListener('DOMContentLoaded', initializeBudgetRequestCampaign);

function initializeBudgetCreateSummary() {
    const form = document.querySelector('[data-budget-create-form]');
    if (!form) return;

    const accountType = form.querySelector('[data-budget-account-type]');
    const transactionType = form.querySelector('[data-budget-transaction-type]');
    const fundSource = form.querySelector('[data-budget-fund-source]');
    const mainFund = form.querySelector('[data-budget-main-fund]');
    const custodianInput = form.querySelector('#budget_custodian_id');
    const remarksInput = form.querySelector('#batch_budget_remarks');
    const sourceCampaign = form.querySelector('[data-budget-source-campaign]');
    const signature = form.querySelector('[data-signature-data]');
    const summary = {
        accountType: form.querySelector('[data-summary-account-type]'),
        transactionType: form.querySelector('[data-summary-transaction-type]'),
        fundSource: form.querySelector('[data-summary-fund-source]'),
        mainFund: form.querySelector('[data-summary-main-fund]'),
        custodian: form.querySelector('[data-summary-custodian]'),
        remarks: form.querySelector('[data-summary-remarks]'),
        sourceCampaign: form.querySelector('[data-summary-source-campaign]'),
        readiness: form.querySelector('[data-budget-readiness]'),
    };

    const refresh = () => {
        if (summary.accountType) summary.accountType.textContent = accountType?.selectedOptions[0]?.value ? accountType.selectedOptions[0].textContent.trim() : 'Not selected';
        if (summary.transactionType) summary.transactionType.textContent = transactionType?.selectedOptions[0]?.value ? transactionType.selectedOptions[0].textContent.trim() : 'Not selected';
        if (summary.fundSource) summary.fundSource.textContent = fundSource?.selectedOptions[0]?.value ? fundSource.selectedOptions[0].textContent.trim() : 'Not selected';
        if (summary.mainFund) summary.mainFund.textContent = mainFund?.value.trim() || 'Not entered';
        if (summary.custodian) summary.custodian.textContent = custodianInput?.selectedIndex > 0 ? custodianInput.selectedOptions[0].dataset.custodianName : 'Not assigned';
        if (summary.remarks) summary.remarks.textContent = remarksInput?.value.trim() || 'None';
        if (summary.sourceCampaign) summary.sourceCampaign.textContent = sourceCampaign?.selectedOptions[0]?.value ? sourceCampaign.selectedOptions[0].textContent.trim() : 'Not selected';
        if (!summary.readiness) return;
        const ready = form.checkValidity() && (!signature || Boolean(signature.value.trim()));
        summary.readiness.textContent = ready ? 'Ready to create Budget Request.' : (signature ? 'Complete the required fields and draw your signature to continue.' : 'Complete the required fields to continue.');
        summary.readiness.classList.toggle('is-ready', ready);
    };

    form.querySelectorAll('[data-po-details]').forEach((details) => {
        const summaryElement = details.querySelector('summary');
        details.addEventListener('toggle', () => summaryElement?.setAttribute('aria-expanded', String(details.open)));
    });
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    form.querySelector('[data-signature-canvas]')?.addEventListener('pointerup', () => queueMicrotask(refresh));
    form.querySelector('[data-signature-clear]')?.addEventListener('click', () => queueMicrotask(refresh));
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        const fieldsValid = form.reportValidity();
        if (!fieldsValid || (signature && !signature.value.trim())) {
            event.preventDefault();
            if (signature && !signature.value.trim()) {
                const signatureError = form.querySelector('[data-signature-client-error]');
                if (signatureError) signatureError.hidden = false;
                if (fieldsValid) form.querySelector('[data-signature-canvas]')?.focus();
            }
            return;
        }
        const button = form.querySelector('[data-budget-submit]');
        const label = form.querySelector('[data-submit-label]');
        if (button) button.disabled = true;
        if (label) label.textContent = 'Submitting…';
    });
    refresh();
}

document.addEventListener('DOMContentLoaded', initializeBudgetCreateSummary);
function normalizeFieldName(key) {
    return key.split('.').map((segment) => `[${segment}]`).join('').replace(/^\[/, '');
}

function resolveFieldSelector(key) {
    const fieldName = normalizeFieldName(key);

    return [
        `input[name="${fieldName}"]`,
        `select[name="${fieldName}"]`,
        `textarea[name="${fieldName}"]`,
    ].join(', ');
}

function prettifyKey(key) {
    const lastSegment = key.split('.').at(-1) ?? key;
    const text = lastSegment
        .replace(/[_-]+/g, ' ')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .trim();

    if (text === 'item id' || text === 'item_id' || key.includes('item_id')) {
        return 'Quoted Item';
    }

    if (key.includes('supplier_name')) {
        return 'Supplier';
    }

    if (key.includes('quoted_amount')) {
        return 'Quoted Amount';
    }

    if (key.includes('delivery_lead_time')) {
        return 'Lead Time';
    }

    if (key.includes('quotation_url')) {
        return 'Quotation URL';
    }

    if (key.includes('warranty')) {
        return 'Warranty';
    }

    if (key.includes('remarks')) {
        return 'Remarks';
    }

    if (key.includes('recommended')) {
        return 'Recommendation';
    }

    if (key.includes('quantity')) {
        return 'Quantity';
    }

    if (key.includes('unit_price')) {
        return 'Unit Price';
    }

    if (key.includes('transaction_type')) {
        return 'Type of Transaction';
    }

    if (key.includes('amount')) {
        return 'Amount';
    }

    if (key.includes('bank_details')) {
        return 'Bank Details';
    }

    if (key.includes('title')) {
        return 'Title';
    }

    if (key.includes('purpose')) {
        return 'Purpose';
    }

    if (key.includes('request_details')) {
        return 'Request Details';
    }

    return text
        .split(' ')
        .filter(Boolean)
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

function deriveFieldLabel(key, field) {
    if (!field) {
        return prettifyKey(key);
    }

    const fieldId = field.id;
    if (fieldId) {
        const label = document.querySelector(`label[for="${CSS.escape(fieldId)}"]`);
        if (label && label.textContent.trim()) {
            return label.textContent.trim();
        }
    }

    const closestLabel = field.closest('label');
    if (closestLabel && closestLabel.textContent.trim()) {
        return closestLabel.textContent.trim();
    }

    const container = field.closest('.row, .mb-3, .form-group, .field-group, .quote-row, .quoted-item-row, .d-flex, .col-12, .col-md-2, .col-md-3, .col-md-4, .col-md-5');
    if (container) {
        const containerLabel = container.querySelector('.form-label, label');
        if (containerLabel && containerLabel.textContent.trim()) {
            return containerLabel.textContent.trim();
        }
    }

    return prettifyKey(key);
}

function formatFieldMessage(label, message) {
    if (!label) {
        return message;
    }

    const normalized = message.replace(/^The\s+.+?\s+field\s+/i, '');
    const cleaned = normalized.trim();

    if (cleaned === 'is required.') {
        return `${label} is required.`;
    }

    if (cleaned === 'is invalid.') {
        return `${label} is invalid.`;
    }

    if (cleaned.length > 0) {
        if (/^is\s+/i.test(cleaned)) {
            return `${label} ${cleaned}`;
        }

        return `${label}: ${cleaned}`;
    }

    return `${label}.`;
}

function revealFieldContext(field) {
    if (!field) {
        return;
    }

    field.closest('.d-none')?.classList.remove('d-none');
    field.closest('[hidden]')?.removeAttribute('hidden');

    const section = field.closest('.quote-bulk-fields, .quote-manual-fields, .collapse, .accordion-item, .card, .tab-pane, .form-section, .workflow-section');
    if (section) {
        section.classList.remove('d-none');
        section.removeAttribute('hidden');
        if (section.classList.contains('collapse')) {
            section.classList.add('show');
        }
    }

    const parent = field.parentElement;
    if (parent) {
        parent.classList.remove('d-none');
    }
}

function markFieldInvalid(field, message) {
    if (!(field instanceof HTMLElement)) {
        return;
    }

    if (field.disabled) {
        return;
    }

    revealFieldContext(field);

    field.classList.add('is-invalid');
    field.setAttribute('aria-invalid', 'true');

    const wrapper = field.closest('.form-check') || field.closest('.mb-3') || field.closest('.col-md-2') || field.closest('.col-md-3') || field.closest('.col-md-4') || field.closest('.col-md-5') || field.closest('.col-12') || field.parentElement;
    if (wrapper && !wrapper.querySelector('.field-validation-message')) {
        const errorNode = document.createElement('div');
        errorNode.className = 'field-validation-message text-danger small mt-1';
        errorNode.textContent = message;
        wrapper.appendChild(errorNode);
    }
}

function getFieldNameMaps() {
    const value = window.__validationErrors ?? {};
    return Object.entries(value).reduce((accumulator, [key, messages]) => {
        if (!Array.isArray(messages) || messages.length === 0) {
            return accumulator;
        }

        const fieldSelector = resolveFieldSelector(key);
        const target = document.querySelector(fieldSelector);

        if (!target || target.disabled) {
            return accumulator;
        }

        accumulator.push({
            key,
            field: target,
            messages,
        });

        return accumulator;
    }, []);
}

function rebuildValidationSummary(entries) {
    const summary = document.getElementById('validation-error-summary');
    if (!summary) {
        return;
    }

    const list = summary.querySelector('ul');
    if (!list) {
        return;
    }

    const title = summary.querySelector('strong');
    const subtitle = summary.querySelector('.mt-1');

    list.innerHTML = '';

    entries.forEach(({ key, field, messages }) => {
        const label = deriveFieldLabel(key, field);

        messages.forEach((message, index) => {
            const item = document.createElement('li');
            const anchor = document.createElement('a');
            const fieldId = `validation-error-field-${String(key).replace(/[^a-zA-Z0-9_-]+/g, '-')}-${index}`;
            if (!field.id) {
                field.id = fieldId;
            }

            anchor.href = `#${field.id}`;
            anchor.className = 'validation-error-link';
            anchor.dataset.validationErrorLink = 'true';
            anchor.dataset.errorKey = key;
            anchor.textContent = formatFieldMessage(label, message);
            anchor.addEventListener('click', (event) => {
                event.preventDefault();
                revealFieldContext(field);
                field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                field.focus({ preventScroll: true });
                field.classList.add('is-invalid');
            });
            item.appendChild(anchor);
            list.appendChild(item);
        });
    });

    if (title) {
        title.textContent = entries.length === 1 ? 'Please fix the highlighted field:' : `Please fix ${entries.length} highlighted fields.`;
    }

    if (subtitle) {
        subtitle.textContent = entries.length === 1 ? 'Please fix the following error:' : 'Please fix the following errors:';
    }
}

function initializeValidationUx() {
    const validationEntries = getFieldNameMaps();
    if (!validationEntries.length) {
        return;
    }

    rebuildValidationSummary(validationEntries);

    let firstTarget = null;

    validationEntries.forEach(({ key, field, messages }) => {
        const label = deriveFieldLabel(key, field);
        const firstMessage = messages.at(0) || 'This field is invalid.';

        markFieldInvalid(field, formatFieldMessage(label, firstMessage));
        if (!firstTarget) {
            firstTarget = field;
        }
    });

    if (firstTarget) {
        revealFieldContext(firstTarget);
        firstTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstTarget.focus({ preventScroll: true });
    }
}

function initializeAppPageBack() {
    document.querySelectorAll('[data-app-page-back]').forEach((button) => {
        button.addEventListener('click', () => {
            const forcedDestination = document.querySelector('[data-app-page-back-destination]')?.dataset.appPageBackDestination;
            const fallback = button.dataset.backFallback || '/dashboard';

            if (forcedDestination) {
                window.location.assign(forcedDestination);
                return;
            }

            let canReturnToApp = false;

            if (document.referrer) {
                try {
                    canReturnToApp = new URL(document.referrer).origin === window.location.origin;
                } catch (_) {
                    canReturnToApp = false;
                }
            }

            if (canReturnToApp && window.history.length > 1) {
                window.history.back();
                return;
            }

            window.location.assign(fallback);
        });
    });
}
function initializeAttachmentPreviews() {
    document.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'file' || ! input.name.includes('[attachment]')) {
            return;
        }

        const preview = input.closest('[data-item-row]')?.querySelector('[data-attachment-preview]');
        if (! preview) {
            return;
        }

        preview.replaceChildren();
        const file = input.files?.[0];
        if (! file) {
            return;
        }

        const details = document.createElement('div');
        details.className = 'border rounded p-2 d-flex flex-wrap align-items-center gap-2';
        const label = document.createElement('span');
        label.className = 'small';
        label.textContent = `${file.name} · ${Math.ceil(file.size / 1024)} KB`;
        details.appendChild(label);

        if (file.type.startsWith('image/')) {
            const image = document.createElement('img');
            image.className = 'attachment-preview-image';
            image.alt = `Preview of ${file.name}`;
            image.src = URL.createObjectURL(file);
            image.onload = () => URL.revokeObjectURL(image.src);
            details.prepend(image);
        } else if (file.type === 'application/pdf') {
            const pdf = document.createElement('embed');
            pdf.className = 'attachment-preview-pdf';
            pdf.type = file.type;
            pdf.src = URL.createObjectURL(file);
            details.prepend(pdf);
        } else {
            const icon = document.createElement('span');
            icon.className = 'badge text-bg-secondary';
            icon.textContent = 'FILE';
            details.prepend(icon);
        }

        const remove = document.createElement('button');
        remove.className = 'btn btn-sm btn-outline-danger ms-auto';
        remove.type = 'button';
        remove.textContent = 'Remove';
        remove.addEventListener('click', () => {
            input.value = '';
            preview.replaceChildren();
        });
        details.appendChild(remove);
        preview.appendChild(details);
    });
}

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || form.dataset.allowDuplicateSubmission === 'true') {
        return;
    }

    // A form-level check (e.g. a missing signature) already cancelled this submit;
    // don't lock the form, or its buttons stay unclickable after the user fixes it.
    if (event.defaultPrevented) {
        return;
    }

    if (form.dataset.submitting === 'true') {
        event.preventDefault();

        return;
    }

    form.dataset.submitting = 'true';
    form.classList.add('is-submitting');
    form.setAttribute('aria-busy', 'true');
});

function initializeSignaturePads() {
    document.querySelectorAll('[data-signature-pad]').forEach((pad) => {
        const canvas = pad.querySelector('[data-signature-canvas]');
        const output = pad.querySelector('[data-signature-data]');
        const clearButton = pad.querySelector('[data-signature-clear]');
        const error = pad.querySelector('[data-signature-client-error]');
        const form = pad.closest('form');

        if (!(canvas instanceof HTMLCanvasElement) || !(output instanceof HTMLInputElement) || !(form instanceof HTMLFormElement)) {
            return;
        }

        const context = canvas.getContext('2d');
        if (!context) {
            return;
        }

        let isDrawing = false;
        let hasSignature = false;
        let lastPoint = null;

        const configureCanvas = () => {
            const rect = canvas.getBoundingClientRect();
            const ratio = window.devicePixelRatio || 1;
            canvas.width = Math.max(1, Math.round(rect.width * ratio));
            canvas.height = Math.max(1, Math.round(rect.height * ratio));
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.strokeStyle = '#172554';
            context.lineWidth = 2.5;
        };

        const pointFor = (event) => {
            const rect = canvas.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        };

        // Export only the inked area so the signature fills document signature boxes
        // instead of being shrunk along with the empty width of the pad.
        const croppedDataUrl = () => {
            const { width, height } = canvas;
            const pixels = context.getImageData(0, 0, width, height).data;
            let minX = width;
            let minY = height;
            let maxX = -1;
            let maxY = -1;
            for (let y = 0; y < height; y += 1) {
                for (let x = 0; x < width; x += 1) {
                    if (pixels[(y * width + x) * 4 + 3] > 8) {
                        if (x < minX) minX = x;
                        if (x > maxX) maxX = x;
                        if (y < minY) minY = y;
                        if (y > maxY) maxY = y;
                    }
                }
            }
            if (maxX < 0) return canvas.toDataURL('image/png');
            const padding = 6;
            minX = Math.max(0, minX - padding);
            minY = Math.max(0, minY - padding);
            const cropWidth = Math.min(width, maxX + padding + 1) - minX;
            const cropHeight = Math.min(height, maxY + padding + 1) - minY;
            const cropped = document.createElement('canvas');
            cropped.width = cropWidth;
            cropped.height = cropHeight;
            cropped.getContext('2d').drawImage(canvas, minX, minY, cropWidth, cropHeight, 0, 0, cropWidth, cropHeight);
            return cropped.toDataURL('image/png');
        };

        const save = () => {
            output.value = hasSignature ? croppedDataUrl() : '';
        };

        const clear = () => {
            context.clearRect(0, 0, canvas.width, canvas.height);
            hasSignature = false;
            lastPoint = null;
            output.value = '';
            if (error) error.hidden = true;
        };

        configureCanvas();
        pad.closest('.modal')?.addEventListener('shown.bs.modal', () => {
            if (!hasSignature) configureCanvas();
        });
        // A pad revealed after load (e.g. Record payment > Received) measures its canvas again.
        pad.addEventListener('signature-pad:refresh', () => {
            if (!hasSignature) configureCanvas();
        });
        clearButton?.addEventListener('click', clear);

        canvas.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            canvas.setPointerCapture(event.pointerId);
            isDrawing = true;
            lastPoint = pointFor(event);
        });

        canvas.addEventListener('pointermove', (event) => {
            if (!isDrawing || !lastPoint) return;
            event.preventDefault();
            const point = pointFor(event);
            context.beginPath();
            context.moveTo(lastPoint.x, lastPoint.y);
            context.lineTo(point.x, point.y);
            context.stroke();
            lastPoint = point;
            hasSignature = true;
        });

        const completeStroke = (event) => {
            if (!isDrawing) return;
            isDrawing = false;
            lastPoint = null;
            if (canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId);
            save();
        };

        canvas.addEventListener('pointerup', completeStroke);
        canvas.addEventListener('pointercancel', completeStroke);

        form.addEventListener('submit', (event) => {
            // e.g. data-signature-required-for="submit": Save Draft may go through unsigned.
            const requiredFor = pad.dataset.signatureRequiredFor;
            if (requiredFor && event.submitter?.value !== requiredFor) return;
            // A pad inside a disabled section (e.g. goods still For receiving) is not submitted.
            if (pad.closest('fieldset:disabled')) return;
            if (hasSignature && output.value !== '') return;
            event.preventDefault();
            if (error) error.hidden = false;
            canvas.focus();
        });
    });
}
document.addEventListener('DOMContentLoaded', () => {
    initializeValidationUx();
    initializeAppPageBack();
    initializeAttachmentPreviews();
    initializeSignaturePads();

    function initializeInventoryUnavailableModal() {
        const unavailableModal = document.getElementById('unavailableModal');
        if (!unavailableModal) return;

        const form = unavailableModal.querySelector('form');
        if (!form) return;

        // Let the screening form use Laravel's normal POST/redirect response.
        // This page can be displayed inside the dashboard action iframe; its
        // previous AJAX redirect saved the decision but did not consistently
        // navigate that iframe to the quotation screen.
        return;

        form.addEventListener('submit', async (event) => {
            // The signature pad cancels the submit when no signature was drawn.
            if (event.defaultPrevented) return;
            event.preventDefault();

            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) submitButton.disabled = true;
            const modalBody = unavailableModal.querySelector('.modal-body');
            modalBody?.querySelectorAll('.thank-you-alert, .unavailable-error-alert').forEach((el) => el.remove());
            form.querySelectorAll('.text-danger.unavailable-field-error').forEach((el) => el.remove());

            const showAlert = (className, extraClass, html) => {
                const alert = document.createElement('div');
                alert.className = `alert ${className} mb-3 ${extraClass}`;
                alert.setAttribute('role', 'alert');
                alert.innerHTML = html;
                modalBody?.insertBefore(alert, modalBody.firstElementChild);
            };

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const data = await response.json().catch(() => ({}));

                if (response.ok) {
                    // The unavailable decision immediately opens the quotation
                    // form for the same IT / Premises staff member.
                    window.location.assign(data.quotation_url || window.location.href);
                    return;
                }

                if (data.errors) {
                    Object.entries(data.errors).forEach(([field, messages]) => {
                        const fieldElement = form.querySelector(`[name="${field}"]`);
                        if (!fieldElement) return;
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'text-danger small unavailable-field-error';
                        errorDiv.textContent = [].concat(messages).join(' ');
                        fieldElement.parentNode.insertBefore(errorDiv, fieldElement.nextSibling);
                    });
                }
                const message = data.errors
                    ? 'Please correct the highlighted fields.'
                    : (response.status === 403 || response.status === 409
                        ? 'This request has already moved on or you can no longer act on it. Reload the page to see its current status.'
                        : 'The decision could not be saved. Reload the page to check whether it was recorded before trying again.');
                showAlert('alert-danger', 'unavailable-error-alert', message);
            } catch (error) {
                showAlert('alert-danger', 'unavailable-error-alert', 'The decision could not be saved. Check your connection and try again.');
            }
            if (submitButton) submitButton.disabled = false;
        });

        unavailableModal.addEventListener('hidden.bs.modal', () => {
            const modalBody = unavailableModal.querySelector('.modal-body');
            if (modalBody) {
                modalBody.querySelectorAll('.thank-you-alert').forEach(el => el.remove());
            }
            form.reset();
        });
    }

    // PO approval form handler
    const poApprovalForm = document.getElementById('purchase-order-approval-actions');
    if (poApprovalForm) {
        poApprovalForm.addEventListener('submit', async (event) => {
            // The signature pad cancels the submit when no signature was drawn.
            if (event.defaultPrevented) return;
            event.preventDefault();

            const buttons = poApprovalForm.querySelectorAll('button[name="decision"]');
            buttons.forEach((button) => { button.disabled = true; });
            // Include the clicked button so Reject is sent as REJECT, not the server default.
            const formData = new FormData(poApprovalForm);
            const decision = event.submitter?.value || 'APPROVE';
            formData.set('decision', decision);
            poApprovalForm.querySelectorAll('.po-approval-feedback').forEach((el) => el.remove());

            const showFeedback = (className, text) => {
                const feedback = document.createElement('div');
                feedback.className = `alert ${className} mb-3 po-approval-feedback`;
                feedback.setAttribute('role', 'alert');
                feedback.textContent = text;
                poApprovalForm.insertBefore(feedback, poApprovalForm.querySelector('button[name="decision"]'));
            };

            try {
                const response = await fetch(poApprovalForm.action, {
                    method: 'POST',
                    body: formData,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const data = await response.json().catch(() => ({}));

                if (response.ok) {
                    showFeedback('alert-success', decision === 'REJECT'
                        ? 'Thank you for rejecting! Your decision has been recorded.'
                        : 'Thank you for approving! Your decision has been recorded.');
                    // Reload so the page closes to the submission receipt.
                    window.setTimeout(() => window.location.reload(), 1200);
                    return;
                }

                const messages = data.errors ? Object.values(data.errors).flat() : [];
                showFeedback('alert-danger', messages.join(' ') || (response.status === 403
                    ? 'This Purchase Order can no longer be approved or rejected by you. Reload the page to see its current status.'
                    : data.message || 'The decision could not be saved. Please try again.'));
            } catch (error) {
                showFeedback('alert-danger', 'The decision could not be saved. Check your connection and try again.');
            }
            buttons.forEach((button) => { button.disabled = false; });
            delete poApprovalForm.dataset.submitting;
            poApprovalForm.classList.remove('is-submitting');
            poApprovalForm.removeAttribute('aria-busy');
        });
    }

    initializeInventoryUnavailableModal();
});

function initializePurchaseOrderSubmit() {
    document.querySelectorAll('[data-po-create-form]').forEach((form) => {
        form.querySelectorAll('[data-po-line-amount]').forEach((line) => {
            const quantity = line.querySelector('[data-po-quantity]');
            const price = line.querySelector('[data-po-unit-price]');
            const amount = Number(line.dataset.poLineAmount);
            if (!quantity || !price) return;

            quantity.addEventListener('input', () => {
                const count = Number(quantity.value);
                price.value = count > 0 ? (amount / count).toFixed(2) : '0.00';
            });
        });

        form.addEventListener('submit', (event) => {
            const button = form.querySelector('[data-po-submit]');
            // Signature and client-validation handlers can cancel submission.
            // Do not leave this button in a false loading state in that case.
            if (event.defaultPrevented || !form.checkValidity() || event.submitter !== button) return;

            const label = form.querySelector('[data-po-submit-label]');
            if (button) button.disabled = true;
            if (label) label.textContent = 'Creating purchase order…';
        });
    });
}

document.addEventListener('DOMContentLoaded', initializePurchaseOrderSubmit);

function initializeWorkflowActionModal() {
    const modalElement = document.getElementById('workflow-action-modal');
    const frame = modalElement?.querySelector('[data-workflow-action-frame]');
    const title = modalElement?.querySelector('#workflow-action-modal-title');
    const loading = modalElement?.querySelector('[data-workflow-action-loading]');
    const openPage = modalElement?.querySelector('[data-workflow-action-open]');
    const expand = modalElement?.querySelector('[data-workflow-action-expand]');
    const requestCode = modalElement?.querySelector('[data-workflow-request-code]');

    if (!modalElement || !frame || !title || !window.bootstrap) return;

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
    let opened = false;
    let completionPending = false;

    const closeCompletedAction = () => {
        if (completionPending) modal.hide();
    };
    // Bootstrap ignores hide() during its opening animation. Retry once the
    // action window has finished opening if a very fast submission completed.
    modalElement.addEventListener('shown.bs.modal', closeCompletedAction);

    frame.addEventListener('load', () => {
        if (loading) loading.hidden = true;
        frame.removeAttribute('aria-busy');
        try {
            const document = frame.contentDocument;
            const code = document?.querySelector('meta[name="workflow-request-code"]')?.content;
            if (requestCode && code) {
                requestCode.textContent = code;
                requestCode.hidden = false;
            }
            // A completed POST can reach the receipt even when its inline
            // closing script fails. Let the parent finish the handoff as well.
            if (opened && document?.querySelector('meta[name="workflow-completed"][content="true"]')) {
                const data = document.getElementById('workflow-completion-data');
                try {
                    const result = data ? JSON.parse(data.textContent) : null;
                    if (result?.notice) {
                        sessionStorage.setItem('asi-procura:submission', JSON.stringify(result.notice));
                        sessionStorage.removeItem('asi-procura:flash');
                    } else if (result?.message) {
                        sessionStorage.setItem('asi-procura:flash', result.message);
                    }
                } catch (_) { /* A storage failure must not leave the action window stuck. */ }
                completionPending = true;
                closeCompletedAction();
            }
        } catch (_) {
            // Keep the dialog usable when its destination cannot be inspected.
        }
    });

    document.querySelectorAll('[data-workflow-action-modal]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            if (loading) loading.hidden = false;
            if (requestCode) requestCode.hidden = true;
            frame.setAttribute('aria-busy', 'true');
            frame.src = trigger.href;
            title.textContent = trigger.dataset.workflowActionTitle || 'Workflow action';
            frame.title = title.textContent;
            if (openPage) {
                openPage.href = trigger.href;
                openPage.hidden = false;
            }
            opened = true;
            completionPending = false;
            modal.show();
        });
    });

    expand?.addEventListener('click', () => {
        const expanded = modalElement.classList.toggle('workflow-action-expanded');
        expand.setAttribute('aria-pressed', String(expanded));
        expand.textContent = expanded ? 'Restore size' : 'Expand';
    });

    modalElement.addEventListener('hidden.bs.modal', () => {
        if (!opened) return;

        frame.src = 'about:blank';
        modalElement.classList.remove('workflow-action-expanded');
        if (expand) {
            expand.setAttribute('aria-pressed', 'false');
            expand.textContent = 'Expand';
        }
        opened = false;
        completionPending = false;
        window.location.reload();
    });
}

document.addEventListener('DOMContentLoaded', initializeWorkflowActionModal);

// Inside the action modal, Cancel closes the modal instead of navigating the iframe.
function initializeEmbeddedDismiss() {
    if (!document.documentElement.classList.contains('is-embedded')) return;

    document.querySelectorAll('[data-embedded-dismiss]').forEach((link) => {
        link.addEventListener('click', (event) => {
            try {
                const parentModal = window.parent.document.getElementById('workflow-action-modal');
                const instance = parentModal && window.parent.bootstrap?.Modal.getInstance(parentModal);
                if (!instance) return;
                event.preventDefault();
                instance.hide();
            } catch (_) {
                // Cross-origin parent: fall back to the link's normal navigation.
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initializeEmbeddedDismiss);

// Forms posted from inside the action modal finish on the "done" page, which closes the modal.
function initializeEmbeddedForms() {
    if (!document.documentElement.classList.contains('is-embedded')) return;

    document.querySelectorAll('form[method="POST" i]').forEach((form) => {
        const marker = document.createElement('input');
        marker.type = 'hidden';
        marker.name = '_embedded';
        marker.value = '1';
        form.append(marker);
    });
}

document.addEventListener('DOMContentLoaded', initializeEmbeddedForms);

// Success message handed over by a closed action modal.
function showHandedOverFlash() {
    let notice = null;
    const data = document.getElementById('workflow-submission-data');
    try {
        notice = data ? JSON.parse(data.textContent) : null;
        if (!document.documentElement.classList.contains('is-embedded')) {
            const stored = sessionStorage.getItem('asi-procura:submission');
            sessionStorage.removeItem('asi-procura:submission');
            if (stored) notice = JSON.parse(stored);
        }
    } catch (_) {
        // Server-rendered receipts still work if browser storage is unavailable.
    }
    if (!notice) return;
    const modalElement = document.getElementById('workflow-submission-modal');
    if (!modalElement || !window.bootstrap) return;
    if (notice.user_id && String(notice.user_id) !== modalElement.dataset.submissionUser) return;
    if (document.documentElement.classList.contains('is-embedded')) {
        try {
            sessionStorage.setItem('asi-procura:submission', JSON.stringify(notice));
            const parentModal = window.parent.document.getElementById('workflow-action-modal');
            const instance = parentModal && window.parent.bootstrap?.Modal.getInstance(parentModal);
            if (instance) {
                instance.hide();
                return;
            }
        } catch (_) {
            // Show the receipt inside the frame when its parent cannot be reached.
        }
    }

    try {
        if (sessionStorage.getItem('asi-procura:last-submission') === notice.id) return;
        sessionStorage.setItem('asi-procura:last-submission', notice.id);
        sessionStorage.removeItem('asi-procura:flash');
    } catch (_) { /* A receipt can still be displayed without storage. */ }

    const requests = Array.isArray(notice.requests) ? notice.requests : [];
    modalElement.querySelector('#workflow-submission-title').textContent = notice.title || 'Submission saved';
    modalElement.querySelector('[data-submission-code]').textContent = requests.map(request => request.code).join(', ');
    modalElement.querySelector('[data-submission-message]').textContent = notice.message || 'Your action was saved successfully.';
    modalElement.querySelector('[data-submission-stage]').textContent = notice.stage || '';
    const view = modalElement.querySelector('[data-submission-view]');
    const safeUrl = value => {
        try {
            const url = new URL(value, window.location.origin);
            return url.origin === window.location.origin && ['http:', 'https:'].includes(url.protocol) ? url.href : null;
        } catch (_) { return null; }
    };
    const url = requests[0] && safeUrl(requests[0].url);
    view.hidden = !url;
    if (url) view.href = url;
    const list = modalElement.querySelector('[data-submission-requests]');
    list.replaceChildren();
    list.hidden = requests.length < 2;
    if (requests.length > 1) requests.forEach(request => {
        const link = document.createElement('a');
        const target = safeUrl(request.url);
        if (!target) return;
        link.href = target;
        link.textContent = `View ${request.code}${request.stage ? ' · ' + request.stage : ''}`;
        list.append(link);
    });
    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

document.addEventListener('DOMContentLoaded', showHandedOverFlash);

function initializeWorkflowRequestPreview() {
    const modalElement = document.getElementById('workflow-request-modal');
    const frame = modalElement?.querySelector('[data-workflow-request-frame]');
    const title = modalElement?.querySelector('#workflow-request-modal-title');

    if (!modalElement || !frame || !title || !window.bootstrap) return;

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);

    document.querySelectorAll('[data-workflow-request-preview]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            const previewUrl = new URL(trigger.href, window.location.origin);
            previewUrl.searchParams.set('preview', '1');
            frame.src = previewUrl.toString();
            title.textContent = trigger.dataset.workflowRequestTitle || 'Request preview';
            modal.show();
        });
    });

    modalElement.addEventListener('hidden.bs.modal', () => {
        frame.src = 'about:blank';
    });
}

document.addEventListener('DOMContentLoaded', initializeWorkflowRequestPreview);

function initializeCreatorWorkflowTabs() {
    const tabs = document.querySelector('[data-creator-workflow-tabs]');
    const scrollKey = 'asi-procura:creator-workflow-scroll';

    if (!tabs) return;

    // Each tab is a server-rendered, live queue. Preserve the viewport position
    // between reloads instead of letting browser scroll restoration react to a
    // differently sized queue.
    const savedScroll = sessionStorage.getItem(scrollKey);
    if (savedScroll !== null) {
        sessionStorage.removeItem(scrollKey);
        const scrollTop = Number.parseInt(savedScroll, 10);

        requestAnimationFrame(() => {
            window.scrollTo(0, Number.isFinite(scrollTop) ? scrollTop : 0);
            if ('scrollRestoration' in history) history.scrollRestoration = 'auto';
        });
    }

    tabs.querySelectorAll('a[href]').forEach((tab) => {
        tab.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

            sessionStorage.setItem(scrollKey, String(window.scrollY));
            if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
        });
    });
}

document.addEventListener('DOMContentLoaded', initializeCreatorWorkflowTabs);

function initializeQuotationEditGuards() {
    document.querySelectorAll('[data-unsaved-changes]').forEach((form) => {
        let dirty = false;
        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('change', () => { dirty = true; });
        form.addEventListener('submit', () => { dirty = false; });
        form.querySelectorAll('a').forEach((control) => {
            control.addEventListener('click', (event) => {
                if (dirty && !window.confirm('Discard unsaved quotation changes?')) event.preventDefault();
            });
        });
        form.closest('.modal')?.addEventListener('hide.bs.modal', (event) => {
            if (!dirty) return;
            if (!window.confirm('Discard unsaved quotation changes?')) {
                event.preventDefault();
                return;
            }
            dirty = false;
        });
        window.addEventListener('beforeunload', (event) => {
            if (!dirty) return;
            event.preventDefault();
            event.returnValue = '';
        });
    });
}

document.addEventListener('DOMContentLoaded', initializeQuotationEditGuards);

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('details:has([data-stock-selection])').forEach((details) => {
        details.addEventListener('toggle', () => {
            if (details.open) details.querySelectorAll('[data-signature-pad]').forEach((pad) => pad.dispatchEvent(new Event('signature-pad:refresh')));
        });
    });
});

// Tick rows, then open one form for all of them: Final budget (Budget Request register) and
// Record payment (Finance's Awaiting payment list, marked with data-select-scope).
function initializeBudgetFinalSelection() {
    document.querySelectorAll('[data-br-final]').forEach((bar) => {
        const button = bar.querySelector('[data-br-final-button]');
        const label = bar.querySelector('[data-br-final-label]');
        const hint = bar.querySelector('[data-br-final-hint]');
        const register = bar.closest('.budget-register, [data-select-scope]');
        const baseLabel = label?.dataset.baseLabel ?? 'Final budget';
        if (!button || !register) return;

        const boxes = [...register.querySelectorAll('[data-br-select]')];
        const selectAll = register.querySelector('[data-br-select-all]');
        const peso = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const idleHint = hint?.textContent ?? '';

        const refresh = () => {
            const checked = boxes.filter((box) => box.checked);
            const url = new URL(button.dataset.baseUrl, window.location.origin);
            checked.forEach((box) => url.searchParams.append('ids[]', box.value));
            button.href = url.toString();
            button.classList.toggle('disabled', checked.length === 0);
            button.setAttribute('aria-disabled', checked.length === 0 ? 'true' : 'false');
            if (label) label.textContent = checked.length ? `${baseLabel} (${checked.length})` : baseLabel;
            if (hint) {
                const total = checked.reduce((sum, box) => sum + Number(box.dataset.amount || 0), 0);
                hint.textContent = checked.length ? `${checked.length} selected · ₱ ${peso.format(total)}` : idleHint;
            }
            if (selectAll) {
                selectAll.checked = checked.length > 0 && checked.length === boxes.length;
                selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
            }
        };

        boxes.forEach((box) => box.addEventListener('change', refresh));
        selectAll?.addEventListener('change', () => {
            boxes.forEach((box) => { box.checked = selectAll.checked; });
            refresh();
        });
        refresh();
    });
}

document.addEventListener('DOMContentLoaded', initializeBudgetFinalSelection);

// "+N more" chips in tables: the full list opens on hover or keyboard focus.
function initializeMoreListPopovers() {
    if (!window.bootstrap?.Popover) return;
    document.querySelectorAll('.more-list-chip[data-bs-toggle="popover"]').forEach((chip) => {
        window.bootstrap.Popover.getOrCreateInstance(chip, { container: 'body' });
    });
}

document.addEventListener('DOMContentLoaded', initializeMoreListPopovers);

// Dashboard "Live remarks": latest remarks across POs. The button counts remarks added since the
// viewer last opened the dialog; the dialog marks them "New" and refreshes while open.
function initializeLiveRemarks() {
    const dialog = document.getElementById('live-remarks-dialog');
    const button = document.querySelector('[data-live-remarks-button]');
    if (!dialog || !button) return;
    document.body.appendChild(dialog);
    const body = dialog.querySelector('[data-live-remarks-body]');
    const status = dialog.querySelector('[data-live-remarks-status]');
    const search = dialog.querySelector('[data-live-remarks-search]');
    const badge = button.querySelector('[data-live-remarks-badge]');
    const storageKey = 'asi-procura:live-remarks-seen';
    const readSeen = () => { try { return window.localStorage.getItem(storageKey); } catch { return null; } };
    const writeSeen = () => { try { window.localStorage.setItem(storageKey, new Date().toISOString()); } catch { /* private mode */ } };
    let highlightSince = null;
    let timer = null;
    let searchTimer = null;

    const feedUrl = (params) => {
        const url = new URL(dialog.dataset.liveRemarksUrl, window.location.href);
        Object.entries(params).forEach(([key, value]) => { if (value) url.searchParams.set(key, value); });
        return url;
    };

    const showCount = (count) => {
        badge.hidden = count < 1;
        badge.textContent = count > 99 ? '99+ new' : `${count} new`;
        button.classList.toggle('has-new', count > 0);
    };

    const refreshCount = async () => {
        const since = readSeen();
        if (!since || dialog.classList.contains('show')) return;
        try {
            const response = await fetch(feedUrl({ count: 1, since }), { headers: { Accept: 'application/json' } });
            if (response.ok) showCount((await response.json()).new);
        } catch { /* keep the last count */ }
    };

    const load = async () => {
        dialog.classList.add('is-refreshing');
        try {
            const response = await fetch(feedUrl({ search: search.value.trim(), since: highlightSince }), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) throw new Error(response.status);
            body.innerHTML = await response.text();
            const fresh = body.querySelectorAll('.is-new').length;
            const time = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            status.textContent = fresh ? `${fresh} new since your last visit · updated ${time}` : `Up to date · updated ${time}`;
        } catch {
            status.textContent = 'Could not refresh. Trying again shortly.';
        } finally {
            dialog.classList.remove('is-refreshing');
        }
    };

    dialog.addEventListener('show.bs.modal', () => {
        highlightSince = readSeen();
        writeSeen();
        showCount(0);
        load();
        timer = window.setInterval(load, 30000);
    });
    dialog.addEventListener('shown.bs.modal', () => search.focus());
    dialog.addEventListener('hidden.bs.modal', () => {
        window.clearInterval(timer);
        writeSeen();
    });
    dialog.querySelector('[data-live-remarks-refresh]').addEventListener('click', load);
    search.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(load, 350);
    });

    // First visit: everything so far counts as seen, so the badge only shows genuinely new remarks.
    if (!readSeen()) writeSeen();
    refreshCount();
    window.setInterval(refreshCount, 60000);
}

document.addEventListener('DOMContentLoaded', initializeLiveRemarks);

// Finalized Budget Requests: click a BR row to show or hide its PO lines; collapsed groups are remembered.
function initializeBudgetGroupToggles() {
    const groups = document.querySelectorAll('[data-br-group]');
    if (!groups.length) return;
    const storageKey = 'asi-procura:collapsed-br-groups';
    let collapsed;
    try { collapsed = new Set(JSON.parse(window.localStorage.getItem(storageKey) || '[]')); } catch { collapsed = new Set(); }
    const save = () => { try { window.localStorage.setItem(storageKey, JSON.stringify([...collapsed])); } catch { /* private mode */ } };

    const setOpen = (row, open) => {
        const id = row.dataset.brGroup;
        row.setAttribute('aria-expanded', String(open));
        document.querySelectorAll(`[data-br-group-line="${id}"]`).forEach((line) => { line.hidden = !open; });
        if (open) collapsed.delete(id); else collapsed.add(id);
    };

    groups.forEach((row) => {
        setOpen(row, !collapsed.has(row.dataset.brGroup));
        const toggle = (event) => {
            // The BR code itself still opens the preview.
            if (event.target.closest('a, button')) return;
            setOpen(row, row.getAttribute('aria-expanded') !== 'true');
            save();
        };
        row.addEventListener('click', toggle);
        row.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggle(event); }
        });
    });

    document.querySelectorAll('[data-br-groups-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const open = button.dataset.brGroupsToggle === 'expand';
            groups.forEach((row) => setOpen(row, open));
            save();
        });
    });
}

document.addEventListener('DOMContentLoaded', initializeBudgetGroupToggles);

// Finalized Budget Requests: zoom the table (remembered per browser) and view it full screen.
function initializeBudgetTableZoom() {
    const view = document.querySelector('[data-br-view]');
    const table = view?.querySelector('.budget-preparation-table');
    if (!table) return;
    const storageKey = 'asi-procura:br-table-zoom';
    const levels = [0.9, 1, 1.15, 1.3, 1.5, 1.75];
    const levelLabel = view.querySelector('.budget-preparation-zoom-level');
    let zoom = 1;
    try { zoom = Number.parseFloat(window.localStorage.getItem(storageKey)) || 1; } catch { /* private mode */ }

    const apply = (value) => {
        zoom = Math.min(levels.at(-1), Math.max(levels[0], value));
        table.style.zoom = String(zoom);
        if (levelLabel) levelLabel.textContent = `${Math.round(zoom * 100)}%`;
        view.querySelector('[data-br-zoom="out"]')?.toggleAttribute('disabled', zoom <= levels[0]);
        view.querySelector('[data-br-zoom="in"]')?.toggleAttribute('disabled', zoom >= levels.at(-1));
        try { window.localStorage.setItem(storageKey, String(zoom)); } catch { /* private mode */ }
    };

    view.querySelectorAll('[data-br-zoom]').forEach((button) => {
        button.addEventListener('click', () => {
            const index = levels.findIndex((level) => level >= zoom - 0.001);
            if (button.dataset.brZoom === 'in') apply(levels[Math.min(index + 1, levels.length - 1)]);
            else if (button.dataset.brZoom === 'out') apply(levels[Math.max(index - 1, 0)]);
            else apply(1);
        });
    });
    apply(zoom);

    const fullscreenButton = view.querySelector('[data-br-fullscreen]');
    const fullscreenLabel = view.querySelector('[data-br-fullscreen-label]');
    if (!fullscreenButton) return;
    if (!document.fullscreenEnabled) {
        fullscreenButton.hidden = true;
        return;
    }
    fullscreenButton.addEventListener('click', () => {
        if (document.fullscreenElement) document.exitFullscreen();
        else view.requestFullscreen().catch(() => {});
    });
    document.addEventListener('fullscreenchange', () => {
        const isFull = document.fullscreenElement === view;
        view.classList.toggle('is-fullscreen', isFull);
        if (fullscreenLabel) fullscreenLabel.textContent = isFull ? 'Exit full screen' : 'Full screen';
    });
}

document.addEventListener('DOMContentLoaded', initializeBudgetTableZoom);
