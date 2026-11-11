@extends('layouts.app')

@section('title', 'Final Budget')

@section('content')
@php
    $total = $budgetRequests->sum(fn ($budget) => (float) $budget->amount);
@endphp
<div class="budget-create-page budget-create-page--compact budget-create-page--wide procura-form-page">
    <x-ui.form-page-header class="budget-create-header" eyebrow="Final budget" title="Final Budget" description="Check the selected Budget Requests. Each one gets its BR code and moves to Payment." icon="wallet" />

    <form method="POST" action="{{ route('budget.finalize.store') }}" class="budget-create-layout budget-create-layout--wide" data-validation-context="final-budget" novalidate>
        @csrf
        @foreach($budgetRequests as $budgetRequest)
            <input type="hidden" name="ids[]" value="{{ $budgetRequest->id }}">
        @endforeach

        <main class="budget-create-main">
            @error('ids')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
            @error('status')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror

            <ol class="budget-stepper" aria-label="Budget Request progress">
                <li class="is-done"><span>1</span>Prepared by Budget Maker</li>
                <li class="is-current" aria-current="step"><span>2</span>Final budget</li>
                <li><span>3</span>Payment</li>
            </ol>

            <section class="budget-create-pos procura-form-section procura-form-context" aria-labelledby="final-selected-heading">
                <div class="budget-create-section-heading budget-create-card-heading"><span class="budget-create-heading-icon"><x-ui.icon name="receipt" size="21" /></span><div><div class="section-kicker">Prepared by Budget Maker</div><h2 id="final-selected-heading">Selected Budget Requests</h2><p>Untick rows in the list to leave them out.</p></div><span class="budget-create-count">{{ $budgetRequests->count() }} selected</span></div>
                @include('budget-requests.partials.preparation-table', ['preparationBudgets' => $budgetRequests, 'showPriority' => false])
                @foreach($budgetRequests as $selectedBudget)
                    @foreach($selectedBudget->lines as $line)
                        @include('budget-requests.partials.funding-contributions', ['fundingLine' => $line])
                    @endforeach
                @endforeach
            </section>

            <section class="budget-create-card procura-form-section" aria-label="Priority and remarks">
                <div class="budget-create-field-grid">
                    <div class="budget-create-field">
                        <label for="final-priority-level">Priority Level <span aria-hidden="true">*</span></label>
                        <select class="form-select @error('priority_level') is-invalid @enderror" id="final-priority-level" name="priority_level" required aria-describedby="final-priority-help">
                            @foreach(\App\Models\PurchaseRequest::PRIORITY_LEVELS as $value => $label)
                                <option value="{{ $value }}" @selected(old('priority_level', 'NORMAL') === $value)>{{ $value === 'NORMAL' ? 'Medium' : $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text" id="final-priority-help">Applies to every PO in this group BR.</div>
                        @error('priority_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="budget-create-field budget-create-field--wide"><label for="final-remarks">Remarks <span class="budget-create-optional">(optional)</span></label><textarea class="form-control @error('remarks') is-invalid @enderror" id="final-remarks" name="remarks" rows="2" maxlength="2000" placeholder="Notes for Payment">{{ old('remarks') }}</textarea>@error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                </div>
            </section>

            <section class="budget-create-card procura-form-section" aria-label="Final budget signature">
                <x-workflow-signature-pad id="final-budget-signature" label="Your signature (required)" instruction="Draw your signature to confirm the Final budget for the selected Budget Requests." />
            </section>

            <div class="budget-create-actions">
                <a class="btn btn-outline-secondary budget-create-cancel" href="{{ route('dashboard') }}" data-embedded-dismiss>Cancel</a>
                <div class="budget-create-actions-total"><span>Total for {{ $budgetRequests->count() }} {{ str('Budget Request')->plural($budgetRequests->count()) }}</span><strong>&#8369; {{ number_format($total, 2) }}</strong></div>
                <button class="btn btn-primary budget-create-submit" type="submit">Final budget &amp; issue BR {{ $budgetRequests->count() === 1 ? 'code' : 'codes' }}</button>
            </div>
        </main>
    </form>
</div>
@endsection
