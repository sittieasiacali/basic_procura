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
    });
    modal.addEventListener('shown.bs.modal', () => form.querySelector('textarea')?.focus());
});
