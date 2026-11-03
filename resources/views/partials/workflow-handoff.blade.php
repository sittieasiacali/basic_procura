@if(session('workflow_request_code'))
    <div class="alert alert-success d-flex flex-wrap align-items-center gap-2" role="status">
        <strong>Request {{ session('workflow_request_code') }}</strong>
        <span>&middot; {{ session('workflow_next_stage') }}</span>
    </div>
@endif
