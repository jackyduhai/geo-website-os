@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@php
  $specRows = collect([
      ['规格 / 型号', $product['net_weight'] ?? null],
      ['包装形式', $product['packaging'] ?? null],
      ['质保期 / 有效期', $product['shelf_life'] ?? null],
      ['储存条件', $product['storage'] ?? null],
      ['起订量', $product['moq'] ?? null],
  ])->filter(fn ($r) => filled($r[1]))->values()->all();
  $processSteps = array_map(fn ($p) => [
      'title' => $p['step'],
      'text'  => $p['value'] . (filled($p['note'] ?? null) ? '（' . $p['note'] . '）' : ''),
  ], $product['params'] ?? []);
@endphp

@section('content')
@include('site._subnav', ['subnav' => $subnav])

{{-- 1. 产品定位 --}}
<section class="page-hero prod-hero">
  <div class="wrap prod-hero-in">
    <div>
      <span class="prod-tag-line">{{ $product['tag'] ?? $line['name'] ?? '' }}</span>
      <h1 class="ph-h">{{ $product['name'] }}</h1>
      <p class="ph-lead">{{ $product['tagline'] }}</p>
      @if(!empty($product['mains']))
        <div class="prod-mains">
          <span class="pm-k">适用范围</span>
          @foreach($product['mains'] as $m)<em>{{ $m }}</em>@endforeach
        </div>
      @endif
      <div class="actions" style="margin-top:26px">
        <a class="btn btn-primary btn-lg" href="{{ url('/') }}#s08">{{ $ctaText ?? '联系我们' }}<span class="arr">→</span></a>
        <a class="btn btn-secondary btn-lg" href="{{ url('/contact/') }}">联系我们</a>
      </div>
    </div>
    @if(!empty($product['key_params']))
    <div class="prod-hero-card">
      <span class="phc-cap">关键参数一览</span>
      @include('site._param_table', ['rows' => array_map(fn ($kp) => ['label' => $kp['label'], 'value' => $kp['value']], $product['key_params'] ?? [])])
    </div>
    @endif
  </div>
</section>

{{-- 2. 使用步骤（HowTo 可视化） --}}
@if(!empty($processSteps))
<section class="sec sec-tint">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">PROCESS · 使用说明</span>
      <h2 class="sec-h">标准化使用步骤</h2>
    </div>
    @include('site._process_steps', ['steps' => $processSteps])
  </div>
</section>
@endif

{{-- 3. 适用场景 --}}
@if(!empty($scenes))
<section class="sec">
  <div class="wrap">
    <div class="sec-head row">
      <div>
        <span class="eyebrow">SCENARIOS · 适用场景</span>
        <h2 class="sec-h">这些应用场景都在用</h2>
      </div>
      <a class="btn-text" href="{{ url('/solutions/') }}">全部场景<span class="arr">→</span></a>
    </div>
    <div class="scene-links">
      @foreach($scenes as $sc)
        <a class="scene-chip" href="{{ url('/solutions/' . $sc['slug'] . '/') }}">{{ $sc['name'] }}<span class="arr">→</span></a>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- 4. 规格与交付：仅有实际规格数据时渲染，无数据不输出占位 / 营销话术 --}}
@if(!empty($specRows))
<section class="sec sec-tint">
  <div class="wrap-narrow">
    <div class="sec-head"><span class="eyebrow">SPEC · 规格与交付</span><h2 class="sec-h">规格、包装与起订</h2></div>
    @include('site._param_table', ['rows' => $specRows])
  </div>
</section>
@endif

{{-- 5. 相关产品 --}}
@if(!empty($related))
<section class="sec">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">RELATED · 相关产品</span>
      <h2 class="sec-h">常与它搭配的产品</h2>
    </div>
    <div class="prod-grid">
      @foreach($related as $rp)
        @include('site._product_card', ['p' => $rp])
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- 6. 产品 FAQ --}}
@if(!empty($faqs))
<section class="sec sec-tint">
  <div class="wrap-narrow">
    <div class="sec-head center"><span class="eyebrow">FAQ · 常见问题</span><h2 class="sec-h">关于{{ $product['short_name'] ?? $product['name'] }}的常见问题</h2></div>
    @include('site._faq_list', ['faqs' => $faqs])
  </div>
</section>
@endif

@include('site._bottom_cta')
@endsection
