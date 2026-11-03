<div class="modal fade" id="live-remarks-dialog" tabindex="-1" aria-labelledby="live-remarks-dialog-title" aria-hidden="true" data-live-remarks-url="{{ route('workflow-remarks.feed') }}">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header remarks-dialog-header">
                <span class="remarks-dialog-icon" aria-hidden="true"><x-ui.icon name="message-square" size="20" /></span>
                <div class="me-auto">
                    <h2 class="modal-title fs-5" id="live-remarks-dialog-title">Live remarks</h2>
                    <div class="live-remarks-status" aria-live="polite"><span class="live-remarks-dot" aria-hidden="true"></span><span data-live-remarks-status>Loading&hellip;</span></div>
                </div>
                <button class="btn btn-sm btn-outline-secondary live-remarks-refresh" type="button" data-live-remarks-refresh aria-label="Refresh remarks"><x-ui.icon name="flow" size="14" /> Refresh</button>
                <button class="btn-close ms-2" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="live-remarks-toolbar">
                <span class="live-remarks-search-icon" aria-hidden="true"><x-ui.icon name="search" size="15" /></span>
                <input class="form-control" type="search" placeholder="Search PO number, request or title" aria-label="Search remarks" data-live-remarks-search>
            </div>
            <div class="modal-body p-0" data-live-remarks-body>
                <div class="live-remarks-loading" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Loading remarks&hellip;</div>
            </div>
            <div class="modal-footer live-remarks-footer">
                <span class="me-auto small text-muted">Newest first · updates every 30 seconds while open</span>
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
