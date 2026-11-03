<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Saved &middot; {{ config('app.name') }}</title>
    <meta name="workflow-request-code" content="{{ session('workflow_request_code') }}">
</head>
<body style="font-family: 'Open Sans', Arial, sans-serif; color: #160d76; padding: 2rem; text-align: center;">
    @if(session('workflow_request_code'))
        <h1>Request {{ session('workflow_request_code') }}</h1>
        <p>{{ session('workflow_next_stage') }}</p>
    @endif
    <p>{{ session('success', 'Saved.') }}</p>
    <p><a href="{{ route('dashboard') }}">Back to dashboard</a></p>
    <script>
        // Hand the message to the dashboard, then close the action modal (closing reloads the dashboard).
        (function () {
            var message = @json(session('workflow_request_code') ? trim(session('workflow_request_code').' · '.session('workflow_next_stage').' '.session('success', 'Saved.')) : session('success', 'Saved.'));
            var notice = @json(session('workflow_submission_notice'));
            var dashboard = @json(route('dashboard'));
            try {
                if (notice) {
                    sessionStorage.setItem('asi-procura:submission', JSON.stringify(notice));
                    sessionStorage.removeItem('asi-procura:flash');
                } else {
                    sessionStorage.setItem('asi-procura:flash', message);
                }
                var parentModal = window.parent !== window && window.parent.document.getElementById('workflow-action-modal');
                var instance = parentModal && window.parent.bootstrap && window.parent.bootstrap.Modal.getInstance(parentModal);
                if (instance) {
                    instance.hide();
                    return;
                }
            } catch (_) {
                // Not inside the dashboard modal: fall through to a normal page load.
            }
            window.top.location.replace(dashboard);
        })();
    </script>
</body>
</html>
