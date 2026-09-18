{{-- S03 五大产品体系（2 大 + 3 小；数据来自 Facts，产品数与链接由数据决定） --}}
@php
  $lines = $productLines ?? [];
  if ($lines instanceof \Illuminate\Support\Collection) { $lines = $lines->all(); }
  $lineIcon = ['seasoning' => 'flame', 'prepared-chicken' => 'drumstick', 'flavor' => 'sparkle', 'coating' => 'shaker', 'spices' => 'beaker'];
  $featureSlugs = ['seasoning', 'prepared-chicken'];
  $lineHref = function ($l) {
      return ($l['count'] ?? 0) >= 4 ? url('/products/' . $l['slug'] . '/') : url('/products/#' . $l['slug']);
  };
@endphp
@if(! empty($lines))
<section class="sec" id="s03">
  <div class="wrap">
    <div class="sec-head row">
      <div>
        <span class="eyebrow">PRODUCT SYSTEM · 产品体系</span>
        <h2 class="sec-h">{{ $blk->title ?: '五大产品体系，覆盖从Sample Marinade到Sample Breading的完整风味方案' }}</h2>
        <p class="sec-sub">{{ $blk->subtitle ?: '一套风味从研发、打样到量产的完整产品支撑。' }}</p>
      </div>
      <a class="btn-text" href="{{ url('/products/') }}">查看全部产品<span class="arr">→</span></a>
    </div>
    <div class="pgrid">
      @foreach($lines as $line)
        @php $isFeature = in_array($line['slug'], $featureSlugs, true); @endphp
        <a class="pcard {{ $isFeature ? 'pcard-feature' : '' }} reveal" href="{{ $lineHref($line) }}">
          <span class="feat-ic">@include('site._icon', ['name' => $lineIcon[$line['slug']] ?? 'default'])</span>
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
