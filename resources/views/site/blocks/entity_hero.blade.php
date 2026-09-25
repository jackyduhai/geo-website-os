{{-- Entity Detail 系统块 · 详情头（Entity 直驱，不可手动添加）。
     Product：定位 Hero（含关键参数卡 / 主料 / CTA）；Service：问句式 Hero。
     数据来自当前 Entity + Catalog 读模型（viewContext），不复制进 Page。 --}}
@php
  $isService = ($entity->type ?? '') === 'service';
@endphp

@if($isService)
  <section class="page-hero">
    <div class="wrap-narrow">
      <span class="eyebrow">{{ __('ui.eyebrow_solution') }}</span>
      <h1 class="ph-h">{{ $scene['title_q'] ?? $scene['name'] }}</h1>
      <p class="ph-lead">{{ $scene['desc'] }}</p>
    </div>
  </section>
@else
  <section class="page-hero prod-hero">
    <div class="wrap prod-hero-in">
      <div>
        <span class="prod-tag-line">{{ $product['tag'] ?? $line['name'] ?? '' }}</span>
        <h1 class="ph-h">{{ $product['name'] }}</h1>
        <p class="ph-lead">{{ $product['tagline'] }}</p>
        @if(! empty($product['mains']))
          <div class="prod-mains">
            <span class="pm-k">{{ __('ui.mains_label') }}</span>
            @foreach($product['mains'] as $m)<em>{{ $m }}</em>@endforeach
          </div>
        @endif
        <div class="actions" style="margin-top:var(--sp-7)">
          <a class="btn btn-primary btn-lg" href="{{ url('/') }}#s08">{{ $ctaText ?? __('ui.bcta_secondary') }}<span class="arr">→</span></a>
          <a class="btn btn-secondary btn-lg" href="{{ url('/contact/') }}">{{ __('ui.bcta_secondary') }}</a>
        </div>
      </div>
      @if(! empty($product['key_params']))
      <div class="prod-hero-card">
        <span class="phc-cap">{{ __('ui.keycap') }}</span>
        @include('site._param_table', ['rows' => array_map(
            fn ($kp) => ['label' => $kp['label'], 'value' => $kp['value']],
            $product['key_params'] ?? []
        )])
      </div>
      @endif
    </div>
  </section>
@endif
