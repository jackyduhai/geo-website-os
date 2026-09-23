@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
@include('site._subnav', ['subnav' => $subnav])

<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">{{ __('ui.product_series') }}</span>
    <h1 class="ph-h">{{ $line['name'] }}</h1>
    <p class="ph-lead">{{ $line['desc'] ?? '' }}</p>
  </div>
</section>

<section class="sec">
  <div class="wrap">
    <div class="prod-grid">
      @foreach($products as $p)
        @include('site._product_card', ['p' => $p, 'lineName' => $line['name']])
      @endforeach
    </div>
  </div>
</section>

@include('site._bottom_cta')
@endsection
