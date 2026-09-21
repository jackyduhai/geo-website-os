{{-- ProductCard：无重框 / 3px 绿顶 / 1:1 图位 / 名 16/24 / 一句话 13/20 最多 2 行。
     变量：$p（facts 产品），可选 $lineName。核心产品进详情，其余回总览体系锚点。 --}}
@php
  $isCore = \App\Support\Catalog::isCoreProduct($p['slug']);
  $href = $isCore ? url('/products/' . $p['slug']) : url('/products/#' . ($p['line'] ?? ''));
  // 无产品图时使用数据自带 icon 或中性占位图标，不在此绑定具体产品线 slug
  $icon = $p['icon'] ?? 'default';
  $img = $p['image'] ?? null;
@endphp
<a class="prod-card" href="{{ $href }}">
  <div class="prod-img @if(!$img) is-empty @endif">
    @if($img)
      <x-picture :src="asset($img)" :alt="$p['name']" loading="lazy" width="300" height="300" />
    @else
      <span class="ph-chip">@include('site._icon', ['name' => $icon, 'cls' => 'ph-ic'])</span>
    @endif
  </div>
  <div class="prod-bd">
    @if(!empty($lineName))<span class="prod-tag">{{ $lineName }}</span>@endif
    <h3>{{ $p['short_name'] ?? $p['name'] }}</h3>
    <p>{{ $p['tagline'] ?? '' }}</p>
    <span class="prod-go">
      {{ $isCore ? '查看参数与用量' : '查看该系列' }}
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
    </span>
  </div>
</a>
