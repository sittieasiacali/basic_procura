@extends('layouts.app')

@section('title', 'Manage Data')

@section('content')
    <x-ui.page-header eyebrow="Administration" title="Manage Data" description="Find and delete application records, accounts, and setup data.">
        <x-slot:actions><a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">Dashboard</a></x-slot:actions>
    </x-ui.page-header>

    @if($errors->any())
        <div class="alert alert-danger" role="alert"><strong>Record was not deleted.</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <div class="alert alert-warning">
        Every deletion requires a reason and is recorded in the audit history. Request-related deletions notify the requestor's assigned OM.
        Delete or reassign linked records first. Your signed-in account cannot be deleted.
    </div>
    <div class="card mb-3"><div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4"><label class="form-label" for="record-type">Record type</label><select class="form-select" id="record-type" name="type">@foreach($types as $value => $label)<option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label" for="record-search">Search</label><input class="form-control" id="record-search" name="search" value="{{ request('search') }}" placeholder="Record ID, reference, name, or email"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Find records</button></div>
        </form>
    </div></div>
    <div class="card"><div class="card-header d-flex justify-content-between"><strong>{{ $types[$type] }}</strong><span>{{ $records->total() }} record(s)</span></div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead><tr><th>ID</th><th>Record</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead>
            <tbody>
                @forelse($records as $record)
                    <tr>
                        <td>{{ $record->getKey() }}</td>
                        <td>{{ \App\Support\AdminRecordCatalog::label($record) }}</td>
                        <td>{{ $record->getRawOriginal('status') ?? ($record->getRawOriginal('is_active') === null ? '—' : ($record->is_active ? 'Active' : 'Inactive')) }}</td>
                        <td>{{ $record->getRawOriginal('updated_at') ?? $record->getRawOriginal('created_at') ?? '—' }}</td>
                        <td>
                            <details><summary class="text-danger">Delete record</summary>
                                <form method="POST" action="{{ route('admin.data.destroy', ['type' => $type, 'id' => $record->getKey()], false) }}" class="mt-2" style="min-width: 250px;">
                                    @csrf
                                    @method('DELETE')
                                    <label class="form-label" for="delete-reason-{{ $record->getKey() }}">Reason for deleting #{{ $record->getKey() }}</label>
                                    <textarea class="form-control mb-2" id="delete-reason-{{ $record->getKey() }}" name="reason" minlength="5" maxlength="2000" rows="2" required></textarea>
                                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirmation" value="1" id="delete-confirm-{{ $record->getKey() }}" required><label class="form-check-label" for="delete-confirm-{{ $record->getKey() }}">Delete {{ \App\Support\AdminRecordCatalog::label($record) }}</label></div>
                                    <button class="btn btn-sm btn-danger" type="submit">Confirm deletion</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No records found.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        @if($records->hasPages())<div class="card-footer">{{ $records->links() }}</div>@endif
    </div>
@endsection
