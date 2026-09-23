@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
@include('site._subnav', ['subnav' => $subnav])

<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">{{ __('ui.eyebrow_culture') }}</span>
    <h1 class="ph-h">{{ __('ui.culture_h1') }}</h1>
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

@if(!empty($workshops))
<section class="sec">
  <div class="wrap-narrow center-txt">
    <p class="prose">{{ __('ui.culture_prose') }}</p>
    <div class="actions" style="justify-content:center;margin-top:20px">
      <a class="btn btn-primary btn-lg" href="{{ url('/factory/') }}">{{ __('ui.view_factory') }}<span class="arr">→</span></a>
    </div>
  </div>
</section>
@endif

@include('site._bottom_cta')
@endsection
