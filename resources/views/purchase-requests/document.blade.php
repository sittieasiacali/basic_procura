<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $purchaseRequest->request_number }} · Purchase Request</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="po-document-page">
    <header class="po-document-toolbar no-print">
        <x-ui.back-button :fallback="route('dashboard')" />
        <div class="po-document-toolbar-context">
            <strong>Purchase Request</strong>
            <small>Saved request details and signed approval trail</small>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="button" onclick="window.print()">Print / Save as PDF</button>
        </div>
    </header>
    <main class="request-document-page">
        @include('purchase-requests.partials.request-document')
    </main>
    @if($mode === 'print')
        <script>window.addEventListener('load', () => window.setTimeout(() => window.print(), 150));</script>
    @endif
</body>
</html>
