{{-- S03 产品体系（数据来自 Facts，产品数与链接由数据决定；系列卡等宽呈现） --}}
@php
  $lines = $productLines ?? [];
  if ($lines instanceof \Illuminate\Support\Collection) { $lines = $lines->all(); }
  // 通用图标序列：按产品线顺序取中性图标，不绑定任何具体行业 / slug；数据可通过 icon 字段覆盖
  $lineIconSeq = ['droplet', 'package', 'sparkle', 'shaker', 'beaker', 'flask', 'gear', 'default'];
  $lineHref = function ($l) {
      return ($l['count'] ?? 0) >= 1 ? url('/products/' . $l['slug'] . '/') : url('/products/#' . $l['slug']);
  };
@endphp
@if(! empty($lines))
<section class="sec" id="s03">
  <div class="wrap">
    <div class="sec-head row">
      <div>
        <span class="eyebrow">PRODUCT SYSTEM · 产品体系</span>
        <h2 class="sec-h">{{ $blk->title ?: count($lines).' 大产品系列，覆盖从研发到量产的完整方案' }}</h2>
        <p class="sec-sub">{{ $blk->subtitle ?: '从研发、打样到量产的完整产品支撑。' }}</p>
      </div>
      <a class="btn-text" href="{{ url('/products/') }}">查看全部产品<span class="arr">→</span></a>
    </div>
    <div class="pgrid">
      @foreach($lines as $line)
        <a class="pcard reveal" href="{{ $lineHref($line) }}">
          <span class="feat-ic">@include('site._icon', ['name' => $line['icon'] ?? ($lineIconSeq[$loop->index] ?? 'default')])</span>
          <div>
            <h3>{{ $line['name'] }}<span class="pcard-count">{{ $line['count'] ?? 0 }} 款</span></h3>
            <p>{{ $line['desc'] ?? '' }}</p>
            <span class="go">查看系列<span class="arr">→</span></span>
          </div>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif
