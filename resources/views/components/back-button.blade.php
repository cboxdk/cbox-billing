{{--
    Reusable "Back to X" ghost button for detail pages — <x-back-button :href="..." label="Back to customers" />.
    Uses the left-chevron glyph (a back-pointing icon), never the forward chevron-right. Reuse this
    on every detail page so the back-nav stays visually identical and always points the right way.
--}}
@props(['href' => '#', 'label' => 'Back'])
<a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ $href }}" style="align-self:flex-start">@include('partials.icon', ['name' => 'chevron-left', 'size' => 14, 'sw' => 1.7]) {{ $label }}</a>
