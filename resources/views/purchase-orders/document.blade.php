<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $purchaseRequest->purchaseOrder->po_number }} · Purchase Order</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="po-document-page">
    <header class="po-document-toolbar no-print">
        <x-ui.back-button :fallback="route('purchase-requests.show', $purchaseRequest) . '#purchase-order-summary'" />
        <div class="po-document-toolbar-context">
            <strong>Purchase Order</strong>
            <small>Saved PO values and approved supplier payment details</small>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if($canPrint)
                <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print PO</button>
                <button class="btn btn-primary" type="button" onclick="window.print()">Export / Save as PDF</button>
            @else
                <span class="small text-muted">Printing is available after final approval.</span>
            @endif
        </div>
    </header>
    <main id="purchase-order-print-root">
        @include('purchase-requests.partials.purchase-order-official')
    </main>
    @if($canPrint && in_array($mode, ['print', 'export'], true))
        <script>window.addEventListener('load', () => window.setTimeout(() => window.print(), 150));</script>
    @endif
</body>
</html>
