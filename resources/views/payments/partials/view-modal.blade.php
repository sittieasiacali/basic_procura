{{-- Processed payment preview: a summary header, then the payment details and its transaction sheet as tabs. --}}
<div class="modal fade payment-view-modal" id="payment-view-modal" tabindex="-1" aria-labelledby="payment-view-heading" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header payment-view-header">
                <span class="payment-view-icon" aria-hidden="true"><x-ui.icon name="payment" size="22" /></span>
                <div class="payment-view-title">
                    <div class="payment-view-kicker">Processed payment</div>
                    <h2 class="modal-title" id="payment-view-heading"><span data-payment-field="code"></span> <span class="payment-view-status" data-payment-field="status" data-payment-status></span></h2>
                    <p><span data-payment-field="method"></span> · paid <span data-payment-field="paid_at"></span> · BR <span data-payment-field="budget_request"></span></p>
                </div>
                <div class="payment-view-amount"><span>Amount</span><strong data-payment-field="amount"></strong></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <ul class="nav payment-view-tabs" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" id="payment-view-details-tab" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#payment-view-details-pane" aria-controls="payment-view-details-pane" aria-selected="true"><x-ui.icon name="file-text" size="15" />Payment details</button></li>
                <li class="nav-item" role="presentation" data-payment-sheet-tab><button class="nav-link" id="payment-view-sheet-tab" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#payment-view-sheet-pane" aria-controls="payment-view-sheet-pane" aria-selected="false"><x-ui.icon name="receipt" size="15" />Transaction sheet</button></li>
            </ul>
            <div class="modal-body tab-content">
                <div class="tab-pane fade show active" id="payment-view-details-pane" role="tabpanel" aria-labelledby="payment-view-details-tab" tabindex="0">
                    @foreach([
                        'Payment' => ['method' => 'Payment method', 'channel' => 'Payment channel', 'type' => 'Payment type', 'reference' => 'Reference number', 'invoice' => 'Invoice number', 'paid_at' => 'Payment date'],
                        'Linked records' => ['budget_request' => 'Budget Request', 'purchase_order' => 'Purchase Order', 'request' => 'Purchase Request'],
                        'Recorded' => ['recorded_by' => 'Recorded by', 'recorded_at' => 'Recorded at'],
                    ] as $group => $fields)
                        <section class="payment-view-group">
                            <h3>{{ $group }}</h3>
                            <dl class="payment-view-details">
                                @foreach($fields as $field => $label)
                                    <div><dt>{{ $label }}</dt><dd data-payment-field="{{ $field }}"></dd></div>
                                @endforeach
                            </dl>
                        </section>
                    @endforeach
                    <section class="payment-view-group">
                        <h3>Receipt evidence</h3>
                        <ul class="payment-view-receipts list-unstyled mb-0" data-payment-receipts></ul>
                    </section>
                    <section class="payment-view-group">
                        <h3>Details</h3>
                        <p class="payment-view-note" data-payment-field="details"></p>
                    </section>
                </div>
                <div class="tab-pane fade" id="payment-view-sheet-pane" role="tabpanel" aria-labelledby="payment-view-sheet-tab" tabindex="0">
                    <div class="br-preview-sheet payment-view-sheet" data-payment-sheet-body></div>
                </div>
            </div>
            <div class="modal-footer">
                <a class="btn btn-outline-primary d-inline-flex align-items-center gap-2 me-auto" href="#" target="_blank" rel="noopener" data-payment-sheet-print hidden><x-ui.icon name="file-text" size="15" />Print transaction sheet</a>
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
