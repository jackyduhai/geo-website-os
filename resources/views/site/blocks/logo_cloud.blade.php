{{-- 通用组合 Block · Logo 墙：合作品牌 / 客户 Logo 灰度展示。 --}}
@php
  $c = $block->cfg();
  $lcTitle = trim((string) ($c['title'] ?? ''));
  $lcLogos = [];
  foreach (is_array($c['items'] ?? null) ? $c['items'] : [] as $i) {
      $m = ! empty($i['media_id']) ? \App\Models\Media::find((int) $i['media_id']) : null;
      if ($m) { $lcLogos[] = ['media' => $m, 'name' => trim((string) ($i['name'] ?? ''))]; }
  }
@endphp
@if(! empty($lcLogos))
  <section class="sec">
    <div class="wrap">
      @if($lcTitle)<div class="sec-head center"><h2 class="sec-h">{{ $lcTitle }}</h2></div>@endif
      <div class="logo-cloud" style="display:flex;flex-wrap:wrap;gap:var(--sp-9);align-items:center;justify-content:center">
        @foreach($lcLogos as $lg)
          <img src="{{ $lg['media']->url() }}" alt="{{ $lg['name'] }}" loading="lazy"
               style="height:40px;width:auto;object-fit:contain;filter:grayscale(1);opacity:.85">
        @endforeach
      </div>
    </div>
  </section>
@endif
