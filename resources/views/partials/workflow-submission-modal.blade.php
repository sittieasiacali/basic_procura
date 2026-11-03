<div class="modal fade" id="workflow-submission-modal" tabindex="-1" aria-labelledby="workflow-submission-title" aria-hidden="true" data-bs-backdrop="static" data-submission-user="{{ auth()->id() }}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content workflow-submission-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="workflow-submission-title">Submission saved</h2>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close submission confirmation"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="workflow-submission-icon"><x-ui.icon name="check-circle" size="28" /></span>
                    <div><div class="small text-muted">Request code</div><strong class="fs-4" data-submission-code></strong></div>
                </div>
                <p data-submission-message></p>
                <div class="workflow-submission-stage" data-submission-stage></div>
                <div class="d-flex flex-column gap-2 mt-3" data-submission-requests hidden></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <a class="btn btn-primary" data-submission-view>View request</a>
            </div>
        </div>
    </div>
</div>
@if(session('workflow_submission_notice'))
    <script type="application/json" id="workflow-submission-data">@json(session('workflow_submission_notice'))</script>
@endif
