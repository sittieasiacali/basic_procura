<div class="modal fade" id="request-preview-modal" tabindex="-1" aria-labelledby="request-preview-heading" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <button class="mobile-modal-back" type="button" data-bs-dismiss="modal" aria-label="Back">&#8249;</button>
                <div>
                    <h2 class="modal-title fs-5" id="request-preview-heading">Request details</h2>
                    <p class="small text-muted mb-0" data-request-preview-reference>Loading saved record…</p>
                </div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-2 py-5 justify-content-center" data-request-preview-loading><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Loading request…</span></div>
                <div class="alert alert-danger d-none" role="alert" data-request-preview-error></div>
                <div class="d-none" data-request-preview-content></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                <a class="btn btn-primary d-inline-flex align-items-center gap-2 disabled" href="#" target="_blank" rel="noopener" data-request-preview-print aria-disabled="true"><x-ui.icon name="file-text" size="16" />Print request</a>
            </div>
        </div>
    </div>
</div>
