@php($paginator->appends(request()->query()))
<nav class="tb-pagination" role="navigation" aria-label="Pagination">
    <p class="tb-pagination__summary" aria-live="polite">
        Showing {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }}
        of {{ $paginator->total() }} {{ \Illuminate\Support\Str::plural('result', $paginator->total()) }}
    </p>

    <ul class="tb-pagination__controls">
        @if ($paginator->onFirstPage())
            <li><span class="tb-pagination__link is-disabled" aria-disabled="true">Previous</span></li>
        @else
            <li><a class="tb-pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Go to previous page">Previous</a></li>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <li><span class="tb-pagination__ellipsis" aria-hidden="true">{{ $element }}</span></li>
            @elseif (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page === $paginator->currentPage())
                        <li><span class="tb-pagination__link is-current" aria-current="page" aria-label="Page {{ $page }}">{{ $page }}</span></li>
                    @else
                        <li><a class="tb-pagination__link" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a></li>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <li><a class="tb-pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Go to next page">Next</a></li>
        @else
            <li><span class="tb-pagination__link is-disabled" aria-disabled="true">Next</span></li>
        @endif
    </ul>
</nav>
