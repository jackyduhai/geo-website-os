@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@php
  $wsIcons = ['package', 'sliders', 'gear', 'shield', 'factory'];
  // H1 只陈述真实存在的生产事实：面积 / 车间 / 产能缺失即不写入，绝不裸输出 0
  // （FactoryController 已用 hasProduction() 保证至少一项，否则该页 404）。
  $factoryH1Parts = [];
  if ((int) ($company['area_sqm'] ?? 0) > 0) {
      $factoryH1Parts[] = __('ui.fh_area', ['num' => number_format((int) $company['area_sqm'])]);
  }
  if (count($workshops) > 0) {
      $factoryH1Parts[] = __('ui.fh_workshops', ['num' => count($workshops)]);
  }
  if ((int) ($company['annual_capacity_tons'] ?? 0) > 0) {
      $factoryH1Parts[] = __('ui.fh_capacity', ['num' => number_format((int) $company['annual_capacity_tons'])]);
  }
  $factoryH1 = implode(\App\Support\Localization\LocaleContext::current() === \App\Support\Localization\LocaleRegistry::default() ? '，' : ', ', $factoryH1Parts);
@endphp

@section('content')
<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">{{ __('ui.eyebrow_factory') }}</span>
    <h1 class="ph-h">{{ $factoryH1 }}</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

{{-- 数据条 --}}
<section class="stats" aria-label="{{ __('ui.stats_aria') }}">
  <div class="wrap stats-in">
    @foreach($stats as $s)
      <div class="stat reveal">
        <div class="stat-n"><span data-count="{{ $s['num'] }}">0</span><i>{{ $s['unit'] }}</i></div>
        <div class="stat-l">{{ $s['label'] }}</div>
      </div>
    @endforeach
  </div>
</section>

{{-- 生产车间（无实拍图时用统一线性图标，不渲染示意图占位） --}}
<section class="sec">
  <div class="wrap-wide">
    <div class="sec-head">
      <span class="eyebrow">{{ __('ui.eyebrow_workshops') }}</span>
      <h2 class="sec-h">{{ __('ui.workshops_h2', ['num' => count($workshops)]) }}</h2>
    </div>
    <div class="ws-grid4">
      @foreach($workshops as $i => $w)
        <div class="ws4-card reveal">
          @if(!empty($w['image']))
            <x-picture img-class="ws4-img" :src="asset($w['image'])" :alt="$w['image_alt'] ?? $w['name']" loading="lazy" width="720" height="480" />
          @else
            <span class="feat-ic">@include('site._icon', ['name' => $wsIcons[$i] ?? 'factory', 'size' => 28])</span>
          @endif
          <h3>{{ $w['name'] }}</h3>
          <p>{{ $w['desc'] }}</p>
        </div>
      @endforeach
    </div>
  </div>
</section>

{{-- 生产流程 --}}
@if(!empty($steps))
<section class="sec sec-tint">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">{{ __('ui.eyebrow_process') }}</span>
      <h2 class="sec-h">{{ __('ui.factory_process_h2', ['num' => count($steps)]) }}</h2>
    </div>
    @include('site._process_steps', ['steps' => $steps])
  </div>
</section>
@endif

{{-- 资质与标准：SC 号 / 执行标准号未核齐前整体隐藏，不出现标题、不留空位 --}}
@if($certsReady)
<section class="sec">
  <div class="wrap-narrow">
    <div class="sec-head"><span class="eyebrow">{{ __('ui.eyebrow_certification') }}</span><h2 class="sec-h">{{ __('ui.cert_h2') }}</h2></div>
    <dl class="facts auto">
      @foreach($certs as $label => $val)
        @if(filled($val))<div class="fact"><dt>{{ $label }}</dt><dd>{{ $val }}</dd></div>@endif
      @endforeach
    </dl>
  </div>
</section>
@endif

{{-- 销售覆盖区域（纯文字，无合规地图前不放地图） --}}
<section class="sec {{ $certsReady ? 'sec-tint' : '' }}">
  <div class="wrap-narrow">
    <div class="sec-head">
      <span class="eyebrow">{{ __('ui.eyebrow_coverage') }}</span>
      <h2 class="sec-h">{{ __('ui.coverage_h2', ['num' => count($regions)]) }}</h2>
    </div>
    <div class="region-tags">
      @foreach($regions as $r)<em>{{ $r }}</em>@endforeach
    </div>
    <p class="prose" style="margin-top:24px">{{ __('ui.factory_address') }}{{ $company['address']['full'] }}</p>
  </div>
</section>

@include('site._bottom_cta', ['variant' => 'factory'])
@endsection
