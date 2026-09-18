@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@php
  $specRows = collect([
      ['净含量', $product['net_weight'] ?? null],
      ['包装形式', $product['packaging'] ?? null],
      ['保质期', $product['shelf_life'] ?? null],
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
          <span class="pm-k">适用主料</span>
          @foreach($product['mains'] as $m)<em>{{ $m }}</em>@endforeach
        </div>
      @endif
      <div class="actions" style="margin-top:26px">
        <a class="btn btn-primary btn-lg" href="{{ url('/') }}#s08">{{ $ctaText ?? '免费获取样品' }}<span class="arr">→</span></a>
        <a class="btn btn-secondary btn-lg" href="{{ url('/cooperation/') }}">获取定制方案</a>
      </div>
    </div>
    <div class="prod-hero-card">
      <span class="phc-cap">关键参数一览</span>
      @include('site._param_table', ['rows' => array_map(fn ($kp) => ['label' => $kp['label'], 'value' => $kp['value']], $product['key_params'] ?? [])])
    </div>
  </div>
</section>

{{-- 2. 使用工艺（HowTo 可视化） --}}
@if(!empty($processSteps))
<section class="sec sec-tint">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">PROCESS · 使用工艺</span>
      <h2 class="sec-h">标准化使用步骤，门店照着就能做</h2>
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
        <h2 class="sec-h">这些生意类型都在用</h2>
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

{{-- 4. 规格与交付（全部缺省则整块隐藏，禁止占位） --}}
@if(!empty($specRows))
<section class="sec sec-tint">
  <div class="wrap-narrow">
    <div class="sec-head"><span class="eyebrow">SPEC · 规格与交付</span><h2 class="sec-h">规格、包装与起订</h2></div>
    @include('site._param_table', ['rows' => $specRows])
  </div>
</section>
@else
<section class="sec sec-tint">
  <div class="wrap-narrow">
    <div class="sec-head"><span class="eyebrow">SPEC · 起订与交付</span><h2 class="sec-h">按你的用量与规格报价</h2></div>
    <p class="prose">不同品类、规格与包装形式的起订量不同。说清你的预计用量、目标口味与包装需求，我们按你的实际情况给出报价与排期，并可先寄样、打样，确认后再量产。</p>
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
