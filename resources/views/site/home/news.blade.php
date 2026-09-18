{{-- 新闻动态（无内容自动收起；可后台选来源栏目/条数，或手动指定具体文章） --}}
@if(($newsItems ?? collect())->isNotEmpty())
<section class="sec sec-tint">
  <div class="wrap">
    <div class="sec-head row">
      <div>
        <span class="eyebrow">NEWS · 新闻动态</span>
        <h2 class="sec-h">{{ $blk->title ?: '企业动态与产品更新' }}</h2>
        <p class="sec-sub">{{ $blk->subtitle }}</p>
      </div>
      <a class="btn-text" href="{{ url('/news/') }}">查看全部动态<span class="arr">→</span></a>
    </div>
    <div class="posts">
      @foreach($newsItems as $c)
        <a class="post reveal @if($c->cover) has-thumb @endif" href="{{ $c->url() }}">
          @if($c->cover)<span class="post-thumb"><x-picture :src="$c->cover->url()" :alt="$c->title" loading="lazy" decoding="async" /></span>@endif
          <span class="post-body">
            <h3>{{ $c->title }}</h3>
            <p>{{ $c->summary }}</p>
            <time>{{ optional($c->published_at)->format('Y-m-d') }}</time>
          </span>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif
