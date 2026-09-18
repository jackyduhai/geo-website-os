{{-- S6 知识中心（浅面卡；可后台选来源栏目/条数，或手动指定具体文章） --}}
@if(($knowledgeItems ?? collect())->isNotEmpty())
<section class="sec">
  <div class="wrap">
    <div class="sec-head row">
      <div>
        <span class="eyebrow">KNOWLEDGE · 知识中心</span>
        <h2 class="sec-h">{{ $blk->title ?: '腌制工艺与门店应用参考' }}</h2>
        <p class="sec-sub">{{ $blk->subtitle ?: '把风味经验沉淀为可复用的专业内容，辅助你选料与出品。' }}</p>
      </div>
      <a class="btn-text" href="{{ url('/knowledge/') }}">进入知识中心<span class="arr">→</span></a>
    </div>
    <div class="kgrid">
      @foreach($knowledgeItems as $c)
        <a class="kcard reveal @if($c->cover) has-cover @endif" href="{{ $c->url() }}">
          @if($c->cover)<span class="kc-cover"><x-picture :src="$c->cover->url()" :alt="$c->title" loading="lazy" decoding="async" /></span>@endif
          <span class="kt">{{ optional($c->group)->name ?? ($c->category->name ?? '行业知识') }}</span>
          <h3>{{ $c->title }}</h3>
          <p>{{ $c->summary }}</p>
          <div class="km"><span>阅读全文<span class="arr">→</span></span><time>{{ optional($c->published_at)->format('Y-m-d') }}</time></div>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif
