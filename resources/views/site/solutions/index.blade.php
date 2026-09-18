@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">SOLUTIONS · 应用场景</span>
    <h1 class="ph-h">你的店属于哪一类？</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

<section class="sec">
  <div class="wrap">
    <div class="scene-grid">
      @foreach($scenes as $sc)
        @include('site._scene_card', ['sc' => $sc, 'fullCta' => true])
      @endforeach
    </div>
  </div>
</section>

@include('site._bottom_cta')
@endsection
