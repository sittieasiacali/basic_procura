{{-- Bulk manual stock entry from a CSV (IT / Premises / Admin). Include only when the user can create inventory items. --}}
<div class="modal fade" id="inventory-import-modal" tabindex="-1" aria-labelledby="inventory-import-heading" aria-hidden="true" @error('inventory_csv') data-open-on-load @enderror>
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('inventory.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-header">
                <div><h2 class="modal-title fs-5" id="inventory-import-heading">Import inventory items</h2><p class="small text-muted mb-0">Add many items at once from a CSV file.</p></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <ol class="inventory-import-steps">
                    <li>
                        <strong>Download the template</strong> and fill one item per row.
                        <a class="btn btn-sm btn-outline-primary ms-1" href="{{ route('inventory.import-template') }}" download><x-ui.icon name="file-text" size="14" /> Download template</a>
                    </li>
                    <li><strong>Upload the CSV.</strong> All rows are checked first; if any row has a problem, nothing is added and the row numbers are listed.</li>
                </ol>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0 inventory-import-columns">
                        <thead class="table-light"><tr><th>Column</th><th>Required</th><th>Example</th></tr></thead>
                        <tbody>
                            <tr><td><code>description</code></td><td>Yes</td><td>Wireless mouse</td></tr>
                            <tr><td><code>quantity</code></td><td>Yes, more than 0</td><td>10</td></tr>
                            <tr><td><code>unit</code></td><td>Yes</td><td>pc</td></tr>
                            <tr><td><code>serial_number</code></td><td>No</td><td>SN-12345</td></tr>
                            <tr><td><code>location</code></td><td>No</td><td>IT storage room</td></tr>
                        </tbody>
                    </table>
                </div>
                <label class="form-label" for="inventory-import-file">CSV file</label>
                <input class="form-control @error('inventory_csv') is-invalid @enderror" id="inventory-import-file" type="file" name="inventory_csv" accept=".csv,text/csv" required aria-describedby="inventory-import-help">
                <div class="form-text" id="inventory-import-help">Up to {{ number_format(500) }} rows and 2 MB. A unique SKU and inventory code are generated for every row.</div>
                @error('inventory_csv')
                    <div class="alert alert-danger mt-3 mb-0" role="alert">
                        <strong>Nothing was imported.</strong>
                        <ul class="mb-0 mt-1">@foreach($errors->get('inventory_csv') as $message)<li>{{ $message }}</li>@endforeach</ul>
                    </div>
                @enderror
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Import items</button>
            </div>
        </form>
    </div>
</div>
