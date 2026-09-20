@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@php $wsIcons = ['package', 'sliders', 'gear', 'shield', 'factory']; @endphp

@section('content')
<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">FACTORY · 工厂与资质</span>
    <h1 class="ph-h">自有约 {{ number_format($company['area_sqm']) }} ㎡ 厂区，{{ count($workshops) }} 个车间，年产能约 {{ number_format($company['annual_capacity_tons']) }} 吨</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

{{-- 数据条 --}}
<section class="stats" aria-label="关键数据">
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
      <span class="eyebrow">WORKSHOPS · 生产车间</span>
      <h2 class="sec-h">{{ count($workshops) }} 个车间，全在自己厂里</h2>
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
      <span class="eyebrow">PROCESS · 生产流程</span>
      <h2 class="sec-h">从原料到成品的 {{ count($steps) }} 步生产流程</h2>
    </div>
    @include('site._process_steps', ['steps' => $steps])
  </div>
</section>
@endif

{{-- 资质与标准：SC 号 / 执行标准号未核齐前整体隐藏，不出现标题、不留空位 --}}
@if($certsReady)
<section class="sec">
  <div class="wrap-narrow">
    <div class="sec-head"><span class="eyebrow">CERTIFICATION · 资质与标准</span><h2 class="sec-h">资质与标准</h2></div>
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
      <span class="eyebrow">COVERAGE · 销售覆盖</span>
      <h2 class="sec-h">覆盖全国 {{ count($regions) }} 大销售区域</h2>
    </div>
    <div class="region-tags">
      @foreach($regions as $r)<em>{{ $r }}</em>@endforeach
    </div>
    <p class="prose" style="margin-top:24px">厂区地址：{{ $company['address']['full'] }}</p>
  </div>
</section>

@include('site._bottom_cta', ['variant' => 'factory'])
@endsection
