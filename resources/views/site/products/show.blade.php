@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@php
  $specRows = collect([
      [__('ui.spec_net'), $product['net_weight'] ?? null],
      [__('ui.spec_packaging'), $product['packaging'] ?? null],
      [__('ui.spec_shelf'), $product['shelf_life'] ?? null],
      [__('ui.spec_storage'), $product['storage'] ?? null],
      [__('ui.spec_moq'), $product['moq'] ?? null],
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
          <span class="pm-k">{{ __('ui.mains_label') }}</span>
          @foreach($product['mains'] as $m)<em>{{ $m }}</em>@endforeach
        </div>
      @endif
      <div class="actions" style="margin-top:26px">
        <a class="btn btn-primary btn-lg" href="{{ url('/') }}#s08">{{ $ctaText ?? __('ui.bcta_secondary') }}<span class="arr">→</span></a>
        <a class="btn btn-secondary btn-lg" href="{{ url('/contact/') }}">{{ __('ui.bcta_secondary') }}</a>
      </div>
    </div>
    @if(!empty($product['key_params']))
    <div class="prod-hero-card">
      <span class="phc-cap">{{ __('ui.keycap') }}</span>
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
      <span class="eyebrow">{{ __('ui.eyebrow_usage') }}</span>
      <h2 class="sec-h">{{ __('ui.usage_h2') }}</h2>
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
        <span class="eyebrow">{{ __('ui.eyebrow_scen') }}</span>
        <h2 class="sec-h">{{ __('ui.scen_h2') }}</h2>
      </div>
      <a class="btn-text" href="{{ url('/solutions/') }}">{{ __('ui.all_scen') }}<span class="arr">→</span></a>
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
    <div class="sec-head"><span class="eyebrow">{{ __('ui.eyebrow_spec') }}</span><h2 class="sec-h">{{ __('ui.spec_h2') }}</h2></div>
    @include('site._param_table', ['rows' => $specRows])
  </div>
</section>
@endif

{{-- 5. 相关产品 --}}
@if(!empty($related))
<section class="sec">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">{{ __('ui.eyebrow_related') }}</span>
      <h2 class="sec-h">{{ __('ui.related_h2') }}</h2>
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
    <div class="sec-head center"><span class="eyebrow">{{ __('ui.eyebrow_faq') }}</span><h2 class="sec-h">{{ __('ui.pf_faq_h2', ['name' => $product['short_name'] ?? $product['name']]) }}</h2></div>
    @include('site._faq_list', ['faqs' => $faqs])
  </div>
</section>
@endif

@include('site._bottom_cta')
@endsection
