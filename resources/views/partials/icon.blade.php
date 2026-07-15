{{-- Lucide outline icons (the design system's product-app icon set).
     Usage: @include('partials.icon', ['name' => 'home', 'size' => 17]) --}}
@php($size = $size ?? 16)
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $sw ?? 1.6 }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
    @case('home') <path d="M3 12 12 3l9 9"/><path d="M5 10v10h14V10"/> @break
    @case('repeat') <path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/> @break
    @case('invoice') <path d="M4 2h11l5 5v15a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z"/><path d="M14 2v5h5"/><path d="M8 13h8"/><path d="M8 17h5"/> @break
    @case('activity') <path d="M22 12h-4l-3 9L9 3l-3 9H2"/> @break
    @case('box') <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/> @break
    @case('building') <rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M8 10h.01"/><path d="M16 10h.01"/><path d="M8 14h.01"/><path d="M16 14h.01"/> @break
    @case('settings') <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 0 1-4 0v-.1A1.6 1.6 0 0 0 7 19.4l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3 12a1.6 1.6 0 0 0-1.1-1.5H2a2 2 0 0 1 0-4h.1A1.6 1.6 0 0 0 3.6 5L3.5 5a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 9 3.6V3.5a2 2 0 0 1 4 0v.1A1.6 1.6 0 0 0 15 5l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8v.1a1.6 1.6 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-1.5.9Z"/> @break
    @case('plus') <path d="M5 12h14"/><path d="M12 5v14"/> @break
    @case('search') <circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/> @break
    @case('chevron-down') <path d="m6 9 6 6 6-6"/> @break
    @case('chevron-right') <path d="m9 18 6-6-6-6"/> @break
    @case('check') <path d="M20 6 9 17l-5-5"/> @break
    @case('moon') <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/> @break
    @case('grid') <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/> @break
    @case('bell') <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/> @break
    @case('arrow-up-right') <path d="M7 17 17 7"/><path d="M7 7h10v10"/> @break
    @case('panel') <path d="M9 3v18"/><rect x="3" y="3" width="18" height="18" rx="2"/> @break
    @case('pin') <path d="M12 17v5"/><path d="M9 10.8V6a3 3 0 1 1 6 0v4.8l2.7 2.7a1 1 0 0 1-.7 1.7H7a1 1 0 0 1-.7-1.7Z"/> @break
    @case('wallet') <path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-2"/><path d="M21 12v3h-4a1.5 1.5 0 0 1 0-3Z"/> @break
    @case('receipt') <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8"/><path d="M8 11h8"/><path d="M8 15h5"/> @break
    @case('gauge') <path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/> @break
    @default <circle cx="12" cy="12" r="9"/>
@endswitch
</svg>
