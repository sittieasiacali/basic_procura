// Requestor dashboard: one Void form shared by every pending card.
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('void-request-modal');
    const form = modal?.querySelector('[data-void-form]');
    if (!modal || !form) return;

    modal.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        if (!trigger?.dataset.voidUrl) return;
        form.action = trigger.dataset.voidUrl;
        form.reset();
        modal.querySelector('[data-void-number]').textContent = trigger.dataset.voidNumber;
        modal.querySelector('[data-void-title]').textContent = trigger.dataset.voidTitle;
        modal.querySelector('[data-void-stage]').textContent = trigger.dataset.voidStage;
        // Drafts and returned requests have no handling staff, so they are voided at once.
        const immediate = trigger.dataset.voidImmediate === '1';
        modal.querySelector('[data-void-message-acknowledged]')?.toggleAttribute('hidden', immediate);
        modal.querySelector('[data-void-message-immediate]')?.toggleAttribute('hidden', !immediate);
    });
    modal.addEventListener('shown.bs.modal', () => form.querySelector('textarea')?.focus());
});
