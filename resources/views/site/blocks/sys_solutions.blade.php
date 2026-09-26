{{-- 系统块 sys_solutions：应用场景总览主体（page-hero + 场景网格 + 底部 CTA）。
     数据由 SolutionController@index 从 Catalog / Narrative 准备，经
     SystemPageRenderContext 注入。 --}}
<section class="page-hero" {!! $semanticAttrs ?? '' !!}>
  <div class="wrap-narrow">
    <span class="eyebrow">{{ __('ui.eyebrow_solutions') }}</span>
    <h1 class="ph-h">{{ __('ui.solutions_h1') }}</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

<section class="sec" {!! \App\Support\Blocks\SectionSemantic::forSection('solution','education','Service','consult') !!}>
  <div class="wrap">
    <div class="scene-grid">
      @foreach($scenes as $sc)
        @include('site._scene_card', ['sc' => $sc, 'fullCta' => true])
      @endforeach
    </div>
  </div>
</section>

@include('site._bottom_cta')
