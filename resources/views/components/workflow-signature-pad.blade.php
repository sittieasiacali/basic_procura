@props([
    'id',
    'label' => 'Electronic signature',
    'instruction' => 'Draw your signature before confirming this workflow action.',
])

<section {{ $attributes->class(['approval-signature-pad']) }} data-signature-pad aria-labelledby="{{ $id }}-heading">
    <span class="approval-signature-box__label" id="{{ $id }}-heading">{{ $label }}</span>
    <strong class="approval-signature-box__account">Sign as: {{ auth()->user()->name }}</strong>
    <canvas class="approval-signature-pad__canvas" data-signature-canvas width="760" height="190" tabindex="0" aria-label="Draw your electronic signature"></canvas>
    <input type="hidden" name="signature_name" value="{{ auth()->user()->name }}">
    <input type="hidden" name="signature_data" data-signature-data>
    <div class="approval-signature-pad__footer">
        <small>{{ $instruction }}</small>
        <button class="btn btn-sm btn-outline-primary" type="button" data-signature-clear>Clear signature</button>
    </div>
    <div class="field-validation-message" data-signature-client-error hidden>Draw your signature before confirming this decision.</div>
    @error('signature_name')<div class="field-validation-message">{{ $message }}</div>@enderror
    @error('signature_data')<div class="field-validation-message">{{ $message }}</div>@enderror
</section>