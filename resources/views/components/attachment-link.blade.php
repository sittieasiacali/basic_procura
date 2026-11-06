{{--
    Link to an uploaded file. Images and PDFs open in the in-page viewer (with full
    screen); other files download. Pass `thumbnail` to show a small image preview.
--}}
@props(['attachment', 'label' => null, 'thumbnail' => false])

@php
    $viewUrl = route('attachments.view', $attachment);
    $downloadUrl = route('attachments.download', $attachment);
    $previewable = $attachment->isPreviewable();
    $kind = $attachment->isImage() ? 'image' : ($attachment->isPdf() ? 'pdf' : 'file');
@endphp

<span {{ $attributes->class(['attachment-link']) }}>
    <x-ui.icon name="paperclip" size="13" />
    @if($label)<span class="text-muted">{{ $label }}:</span>@endif
    <a href="{{ $previewable ? $viewUrl : $downloadUrl }}"
       @if($previewable)
           target="_blank" rel="noopener"
           data-attachment-viewer
           data-attachment-kind="{{ $kind }}"
           data-attachment-name="{{ $attachment->original_name }}"
           data-attachment-download="{{ $downloadUrl }}"
       @endif>{{ $attachment->original_name }}</a>
    @if($thumbnail && $attachment->isImage())
        <a class="attachment-thumbnail" href="{{ $viewUrl }}" target="_blank" rel="noopener"
           data-attachment-viewer data-attachment-kind="image"
           data-attachment-name="{{ $attachment->original_name }}"
           data-attachment-download="{{ $downloadUrl }}"
           aria-label="View {{ $attachment->original_name }} full size">
            <img src="{{ $viewUrl }}" alt="{{ $label ? $label.': ' : '' }}{{ $attachment->original_name }}" loading="lazy">
        </a>
    @endif
</span>
