@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
@include('site._subnav', ['subnav' => $subnav])

<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">PRODUCTS · 产品中心</span>
    <h1 class="ph-h">{{ count($lines) }} 大产品系列，覆盖从研发到量产的完整需求</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

@foreach($lines as $line)
  <section class="sec {{ $loop->even ? 'sec-tint' : '' }}" id="{{ $line['slug'] }}">
    <div class="wrap">
      <div class="sec-head row">
        <div>
          <h2 class="sec-h">{{ $line['name'] }}<span class="pcard-count">{{ count($line['products']) }} 款</span></h2>
          <p class="sec-sub">{{ $line['desc'] ?? '' }}</p>
        </div>
        @if(count($line['products']) >= 1)
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
        <p class="empty-note">该体系产品正在整理中，可先联系我们获取产品手册与样品。</p>
      @endif
    </div>
  </section>
@endforeach

@include('site._bottom_cta')
@endsection
