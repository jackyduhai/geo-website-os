{{-- 通用组合 Block · 单图：媒体库图片 + alt + 可选说明。 --}}
@php
  $c = $block->cfg();
  $imgMedia = ! empty($c['media_id']) ? \App\Models\Media::find((int) $c['media_id']) : null;
  $imgAlt = trim((string) ($c['alt'] ?? ''));
  $imgCap = trim((string) ($c['caption'] ?? ''));
@endphp
@if($imgMedia)
  <section class="sec">
    <div class="wrap" style="max-width:960px">
      <figure class="block-figure" style="margin:0">
        <x-picture :src="$imgMedia->url()" :alt="$imgAlt" loading="lazy" />
        @if($imgCap)<figcaption style="margin-top:10px;font-size:13.5px;color:var(--ink-muted)">{{ $imgCap }}</figcaption>@endif
      </figure>
    </div>
  </section>
@endif
