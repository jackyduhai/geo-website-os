@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
@include('site._subnav', ['subnav' => $subnav])

<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">ABOUT · 关于我们</span>
    <h1 class="ph-h">{{ $company['name'] }}</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

<section class="sec">
  <div class="wrap about-layout">
    <div class="about-prose prose">
      {!! $bodyHtml !!}
      <div class="actions" style="margin-top:28px">
        <a class="btn btn-primary" href="{{ url('/') }}#s08">{{ $ctaText ?? '联系我们' }}<span class="arr">→</span></a>
        @if(\App\Support\Catalog::hasProduction())
        <a class="btn btn-secondary" href="{{ url('/factory/') }}">查看工厂与资质</a>
        @endif
      </div>
    </div>
    <dl class="fact-block">
      <div><dt>公司全称</dt><dd>{{ $company['name'] }}</dd></div>
      @if(!empty($company['founded_display']))<div><dt>成立时间</dt><dd>{{ $company['founded_display'] }}</dd></div>@endif
      @if(!empty($company['established_production_display']))<div><dt>正式投产</dt><dd>{{ $company['established_production_display'] }}</dd></div>@endif
      @if(!empty($company['address']['full']))<div><dt>地址</dt><dd>{{ $company['address']['full'] }}</dd></div>@endif
      @if(!empty($company['area_display']))<div><dt>厂区面积</dt><dd>{{ $company['area_display'] }}</dd></div>@endif
      @if(!empty($company['annual_capacity_display']))<div><dt>年产能</dt><dd>{{ $company['annual_capacity_display'] }}</dd></div>@endif
      @if(collect($workshops)->pluck('name')->filter()->isNotEmpty())<div><dt>生产车间</dt><dd>{{ collect($workshops)->pluck('name')->implode(' / ') }}</dd></div>@endif
      @if(!empty($regions))<div><dt>销售区域</dt><dd>{{ implode('、', $regions) }}</dd></div>@endif
      @if(!empty($company['phone']))<div><dt>官方电话</dt><dd>{{ $company['phone'] }}</dd></div>@endif
    </dl>
  </div>
</section>

@include('site._bottom_cta')
@endsection
