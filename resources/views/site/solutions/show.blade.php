@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@php
  $isP2 = ($scene['priority'] ?? 'P0') === 'P2';
  // 区块 5 关键参数：P0 取核心产品 key_params；P2 取 param_note
  $paramRows = [];
  if ($isP2) {
      foreach (($scene['param_note'] ?? []) as $pn) { $paramRows[] = ['label' => $pn['label'], 'value' => $pn['value']]; }
  } elseif ($keyProduct) {
      foreach (($keyProduct['key_params'] ?? []) as $kp) { $paramRows[] = ['label' => $kp['label'], 'value' => $kp['value']]; }
  }
  // 区块 6 出餐流程：P0 核心产品有分步 params 时展示
  $flowSteps = [];
  if (! $isP2 && $keyProduct) {
      foreach (($keyProduct['params'] ?? []) as $p) {
          $flowSteps[] = ['title' => $p['step'], 'text' => $p['value'] . (filled($p['note'] ?? null) ? '（' . $p['note'] . '）' : '')];
      }
  }
@endphp

@section('content')
{{-- 1. 页头（问句式 H1） --}}
<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">SOLUTION · 应用场景</span>
    <h1 class="ph-h">{{ $scene['title_q'] }}</h1>
    <p class="ph-lead">{{ $scene['desc'] }}</p>
  </div>
</section>

{{-- 3. 三个具体麻烦（痛点） --}}
@if(!empty($scene['pain_points']))
<section class="sec">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">PAIN POINTS · 你的麻烦</span>
      <h2 class="sec-h">这个场景下，最头疼的三件事</h2>
    </div>
    <div class="pain-grid">
      @foreach($scene['pain_points'] as $pp)
        <div class="pain-card reveal">
          <h3>{{ $pp['title'] }}</h3>
          <p>{{ $pp['desc'] }}</p>
        </div>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- 4. 推荐产品组合 + 为什么这么配 --}}
@if(!empty($combo))
<section class="sec sec-tint">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">COMBO · 推荐组合</span>
      <h2 class="sec-h">这套组合，正好覆盖你的出品</h2>
      @if(!empty($scene['combo_reason']))<p class="sec-sub">{{ $scene['combo_reason'] }}</p>@endif
    </div>
    <div class="prod-grid combo-grid">
      @foreach($combo as $cp)
        @include('site._product_card', ['p' => $cp])
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- 5. 关键参数（证据） --}}
@if(!empty($paramRows))
<section class="sec is-inverse">
  <div class="wrap-narrow">
    <div class="sec-head">
      <span class="eyebrow accent">PARAMETERS · 关键参数</span>
      <h2 class="sec-h">{{ $isP2 ? '用法与配比原则' : '一组可直接复现的参数' }}</h2>
    </div>
    @include('site._param_table', ['rows' => $paramRows])
    @if(!$isP2 && !empty($scene['key_param_display']))
      <p class="tbl-note muted">{{ $scene['key_param_display'] }}</p>
    @endif
  </div>
</section>
@endif

{{-- 6. 出餐流程建议 --}}
@if(!empty($flowSteps))
<section class="sec">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">WORKFLOW · 出餐流程</span>
      <h2 class="sec-h">照着这套流程出餐</h2>
    </div>
    @include('site._process_steps', ['steps' => $flowSteps])
  </div>
</section>
@endif

{{-- 7. 场景 FAQ --}}
@if(!empty($faqs))
<section class="sec sec-tint">
  <div class="wrap-narrow">
    <div class="sec-head center"><span class="eyebrow">FAQ · 常见问题</span><h2 class="sec-h">关于「{{ $scene['name'] }}」的常见问题</h2></div>
    @include('site._faq_list', ['faqs' => $faqs])
  </div>
</section>
@endif

{{-- 相邻场景 --}}
@if(!empty($prev) || !empty($next))
<section class="sec">
  <div class="wrap">
    <div class="adj-grid">
      @foreach(['prev' => $prev, 'next' => $next] as $dir => $adj)
        @if(!empty($adj))
          <a class="adj-card" href="{{ url('/solutions/' . $adj['slug'] . '/') }}">
            <span class="adj-dir">{{ $dir === 'prev' ? '上一场景' : '下一场景' }}</span>
            <span class="adj-name">{{ $adj['name'] }}<span class="arr">→</span></span>
          </a>
        @endif
      @endforeach
    </div>
  </div>
</section>
@endif

@include('site._bottom_cta')
@endsection
