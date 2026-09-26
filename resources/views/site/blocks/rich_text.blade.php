{{-- 通用组合 Block · 富文本：标题 + Markdown 正文（渲染时转安全 HTML，不存 HTML 入 DB）。 --}}
@php
  $c = $block->cfg();
  $rtTitle = trim((string) ($c['title'] ?? ''));
  $rtBody = trim((string) ($c['body'] ?? ''));
@endphp
@if($rtTitle !== '' || $rtBody !== '')
  <section class="sec" {!! $semanticAttrs ?? '' !!}>
    <div class="wrap-narrow">
      @if($rtTitle)
        <div class="sec-head">
          <h2 class="sec-h">{{ $rtTitle }}</h2>
        </div>
      @endif
      @if($rtBody)
        <div class="prose" style="font-size:var(--fs-base);line-height:1.9">
          {!! \Illuminate\Support\Str::markdown($rtBody) !!}
        </div>
      @endif
    </div>
  </section>
@endif
