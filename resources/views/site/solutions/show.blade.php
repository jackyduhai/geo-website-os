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
  // 区块 6 使用流程：P0 核心产品有分步 params 时展示
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
    <span class="eyebrow">{{ __('ui.eyebrow_solution') }}</span>
    <h1 class="ph-h">{{ $scene['title_q'] ?? $scene['name'] }}</h1>
    <p class="ph-lead">{{ $scene['desc'] }}</p>
  </div>
</section>

{{-- 3. 三个具体麻烦（痛点） --}}
@if(!empty($scene['pain_points']))
<section class="sec">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">{{ __('ui.pain_eyebrow') }}</span>
      <h2 class="sec-h">{{ __('ui.pain_h2') }}</h2>
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
      <span class="eyebrow">{{ __('ui.combo_eyebrow') }}</span>
      <h2 class="sec-h">{{ __('ui.combo_h2') }}</h2>
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
      <span class="eyebrow accent">{{ __('ui.params_eyebrow') }}</span>
      <h2 class="sec-h">{{ $isP2 ? __('ui.params_h2_p2') : __('ui.params_h2_p0') }}</h2>
    </div>
    @include('site._param_table', ['rows' => $paramRows])
    @if(!$isP2 && !empty($scene['key_param_display']))
      <p class="tbl-note muted">{{ $scene['key_param_display'] }}</p>
    @endif
  </div>
</section>
@endif

{{-- 6. 使用流程建议 --}}
@if(!empty($flowSteps))
<section class="sec">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">{{ __('ui.workflow_eyebrow') }}</span>
      <h2 class="sec-h">{{ __('ui.workflow_h2') }}</h2>
    </div>
    @include('site._process_steps', ['steps' => $flowSteps])
  </div>
</section>
@endif

{{-- 7. 场景 FAQ --}}
@if(!empty($faqs))
<section class="sec sec-tint">
  <div class="wrap-narrow">
    <div class="sec-head center"><span class="eyebrow">{{ __('ui.eyebrow_faq') }}</span><h2 class="sec-h">{{ __('ui.scene_faq_h2', ['name' => $scene['name']]) }}</h2></div>
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
            <span class="adj-dir">{{ $dir === 'prev' ? __('ui.adj_prev') : __('ui.adj_next') }}</span>
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
