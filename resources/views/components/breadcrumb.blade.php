{{--
    Reusable breadcrumb trail — <x-breadcrumb :items="[...]" />. Each item is
    ['label' => 'Acme', 'href' => route(...)]; omit `href` (or make it the last item) to
    render a plain, muted current-page label. Rendered inside the topbar `.crumb`, after the
    org/environment context, so deep pages show the real path
    (e.g. Customers › Acme › Subscription #123). Waves 2-4 set breadcrumbs the same way.
--}}
@props(['items' => []])
@foreach ($items as $item)
    <span class="sep">/</span>
    @if (!empty($item['href']) && !$loop->last)
        <a href="{{ $item['href'] }}" class="crumb-link" style="color:var(--muted-foreground);text-decoration:none">{{ $item['label'] }}</a>
    @else
        <span class="muted">{{ $item['label'] }}</span>
    @endif
@endforeach
