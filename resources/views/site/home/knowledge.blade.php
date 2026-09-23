{{-- S6 知识中心（浅面卡；可后台选来源栏目/条数，或手动指定具体文章） --}}
@if(($knowledgeItems ?? collect())->isNotEmpty())
<section class="sec">
  <div class="wrap">
    <div class="sec-head row">
      <div>
        <span class="eyebrow">{{ __('ui.eyebrow_knowledge') }}</span>
        <h2 class="sec-h">{{ $blk->title ?: __('ui.home_know_title') }}</h2>
        <p class="sec-sub">{{ $blk->subtitle ?: __('ui.home_know_sub') }}</p>
      </div>
      <a class="btn-text" href="{{ url('/knowledge/') }}">{{ __('ui.home_know_btn') }}<span class="arr">→</span></a>
    </div>
    <div class="kgrid">
      @foreach($knowledgeItems as $c)
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
  </div>
</section>
@endif
