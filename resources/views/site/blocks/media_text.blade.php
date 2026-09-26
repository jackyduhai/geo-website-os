{{-- 通用组合 Block · 图文混排：左图右文（reverse 时图在右），可选按钮。 --}}
@php
  $c = $block->cfg();
  $mtMedia = ! empty($c['media_id']) ? \App\Models\Media::find((int) $c['media_id']) : null;
  $mtAlt = trim((string) ($c['alt'] ?? ''));
  $mtTitle = trim((string) ($c['title'] ?? ''));
  $mtBody = trim((string) ($c['body'] ?? ''));
  $mtBtnLabel = trim((string) ($c['button_label'] ?? ''));
  $mtBtnUrl = trim((string) ($c['button_url'] ?? ''));
  $mtReverse = ! empty($c['reverse']);
@endphp
@if($mtTitle !== '' || $mtBody !== '' || $mtMedia)
  <section class="sec" {!! $semanticAttrs ?? '' !!}>
    <div class="wrap grid g2 @if($mtReverse) media-text-reverse @endif" style="gap:var(--sp-12);align-items:center">
      @if($mtMedia)
        <div class="reveal">
          <x-picture :src="$mtMedia->url()" :alt="$mtAlt ?: $mtTitle" loading="lazy" />
        </div>
      @endif
      <div class="reveal">
        @if($mtTitle)<h2 class="sec-h">{{ $mtTitle }}</h2>@endif
        @if($mtBody)
          <div class="prose" style="font-size:var(--fs-base);line-height:1.9">{!! \Illuminate\Support\Str::markdown($mtBody) !!}</div>
        @endif
        @if($mtBtnLabel !== '' && $mtBtnUrl !== '')
          <div class="actions" style="margin-top:var(--sp-6)">
            <a class="btn btn-primary" href="{{ \App\Support\SafeUrl::sanitize($mtBtnUrl) }}">{{ $mtBtnLabel }}<span class="arr">→</span></a>
          </div>
        @endif
      </div>
    </div>
  </section>
@endif
