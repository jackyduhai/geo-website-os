@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
@include('site._subnav', ['subnav' => $subnav])

<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">CULTURE · 企业文化</span>
    <h1 class="ph-h">我们做事的标准</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

<section class="sec sec-tint">
  <div class="wrap">
    <div class="culture-grid">
      @foreach(($copy['cards'] ?? []) as $c)
        <div class="culture-card reveal">
          <span class="cc-label">{{ $c['label'] }}</span>
          <h2 class="cc-main">{{ $c['main'] }}</h2>
          <p>{{ $c['desc'] }}</p>
        </div>
      @endforeach
    </div>
  </div>
</section>

<section class="sec">
  <div class="wrap-narrow center-txt">
    <p class="prose">{{ count($workshops) }} 个车间的实拍，比任何形容词都有说服力。</p>
    <div class="actions" style="justify-content:center;margin-top:20px">
      <a class="btn btn-primary btn-lg" href="{{ url('/factory/') }}">查看工厂与资质<span class="arr">→</span></a>
    </div>
  </div>
</section>

@include('site._bottom_cta')
@endsection
