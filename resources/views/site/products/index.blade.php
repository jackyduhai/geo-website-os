@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
@include('site._subnav', ['subnav' => $subnav])

<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">PRODUCTS · 产品中心</span>
    @if($flatMode ?? false)
    <h1 class="ph-h">产品中心</h1>
    @else
    <h1 class="ph-h">{{ count($lines) }} 大产品系列</h1>
    @endif
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

@foreach($lines as $line)
  <section class="sec {{ $loop->even ? 'sec-tint' : '' }}" id="{{ $line['slug'] ?? 'all' }}">
    <div class="wrap">
      <div class="sec-head row">
        <div>
          <h2 class="sec-h">{{ $line['name'] }}<span class="pcard-count">{{ count($line['products']) }} 款</span></h2>
          <p class="sec-sub">{{ $line['desc'] ?? '' }}</p>
        </div>
        @if(!empty($line['slug']) && count($line['products']) >= 1)
          <a class="btn-text" href="{{ url('/products/' . $line['slug'] . '/') }}">查看该系列<span class="arr">→</span></a>
        @endif
      </div>
      @if(count($line['products']))
        <div class="prod-grid">
          @foreach($line['products'] as $p)
            @include('site._product_card', ['p' => $p, 'lineName' => $line['name']])
          @endforeach
        </div>
      @else
        <p class="empty-note">该系列产品正在整理中，可先联系我们了解详情。</p>
      @endif
    </div>
  </section>
@endforeach

@include('site._bottom_cta')
@endsection
