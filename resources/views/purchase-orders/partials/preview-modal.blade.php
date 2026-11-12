<div class="modal fade" id="purchase-order-review-modal" tabindex="-1" aria-labelledby="purchase-order-review-heading" aria-hidden="true" @if($shouldAutoOpenPurchaseOrderPreview ?? false) data-auto-open="true" data-po-preview-url="{{ route('purchase-orders.preview', $purchaseOrder) }}" @endif>
    <div class="modal-dialog modal-xl modal-dialog-scrollable po-preview-modal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="mobile-modal-back" type="button" data-bs-dismiss="modal" aria-label="Back"><x-ui.icon name="chevron-left" size="24" /></button>
                <div><h2 class="modal-title fs-5" id="purchase-order-review-heading">PO Details</h2><p class="small text-muted mb-0" data-po-preview-reference>Loading saved record…</p></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-2 py-5 justify-content-center" data-po-preview-loading><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Loading Purchase Order…</span></div>
                <div class="alert alert-danger d-none" role="alert" data-po-preview-error></div>
                <div class="d-none" data-po-preview-content>
                    <ul class="nav nav-tabs po-preview-tabs mb-3" role="tablist">
                        <li class="nav-item" role="presentation"><button class="nav-link active" id="po-details-template-tab" data-bs-toggle="tab" data-bs-target="#po-details-template-pane" type="button" role="tab" aria-controls="po-details-template-pane" aria-selected="true">PO Details</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" id="po-print-template-tab" data-bs-toggle="tab" data-bs-target="#po-print-template-pane" type="button" role="tab" aria-controls="po-print-template-pane" aria-selected="false">Official Purchase Order</button></li>
                    </ul>
                    <div class="tab-content"><div class="tab-pane fade show active" id="po-details-template-pane" role="tabpanel" aria-labelledby="po-details-template-tab" tabindex="0" data-po-preview-details></div><div class="tab-pane fade" id="po-print-template-pane" role="tabpanel" aria-labelledby="po-print-template-tab" tabindex="0" data-po-preview-document></div></div>
                </div>
            </div>
            <div class="modal-footer"><span class="small text-muted me-auto d-none" data-po-export-feedback role="status"></span><button class="btn btn-outline-primary d-none" type="button" data-po-task-edit-open>Edit</button><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button><button class="btn btn-primary d-inline-flex align-items-center gap-2" type="button" data-export-purchase-order disabled><x-ui.icon name="file-text" size="16" />Print selected template</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="purchase-order-task-edit-modal" tabindex="-1" aria-labelledby="purchase-order-task-edit-heading" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div><h2 class="modal-title fs-5" id="purchase-order-task-edit-heading">Edit Purchase Order</h2><p class="small text-muted mb-0">You can edit only your own task. Every change is recorded below.</p></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" data-po-task-edit-body></div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-po-task-edit-back>Back to preview</button></div>
        </div>
    </div>
</div>
