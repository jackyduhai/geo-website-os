{{-- 系统块 case_list：客户案例总览主体（page-hero + 案例卡片网格）。
     数据由 CaseController@index 经 ListingRenderContext 注入 $cases。
     纯展示块：无 <script>、不改 Schema/SEO；全部链接经 SafeUrl。 --}}
<section class="page-hero" {!! $semanticAttrs ?? '' !!}>
  <div class="wrap-narrow">
    <span class="eyebrow">{{ __('ui.cases_eyebrow') }}</span>
    <h1 class="ph-h">{{ __('ui.cases_h1') }}</h1>
    <p class="ph-lead">{{ __('ui.cases_lead') }}</p>
  </div>
</section>

<section class="sec">
  <div class="wrap">
    <div class="prod-grid">
      @foreach(($cases ?? []) as $c)
        <a class="prod-card" href="{{ \App\Support\SafeUrl::sanitize($c['url']) }}">
          <div class="prod-bd">
            @if(!empty($c['industry']))<span class="prod-tag">{{ $c['industry'] }}</span>@endif
            <h3>{{ $c['name'] }}</h3>
            <p>{{ $c['summary'] }}</p>
            <span class="prod-go">{{ __('nav.cases') }}
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            </span>
          </div>
        </a>
      @endforeach
    </div>
  </div>
</section>
