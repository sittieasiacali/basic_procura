{{--
    Search bar and filters for a single table, configured through one $toolbar array
    (included views inherit the parent's variables, so loose names would collide).
    Field names default to "{prefix}_search", "{prefix}_status", "{prefix}_from" and
    "{prefix}_to"; every other query value (page-level filters, other tables) is carried
    along so each table filters independently.
--}}
@php
    $prefix = $toolbar['prefix'];
    $searchName = $toolbar['searchName'] ?? $prefix.'_search';
    $statusName = $toolbar['statusName'] ?? $prefix.'_status';
    $fromName = $prefix.'_from';
    $toName = $prefix.'_to';
    $pageName = $toolbar['pageName'] ?? $prefix.'_page';
    $statusOptions = $toolbar['statusOptions'] ?? [];
    $dateLabel = $toolbar['dateLabel'] ?? null;
    $formAction = $toolbar['action'] ?? url()->current();
    $anchor = isset($toolbar['anchor']) ? '#'.$toolbar['anchor'] : '';
    $ownKeys = array_merge([$searchName, $pageName], $statusOptions ? [$statusName] : [], $dateLabel ? [$fromName, $toName] : []);
    $carried = collect(request()->query())->except($ownKeys)->filter(fn ($queryValue) => is_string($queryValue) && $queryValue !== '');
    $isFiltered = collect($ownKeys)->contains(fn ($key) => $key !== $pageName && filled(request($key)));
    $fieldId = fn (string $name) => 'table-filter-'.str_replace('_', '-', $name);
@endphp

<form class="table-toolbar" method="GET" action="{{ $formAction }}{{ $anchor }}" role="search" aria-label="{{ $toolbar['label'] ?? 'Filter table' }}">
    @foreach($carried as $carriedName => $carriedValue)
        <input type="hidden" name="{{ $carriedName }}" value="{{ $carriedValue }}">
    @endforeach
    <div class="table-toolbar-field table-toolbar-search">
        <label class="visually-hidden" for="{{ $fieldId($searchName) }}">Search</label>
        <span class="table-toolbar-search-icon" aria-hidden="true"><x-ui.icon name="search" size="15" /></span>
        <input class="form-control form-control-sm" id="{{ $fieldId($searchName) }}" type="search" name="{{ $searchName }}" value="{{ is_string(request($searchName)) ? request($searchName) : '' }}" placeholder="{{ $toolbar['searchPlaceholder'] ?? 'Search…' }}" maxlength="200">
    </div>
    @if($statusOptions)
        <div class="table-toolbar-field">
            <label class="visually-hidden" for="{{ $fieldId($statusName) }}">{{ $toolbar['statusLabel'] ?? 'Status' }}</label>
            <select class="form-select form-select-sm" id="{{ $fieldId($statusName) }}" name="{{ $statusName }}">
                <option value="">{{ $toolbar['statusPlaceholder'] ?? 'All statuses' }}</option>
                @foreach($statusOptions as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected(request($statusName) === (string) $optionValue)>{{ $optionLabel }}</option>
                @endforeach
            </select>
        </div>
    @endif
    @if($dateLabel)
        <div class="table-toolbar-field table-toolbar-dates">
            <label class="visually-hidden" for="{{ $fieldId($fromName) }}">{{ $dateLabel }} from</label>
            <input class="form-control form-control-sm" id="{{ $fieldId($fromName) }}" type="date" name="{{ $fromName }}" value="{{ is_string(request($fromName)) ? request($fromName) : '' }}" title="{{ $dateLabel }} from">
            <span class="small text-muted" aria-hidden="true">to</span>
            <label class="visually-hidden" for="{{ $fieldId($toName) }}">{{ $dateLabel }} to</label>
            <input class="form-control form-control-sm" id="{{ $fieldId($toName) }}" type="date" name="{{ $toName }}" value="{{ is_string(request($toName)) ? request($toName) : '' }}" title="{{ $dateLabel }} to">
        </div>
    @endif
    <div class="table-toolbar-actions">
        <button class="btn btn-sm btn-primary" type="submit">Filter</button>
        @if($isFiltered)
            <a class="btn btn-sm btn-outline-secondary" href="{{ $formAction }}{{ $carried->isNotEmpty() ? '?'.http_build_query($carried->all()) : '' }}{{ $anchor }}">Clear</a>
        @endif
    </div>
</form>
