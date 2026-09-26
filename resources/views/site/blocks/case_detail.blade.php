{{-- 系统块 case_detail：客户案例详情主体（18R-2b）。
     根节点 data-* 由 GEO Gate 固定（AI 读成"企业→场景→产品→结果"的业务事实，非文章）。
     数据由 CaseController 经 CaseRenderContext 注入：$entity + $case。
     纯展示块：无 <script>、不改 Schema/SEO；产品链接经 SafeUrl。 --}}
@php
  $industry  = $case['industry'] ?? '';
  $scenario = $case['scenario'] ?? '';
  $customer = $case['customer'] ?? null;
  $products = $case['products'] ?? [];
@endphp

<section data-section="case-study" data-purpose="proof" data-entity="case_study" data-conversion="inquiry">
  <div class="wrap-narrow">
    <span class="eyebrow">{{ __('ui.cases_eyebrow') }}</span>
    <h1 class="ph-h">{{ $entity->name }}</h1>
    @if(!empty($entity->summary))<p class="ph-lead">{{ $entity->summary }}</p>@endif
    <div class="scene-links">
      @if($industry !== '')<span class="scene-chip">{{ __('ui.case_industry') }}：{{ $industry }}</span>@endif
      @if($scenario !== '')<span class="scene-chip">{{ __('ui.case_scenario') }}：{{ $scenario }}</span>@endif
      @if(!empty($customer))<span class="scene-chip">{{ __('ui.case_customer') }}：{{ $customer['name'] }}</span>@endif
    </div>
  </div>
</section>

@if(!empty($case['challenge']) || !empty($case['solution']) || !empty($case['result']))
<section class="sec">
  <div class="wrap-narrow">
    @if(!empty($case['challenge']))
      <div class="sec-head"><span class="eyebrow">{{ __('ui.case_challenge') }}</span></div>
      <p>{{ $case['challenge'] }}</p>
    @endif
    @if(!empty($case['solution']))
      <div class="sec-head mt-2"><span class="eyebrow">{{ __('ui.case_solution') }}</span></div>
      <p>{{ $case['solution'] }}</p>
    @endif
    @if(!empty($case['result']))
      <div class="sec-head mt-2"><span class="eyebrow">{{ __('ui.case_result') }}</span></div>
      <p>{{ $case['result'] }}</p>
    @endif
  </div>
</section>
@endif

@if(!empty($products))
<section class="sec sec-tint">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">{{ __('ui.eyebrow_related') }}</span>
      <h2 class="sec-h">{{ __('ui.case_products') }}</h2>
    </div>
    <div class="scene-links">
      @foreach($products as $rp)
        <a class="scene-chip" href="{{ \App\Support\SafeUrl::sanitize($rp['url']) }}">{{ $rp['name'] }}<span class="arr">→</span></a>
      @endforeach
    </div>
  </div>
</section>
@endif
