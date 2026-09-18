{{-- 首页中部横幅（可装修区块 mid_banner）：
     读取「展示 → Banner 轮播」中投放位置=首页中部、启用且有图的条目。
     单张：通栏全宽（无两侧留白），图上压字内容对齐到 1200 栅格；
     多张：横向 scroll-snap（不自动播放，避免干扰阅读）；无图整块不渲染。
     内容全部 SSR 在初始 HTML；图片自带文字时主/副标题留空即可，不在图上叠字。 --}}
@php
  $mb = collect($midBanners ?? [])->filter(fn ($b) => $b->imageUrl())->values();
  $multi = $mb->count() > 1;
@endphp
@if($mb->isNotEmpty())
<section class="midbanner{{ $multi ? ' is-multi-sec' : '' }}" aria-label="中部横幅">
  <div class="mb-track{{ $multi ? ' is-multi' : '' }}">
    @foreach($mb as $b)
      @php
        $mTitle = trim((string) ($b->title ?? ''));
        $mSub = trim((string) ($b->subtitle ?? ''));
        $mLink = trim((string) ($b->link ?? ''));
        $mBlank = (int) ($b->target ?? 0) === 1;
        $mHref = $mLink !== ''
            ? (preg_match('~^(https?:|tel:|/)~', $mLink) ? (str_starts_with($mLink, '/') ? url($mLink) : $mLink) : url('/' . ltrim($mLink, '/')))
            : null;
        $mAlt = $mTitle !== '' ? $mTitle : '中部横幅';
        // 不写死 width/height 属性：响应式高度由 CSS aspect-ratio 决定，写死 HTML 属性会与 aspect-ratio 冲突导致误裁切。
        // WebP 渐进增强：存在 .webp 兄弟文件时首选 webp，否则/旧浏览器回退原图（picture 包一层 .pic 保持块级满宽）。
        $mbSrc = $b->imageUrl();
        $mbWebp = \App\Support\ImageOptimizer::webpUrl($mbSrc);
        $mbImg = '<img src="' . e($mbSrc) . '" alt="' . e($mAlt) . '" loading="lazy" decoding="async">';
        $figure = '<picture class="pic">'
            . ($mbWebp ? '<source type="image/webp" srcset="' . e($mbWebp) . '">' : '')
            . $mbImg . '</picture>';
        $cap = ($mTitle !== '' || $mSub !== '') ? '<div class="mb-cap-in">'
            . ($mTitle !== '' ? '<strong>' . e($mTitle) . '</strong>' : '')
            . ($mSub !== '' ? '<span>' . e($mSub) . '</span>' : '')
            . '</div>' : '';
      @endphp
      <figure class="mb-item">
        @if($mHref)
          <a href="{{ $mHref }}" @if($mBlank) target="_blank" rel="noopener" @endif>
            {!! $figure !!}
            @if($cap !== '')<figcaption class="mb-cap">{!! $cap !!}</figcaption>@endif
          </a>
        @else
          {!! $figure !!}
          @if($cap !== '')<figcaption class="mb-cap">{!! $cap !!}</figcaption>@endif
        @endif
      </figure>
    @endforeach
  </div>
</section>
@endif
