<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Saved &middot; {{ config('app.name') }}</title>
</head>
<body style="font-family: 'Open Sans', Arial, sans-serif; color: #160d76; padding: 2rem; text-align: center;">
    <p>{{ session('success', 'Saved.') }}</p>
    <p><a href="{{ route('dashboard') }}">Back to dashboard</a></p>
    <script>
        // Hand the message to the dashboard, then close the action modal (closing reloads the dashboard).
        (function () {
            var message = @json(session('success', 'Saved.'));
            var dashboard = @json(route('dashboard'));
            try {
                var parentModal = window.parent !== window && window.parent.document.getElementById('workflow-action-modal');
                var instance = parentModal && window.parent.bootstrap && window.parent.bootstrap.Modal.getInstance(parentModal);
                window.parent.sessionStorage.setItem('asi-procura:flash', message);
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
