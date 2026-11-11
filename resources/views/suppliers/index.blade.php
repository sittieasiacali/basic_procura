@extends('layouts.app')
@section('title', 'Supplier Management')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><h1 class="h3 mb-1">Supplier Management</h1><p class="text-muted mb-0">{{ $team ? $teams[$team].' supplier directory' : 'All team supplier directories' }}</p></div>
    <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">Dashboard</a>
</div>
<p class="text-muted">IT, Premises, and Purchasing maintain separate suppliers. Deactivated suppliers retain their procurement history.</p>
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<details class="card mb-3" @if(old('_supplier_form') === 'new') open @endif>
    <summary class="card-header">Add supplier</summary>
    <div class="card-body"><form method="POST" action="{{ route('suppliers.store') }}">@csrf
        @include('suppliers.partials.fields', ['supplier' => null])
        <button class="btn btn-primary mt-3">Add supplier</button>
    </form></div>
</details>
<form class="row g-2 mb-3" method="GET" action="{{ route('suppliers.index') }}">
    <div class="col-md-7"><label class="visually-hidden" for="supplier-search">Search suppliers</label><input class="form-control" id="supplier-search" name="search" value="{{ $search }}" placeholder="Search suppliers"></div>
    @if(count($teams) > 1)
        <div class="col-md-3"><label class="visually-hidden" for="supplier-team-filter">Team</label><select class="form-select" id="supplier-team-filter" name="team"><option value="">All teams</option>@foreach($teams as $value => $label)<option value="{{ $value }}" @selected($team === $value)>{{ $label }}</option>@endforeach</select></div>
    @endif
    <div class="col-auto"><button class="btn btn-outline-primary">Search</button></div>
</form>
@forelse($suppliers as $supplier)
    <details class="card mb-2" @if(old('_supplier_form') === (string) $supplier->id) open @endif>
        <summary class="card-header">{{ $supplier->name }} <span class="badge text-bg-light">{{ \App\Models\Supplier::TEAMS[$supplier->team] }}</span> <span class="badge {{ $supplier->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $supplier->is_active ? 'Active' : 'Inactive' }}</span> <span class="small text-muted">{{ $supplier->quotations_count }} quotation lines</span></summary>
        <div class="card-body"><form method="POST" action="{{ route('suppliers.update', $supplier) }}">@csrf @method('PATCH')
            @include('suppliers.partials.fields', ['supplier' => $supplier])
            <button class="btn btn-primary mt-3">Save supplier</button>
        </form></div>
    </details>
@empty<div class="card p-4 text-center"><h2 class="h5">No suppliers found</h2><p class="text-muted mb-0">Add a supplier to this team's directory or adjust your search.</p></div>@endforelse
{{ $suppliers->links() }}
@endsection
