{{--
    Reusable design-system pagination — consumed via `{{ $paginator->links('partials.pagination') }}`.
    Preserves the current query string (search term, status tab) across pages. Renders nothing
    for a single-page result. Waves 2-4 reuse this for their own lists.
--}}
@if ($paginator->hasPages())
    <nav class="cbx-pagination" role="navigation" aria-label="Pagination">
        <span class="cbx-pagination-summary">
            {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }}
        </span>
        <div class="cbx-pagination-pages">
            @if ($paginator->onFirstPage())
                <span class="is-disabled" aria-disabled="true">‹ Prev</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Prev</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="is-gap">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="is-active" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next ›</a>
            @else
                <span class="is-disabled" aria-disabled="true">Next ›</span>
            @endif
        </div>
    </nav>
@endif
