@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
@include('site._subnav', ['subnav' => $subnav])

<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">{{ __('ui.eyebrow_history') }}</span>
    <h1 class="ph-h">{{ __('ui.history_h1', ['year' => substr($company['founded'] ?? '', 0, 4) ?: date('Y')]) }}</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

<section class="sec">
  <div class="wrap-narrow">
    @if(!empty($nodes ?? []))
    <ol class="timeline">
      @foreach($nodes as $n)
        <li class="reveal">
          <span class="t-year">{{ $n['time'] }}</span>
          <h4>{{ $n['title'] }}</h4>
          <p>{{ $n['desc'] }}</p>
        </li>
      @endforeach
    </ol>
    @endif
  </div>
</section>

@include('site._bottom_cta')
@endsection
