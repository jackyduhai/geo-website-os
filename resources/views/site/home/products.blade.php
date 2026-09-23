{{-- S03 产品体系（数据来自 Catalog 站点目录读模型，产品数与链接由数据决定；系列卡等宽呈现） --}}
@php
  $lines = $productLines ?? [];
  if ($lines instanceof \Illuminate\Support\Collection) { $lines = $lines->all(); }
  // 通用图标序列：按产品线顺序取中性图标，不绑定任何具体行业 / slug；数据可通过 icon 字段覆盖
  $lineIconSeq = ['package', 'grid', 'gear', 'star', 'shield', 'truck', 'sparkle', 'default'];
  $lineHref = function ($l) {
      return ($l['count'] ?? 0) >= 1 ? url('/products/' . $l['slug'] . '/') : url('/products/#' . $l['slug']);
  };
@endphp
@if(! empty($lines))
<section class="sec" id="s03">
  <div class="wrap">
    <div class="sec-head row">
      <div>
        <span class="eyebrow">{{ __('ui.eyebrow_product_system') }}</span>
        <h2 class="sec-h">{{ $blk->title ?: __('ui.big_lines', ['count' => count($lines)]) }}</h2>
        <p class="sec-sub">{{ $blk->subtitle ?: __('ui.home_products_sub') }}</p>
      </div>
      <a class="btn-text" href="{{ url('/products/') }}">{{ __('ui.home_products_btn') }}<span class="arr">→</span></a>
    </div>
    <div class="pgrid">
      @foreach($lines as $line)
        <a class="pcard reveal" href="{{ $lineHref($line) }}">
          <span class="feat-ic">@include('site._icon', ['name' => $line['icon'] ?? ($lineIconSeq[$loop->index] ?? 'default')])</span>
          <div>
            <h3>{{ $line['name'] }}<span class="pcard-count">{{ __('ui.unit_count', ['count' => $line['count'] ?? 0]) }}</span></h3>
            <p>{{ $line['desc'] ?? '' }}</p>
            <span class="go">{{ __('ui.cat_view_line') }}<span class="arr">→</span></span>
          </div>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif
