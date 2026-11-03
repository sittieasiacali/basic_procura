<div class="modal fade" id="workflow-action-modal" tabindex="-1" aria-labelledby="workflow-action-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable workflow-action-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="workflow-action-modal-title">Workflow action</h2>
                <button class="btn workflow-action-close" type="button" data-bs-dismiss="modal" aria-label="Close action">
                    <x-ui.icon name="x-circle" size="22" />
                </button>
            </div>
            <div class="modal-body p-0">
                <div class="workflow-action-loading text-muted" data-workflow-action-loading hidden role="status">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    Loading form&hellip;
                </div>
                <iframe class="workflow-action-frame" title="Workflow action" data-workflow-action-frame></iframe>
            </div>
        </div>
    </div>
</div>
