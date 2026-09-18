{{--
  Example Website · 统一线性图标库（全站唯一来源）
  用法：@include('site._icon', ['name' => 'flame', 'size' => 24])
  规范：24×24 viewBox、stroke=currentColor、圆角线帽；颜色由父级 color 控制。
  可用 name：flame/droplet/sparkle/shaker/package/sliders/factory/repeat/
            leaf/drumstick/jar/flask/check/truck/award/shield/users/gear/
            chart/doc/phone/clock/star/beaker/mouse/chicken/grid/default
--}}
@php
  $size = isset($size) ? (int)$size : 24;
  $paths = [
    'flame'    => '<path d="M12 22c3.9 0 6.5-2.6 6.5-6.4 0-3.4-2.2-5.9-4.6-8.3-.4.9-1 1.6-1.9 2 .1-2.4-.9-4.6-2.6-6.3C8.9 5.6 5.5 9.4 5.5 15.6 5.5 19.4 8.1 22 12 22z"/><path d="M12 22c-1.6 0-2.8-1.1-2.8-2.7 0-1.4.9-2.4 1.8-3.3.4.9 1.3 1.4 2.1 1.1"/>',
    'droplet'  => '<path d="M12 3.2s6 6.3 6 10.4a6 6 0 0 1-12 0C6 9.5 12 3.2 12 3.2z"/><path d="M9.5 14.5a2.5 2.5 0 0 0 2.5 2.5"/>',
    'sparkle'  => '<path d="M12 3l1.8 4.9L18.7 9.7 13.8 11.5 12 16.4 10.2 11.5 5.3 9.7 10.2 7.9z"/><path d="M18.5 15l.8 2.1 2.1.8-2.1.8-.8 2.1-.8-2.1-2.1-.8 2.1-.8z"/>',
    'shaker'   => '<path d="M9 3.5h6"/><path d="M9.2 3.5v2.6L8.4 9h7.2l-.8-2.9V3.5"/><path d="M7.2 9h9.6l1 11a1 1 0 0 1-1 1.1H7.2a1 1 0 0 1-1-1.1z"/><path d="M9 13.5h6"/>',
    'package'  => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/><path d="M7.5 5.5l9 5"/>',
    'sliders'  => '<path d="M4 7h10"/><path d="M18 7h2"/><circle cx="16" cy="7" r="2.2"/><path d="M4 17h4"/><path d="M12 17h8"/><circle cx="10" cy="17" r="2.2"/>',
    'factory'  => '<path d="M3 21V10l6 4V10l6 4V6l6-3v18z"/><path d="M3 21h18"/><path d="M7 17h.01M11 17h.01M15 17h.01"/>',
    'repeat'   => '<path d="M17 3l3 3-3 3"/><path d="M20 6H8a4 4 0 0 0-4 4v1"/><path d="M7 21l-3-3 3-3"/><path d="M4 18h12a4 4 0 0 0 4-4v-1"/>',
    'leaf'     => '<path d="M11 20C6 20 3 16 3 10c0-3 2-6 18-7 0 9-3 17-10 17z"/><path d="M3 21c3-5 6-7 10-8"/>',
    'drumstick'=> '<path d="M15.5 8.5a4.5 4.5 0 0 0-6.2 5.2c-1.6 1.2-3.6 3.2-3.3 5 .3 1.7 1.8 2.6 3.4 2.3 1.7-.3 3.3-2 4.4-3.4a4.5 4.5 0 0 0 5.2-6.1l-2.4 2.4-2.6-2.6z"/>',
    'jar'      => '<path d="M8 3h8v3H8z"/><path d="M7 6h10v14a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1z"/><path d="M9.5 12h5M9.5 15.5h5"/>',
    'flask'    => '<path d="M9 3h6"/><path d="M10 3v5.5L5.2 17a2 2 0 0 0 1.8 3h10a2 2 0 0 0 1.8-3L14 8.5V3"/><path d="M7.5 14h9"/>',
    'check'    => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.2l2.3 2.3 4.7-4.8"/>',
    'truck'    => '<path d="M3 6h11v9H3z"/><path d="M14 9h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
    'award'    => '<circle cx="12" cy="9" r="5.5"/><path d="M8.6 13.7L7.5 21l4.5-2.3L16.5 21l-1.1-7.3"/>',
    'shield'   => '<path d="M12 3l7 3v5c0 4.6-3 8.4-7 10-4-1.6-7-5.4-7-10V6z"/><path d="M9.2 12l2 2 3.6-3.8"/>',
    'users'    => '<circle cx="9" cy="8.5" r="3.2"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="M16 5.6a3.2 3.2 0 0 1 0 6"/><path d="M17.5 14.6a5.5 5.5 0 0 1 3 5"/>',
    'gear'     => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.8v2.4M12 18.8v2.4M21.2 12h-2.4M5.2 12H2.8M18.5 5.5l-1.7 1.7M7.2 16.8l-1.7 1.7M18.5 18.5l-1.7-1.7M7.2 7.2L5.5 5.5"/>',
    'chart'    => '<path d="M4 4v16h16"/><path d="M8 15v-4M12 15V8M16 15v-6"/>',
    'doc'      => '<path d="M6 2.8h8l4 4V21a.8.8 0 0 1-.8.8H6a.8.8 0 0 1-.8-.8V3.6A.8.8 0 0 1 6 2.8z"/><path d="M14 2.8V7h4"/><path d="M9 12h6M9 15.5h6"/>',
    'phone'    => '<path d="M5 3.5h3l1.5 4.5-2 1.4a12 12 0 0 0 5.1 5.1l1.4-2 4.5 1.5v3a1.6 1.6 0 0 1-1.7 1.6A16 16 0 0 1 3.4 5.2 1.6 1.6 0 0 1 5 3.5z"/>',
    'clock'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    'star'     => '<path d="M12 3.5l2.5 5.2 5.7.8-4.1 4 1 5.7L12 16.5l-5.1 2.7 1-5.7-4.1-4 5.7-.8z"/>',
    'beaker'   => '<path d="M9 3h6"/><path d="M10 3v6L4.6 18A1.8 1.8 0 0 0 6.2 21h11.6a1.8 1.8 0 0 0 1.6-3L14 9V3"/><path d="M7 15.5h10"/>',
    'mouse'    => '<rect x="7" y="3" width="10" height="18" rx="5"/><path d="M12 7v4"/>',
    'chicken'  => '<path d="M16 4a2.2 2.2 0 1 0 0 .01"/><path d="M15 8.5c2.6.4 4.5 2.3 4.5 4.9 0 3.6-3.4 6.6-7.5 6.6S4.5 17 4.5 13.4C4.5 9.7 7.4 7 11 6.6"/><path d="M11 6.6l2.6-2.3L15 8.5"/><path d="M9 13.5l-2.5 2"/>',
    'grid'     => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
    'arrow'    => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
    'default'  => '<path d="M20 7L12 3 4 7l8 4 8-4z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/>',
  ];
  $svg = $paths[$name ?? ''] ?? $paths['default'];
@endphp<svg class="{{ $cls ?? '' }}" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $svg !!}</svg>
