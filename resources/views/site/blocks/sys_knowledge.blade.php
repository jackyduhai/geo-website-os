{{-- 系统块 sys_knowledge：知识总览主体（page-hero + 文章网格 + pager）。
     数据由 KnowledgeController@index / channel 从 Catalog / Pages 准备，经
     SystemPageRenderContext 注入；频道 subnav 由 composed 布局统一渲染。 --}}
<section class="page-hero" {!! $semanticAttrs ?? '' !!}>
  <div class="wrap-narrow">
    <span class="eyebrow">{{ __('ui.eyebrow_knowledge') }}</span>
    <h1 class="ph-h">{{ $active ? ($channels[$active] ?? __('ui.eyebrow_knowledge')) : __('ui.knowledge_h1') }}</h1>
    <p class="ph-lead">{{ __('ui.knowledge_lead') }}</p>
  </div>
</section>

<section class="sec" {!! \App\Support\Blocks\SectionSemantic::forSection('faq','education','Content') !!}>
  <div class="wrap">
    @if($items->count())
      <div class="kgrid kgrid-3">
        @foreach($items as $c)
          <a class="kcard reveal @if($c->cover) has-cover @endif" href="{{ $c->url() }}">
            @if($c->cover)<span class="kc-cover"><x-picture :src="$c->cover->url()" :alt="$c->title" loading="lazy" decoding="async" /></span>@endif
            @php
              $ktName = $c->group
                ? $c->group->displayName()
                : ($c->category ? $c->category->displayName() : __('ui.industry_knowledge'));
            @endphp
            <span class="kt">{{ $ktName }}</span>
            <h3>{{ $c->title }}</h3>
            <p>{{ $c->summary }}</p>
            <div class="km"><span>{{ __('ui.read_more') }}<span class="arr">→</span></span><time>{{ optional($c->published_at)->format('Y-m-d') }}</time></div>
          </a>
        @endforeach
      </div>
      <div class="pager">{{ $items->links() }}</div>
    @else
      <p class="empty-note">{{ __('ui.knowledge_empty') }}</p>
    @endif
  </div>
</section>

@include('site._bottom_cta', ['semanticAttrs' => ''])
