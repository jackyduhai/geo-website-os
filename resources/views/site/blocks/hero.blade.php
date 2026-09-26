{{-- 通用组合 Block · Hero 首屏（数据全部来自 block content，不读 Catalog / 不写死）。
     有配图：左文案 / 右图；无配图：文案区。整页唯一 H1。 --}}
@php
  $c = $block->cfg();
  $hEyebrow = trim((string) ($c['eyebrow'] ?? ''));
  $hTitle = trim((string) ($c['title'] ?? $block->title ?? ''));
  $hSub = trim((string) ($c['subtitle'] ?? $block->subtitle ?? ''));
  $hButtons = is_array($c['buttons'] ?? null) ? $c['buttons'] : [];
  $hImage = ! empty($c['image_id']) ? \App\Models\Media::find((int) $c['image_id']) : null;
@endphp
<section class="hero-split reveal in" id="top">
  <div class="wrap hero-in">
    <div class="hero-copy">
      @if($hEyebrow)<span class="hero-kicker-line">{{ $hEyebrow }}</span>@endif
      @if($hTitle)<h1 class="hero-title">{{ $hTitle }}</h1>@endif
      @if($hSub)<p class="hero-lead">{{ $hSub }}</p>@endif
      @if(! empty($hButtons))
        <div class="actions">
          @foreach($hButtons as $b)
            @php
              $bLabel = trim((string) ($b['label'] ?? ''));
              $bUrl = trim((string) ($b['url'] ?? ''));
              if ($bLabel === '' || $bUrl === '') { continue; }
              $bClass = match ($b['style'] ?? 'primary') {
                  'secondary' => 'btn-secondary',
                  'ghost' => 'btn-ghost',
                  default => 'btn-primary',
              };
            @endphp
            <a class="btn {{ $bClass }} btn-lg" href="{{ \App\Support\SafeUrl::sanitize($bUrl) }}">{{ $bLabel }}<span class="arr">→</span></a>
          @endforeach
        </div>
      @endif
    </div>
    @if($hImage)
      <div class="hero-visual">
        <x-picture :src="$hImage->url()" :alt="$hTitle ?: $hEyebrow" loading="eager" fetchpriority="high" />
      </div>
    @endif
  </div>
</section>
