<div class="modal fade" id="budget-request-preview-modal" tabindex="-1" aria-labelledby="budget-request-preview-heading" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable po-preview-modal br-preview-modal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="mobile-modal-back" type="button" data-bs-dismiss="modal" aria-label="Back">&#8249;</button>
                <div><h2 class="modal-title fs-5" id="budget-request-preview-heading">Budget request details</h2><p class="small text-muted mb-0" data-br-preview-reference>Loading saved record...</p></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-2 py-5 justify-content-center" data-br-preview-loading><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Loading Budget Request...</span></div>
                <div class="alert alert-danger d-none" role="alert" data-br-preview-error></div>
                <div class="d-none" data-br-preview-content>
                    <div class="br-preview-sheet" data-br-preview-transaction></div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button><a class="btn btn-primary" data-br-preview-print href="#" target="_blank" rel="noopener">Print / Save as PDF</a></div>
        </div>
    </div>
</div>
