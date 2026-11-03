// Inventory release modal: one shared form, pointed at the item whose Release button opened it.
document.addEventListener('DOMContentLoaded', () => {
    // A CSV import that failed validation reopens its modal with the row errors.
    document.querySelectorAll('[data-open-on-load]').forEach((element) => window.bootstrap?.Modal.getOrCreateInstance(element).show());

    const modal = document.getElementById('inventory-release-modal');
    if (modal) {
        const form = modal.querySelector('[data-inventory-release-form]');
        const quantity = form.querySelector('input[name="quantity"]');

        modal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget;
            if (!trigger?.dataset.inventoryReleaseUrl) return;

            form.action = trigger.dataset.inventoryReleaseUrl;
            form.reset();
            form.querySelector('[data-signature-clear]')?.click();
            quantity.max = trigger.dataset.inventoryReleaseMax;
            modal.querySelector('[data-inventory-release-label]').textContent = trigger.dataset.inventoryReleaseName;
            modal.querySelector('[data-inventory-release-available]').textContent =
                `(${trigger.dataset.inventoryReleaseMax} ${trigger.dataset.inventoryReleaseUnit} available)`;
        });
    }

    const claimModal = document.getElementById('inventory-claim-modal');
    if (claimModal) {
        const claimForm = claimModal.querySelector('[data-inventory-claim-form]');
        claimModal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget;
            if (!trigger?.dataset.inventoryClaimUrl) return;

            claimForm.action = trigger.dataset.inventoryClaimUrl;
            claimForm.reset();
            claimForm.querySelector('[data-claim-release-id]').value = trigger.dataset.inventoryClaimId;
            claimForm.querySelector('[data-signature-clear]')?.click();
            const signerName = trigger.dataset.inventoryClaimRecipient;
            claimForm.querySelector('input[name="signature_name"]').value = signerName;
            claimForm.querySelector('.approval-signature-box__account').textContent = `Sign as: ${signerName}`;
            claimModal.querySelector('[data-inventory-claim-label]').textContent = trigger.dataset.inventoryClaimName;
        });
        if (claimModal.dataset.reopenClaimId) {
            const trigger = Array.from(document.querySelectorAll('[data-inventory-claim-id]'))
                .find((button) => button.dataset.inventoryClaimId === claimModal.dataset.reopenClaimId);
            if (trigger && window.bootstrap?.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(claimModal).show(trigger);
            }
        }
    }
});
