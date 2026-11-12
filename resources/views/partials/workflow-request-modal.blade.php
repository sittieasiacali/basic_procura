<div class="modal fade" id="workflow-request-modal" tabindex="-1" aria-labelledby="workflow-request-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable workflow-action-modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="preview-modal-brand">
                    <img class="preview-modal-logo" src="{{ asset('assets/images/Allianz_logo.png') }}" alt="Allianz">
                    <h2 class="modal-title fs-5" id="workflow-request-modal-title">Request preview</h2>
                </div>
                <button class="btn btn-sm btn-outline-secondary workflow-action-close" type="button" data-bs-dismiss="modal" aria-label="Close preview">
                    <x-ui.icon name="x-circle" size="18" /><span>Close</span>
                </button>
            </div>
            <div class="modal-body p-0">
                <iframe class="workflow-action-frame" title="Request preview" data-workflow-request-frame name="procura-workflow-request" allow="fullscreen" allowfullscreen></iframe>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
