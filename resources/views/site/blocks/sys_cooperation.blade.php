{{-- 系统块 sys_cooperation：合作方式主体（page-hero + 合作方式 + 流程 + FAQ）。
     数据由 CooperationController@show 从 Catalog / Pages 准备，经 SystemPageRenderContext 注入。 --}}
<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">{{ __('ui.eyebrow_cooperation') }}</span>
    <h1 class="ph-h">{{ __('ui.coop_h1', ['count' => count($coop['types'])]) }}</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

{{-- 合作方式 --}}
<section class="sec">
  <div class="wrap">
    <div class="coop-grid3">
      @foreach($coop['types'] as $t)
        <div class="coop-mode reveal">
          <span class="feat-ic g">@include('site._icon', ['name' => 'check'])</span>
          <h2 class="cm-h">{{ $t['name'] }}</h2>
          <p class="cm-fit">{{ $t['fit'] }}</p>
          <ul class="coop-points">
            @foreach($t['includes'] as $inc)<li>{{ $inc }}</li>@endforeach
          </ul>
          <a class="btn-text" href="{{ url('/') }}#s08">{{ $t['cta'] }}<span class="arr">→</span></a>
        </div>
      @endforeach
    </div>
  </div>
</section>

{{-- 合作流程 --}}
@if(!empty($coop['process']))
<section class="sec sec-tint">
  <div class="wrap">
    <div class="sec-head center">
      <span class="eyebrow">{{ __('ui.eyebrow_process') }}</span>
      <h2 class="sec-h">{{ __('ui.process_h2', ['count' => count($coop['process'])]) }}</h2>
    </div>
    @include('site._process_steps', [
      'steps' => array_map(fn($s) => ['title' => $s['name'], 'text' => $s['desc']], $coop['process']),
      'small' => true,
    ])
  </div>
</section>
@endif

{{-- FAQ --}}
@if(!empty($faqs))
<section class="sec">
  <div class="wrap-narrow">
    <div class="sec-head center"><span class="eyebrow">{{ __('ui.eyebrow_faq') }}</span><h2 class="sec-h">{{ __('ui.coop_faq_h2') }}</h2></div>
    @include('site._faq_list', ['faqs' => $faqs])
  </div>
</section>
@endif

@include('site._bottom_cta')
