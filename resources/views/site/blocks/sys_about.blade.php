{{-- 系统块 sys_about：关于我们 profile / history / culture 三页主体，按 $aboutPage 渲染。
     数据由 AboutController@page 从 Catalog / Pages / Narrative 准备，经
     SystemPageRenderContext 注入；subnav 由 composed 布局统一渲染。 --}}
@if($aboutPage === 'profile')
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

@elseif($aboutPage === 'history')
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

@else
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
@endif

@include('site._bottom_cta')
