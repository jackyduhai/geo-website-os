@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
@include('site._subnav', ['subnav' => $subnav])

<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">{{ __('ui.eyebrow_about') }}</span>
    <h1 class="ph-h">{{ $company['name'] }}</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

<section class="sec">
  <div class="wrap about-layout">
    <div class="about-prose prose">
      {!! $bodyHtml !!}
      <div class="actions" style="margin-top:28px">
        <a class="btn btn-primary" href="{{ url('/') }}#s08">{{ $ctaText ?? __('ui.contact_us') }}<span class="arr">→</span></a>
        @if(\App\Support\Catalog::hasProduction())
        <a class="btn btn-secondary" href="{{ url('/factory/') }}">{{ __('ui.view_factory') }}</a>
        @endif
      </div>
    </div>
    <dl class="fact-block">
      <div><dt>{{ __('ui.dt_company_full') }}</dt><dd>{{ $company['name'] }}</dd></div>
      @if(!empty($company['founded_display']))<div><dt>{{ __('ui.dt_founded') }}</dt><dd>{{ $company['founded_display'] }}</dd></div>@endif
      @if(!empty($company['established_production_display']))<div><dt>{{ __('ui.dt_production') }}</dt><dd>{{ $company['established_production_display'] }}</dd></div>@endif
      @if(!empty($company['address']['full']))<div><dt>{{ __('ui.dt_address') }}</dt><dd>{{ $company['address']['full'] }}</dd></div>@endif
      @if(!empty($company['area_display']))<div><dt>{{ __('ui.dt_area') }}</dt><dd>{{ $company['area_display'] }}</dd></div>@endif
      @if(!empty($company['annual_capacity_display']))<div><dt>{{ __('ui.dt_capacity') }}</dt><dd>{{ $company['annual_capacity_display'] }}</dd></div>@endif
      @if(collect($workshops)->pluck('name')->filter()->isNotEmpty())<div><dt>{{ __('ui.dt_workshops') }}</dt><dd>{{ collect($workshops)->pluck('name')->implode(' / ') }}</dd></div>@endif
      @if(!empty($regions))<div><dt>{{ __('ui.dt_regions') }}</dt><dd>{{ implode(\App\Support\Localization\LocaleContext::current() === \App\Support\Localization\LocaleRegistry::default() ? '、' : ', ', $regions) }}</dd></div>@endif
      @if(!empty($company['phone']))<div><dt>{{ __('ui.dt_phone') }}</dt><dd>{{ $company['phone'] }}</dd></div>@endif
    </dl>
  </div>
</section>

@include('site._bottom_cta')
@endsection
