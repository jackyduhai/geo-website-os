{{-- 产品体系图标：栏目 slug → 统一图标库语义名（唯一来源 site._icon）。变量：$slug --}}
@php
  $slugIcon = [
    'chinese-marinade' => 'flame',
    'western-marinade' => 'droplet',
    'special-marinade' => 'sparkle',
    'coating-powder'   => 'shaker',
    'prepared-chicken' => 'package',
  ];
  $iconName = $slugIcon[$slug ?? ''] ?? 'default';
@endphp
@include('site._icon', ['name' => $iconName])
