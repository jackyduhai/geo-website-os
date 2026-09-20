@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
@include('site._subnav', ['subnav' => $subnav])

<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">HISTORY · 发展历程</span>
    <h1 class="ph-h">从 {{ substr($company['founded'] ?? '', 0, 4) ?: date('Y') }} 年到现在</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

<section class="sec">
  <div class="wrap-narrow">
    <ol class="timeline">
      @foreach(($copy['nodes'] ?? []) as $n)
        <li class="reveal">
          <span class="t-year">{{ $n['time'] }}</span>
          <h4>{{ $n['title'] }}</h4>
          <p>{{ $n['desc'] }}</p>
        </li>
      @endforeach
    </ol>
  </div>
</section>

@include('site._bottom_cta')
@endsection
