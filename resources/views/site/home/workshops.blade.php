{{-- S05 核心生产设施（重音段：左叙述+关键数据，右 2×2 能力格；可后台装修，无实拍图时用统一线性图标） --}}
@php
  $ws = $workshopItems ?? ($workshops ?? []);
  $wsIcons = ['package', 'sliders', 'gear', 'shield', 'factory'];
  $wsStats = collect($stats ?? [])->filter(fn ($st) => (int) ($st['num'] ?? 0) > 0)->take(2)->values();
@endphp
@if(count($ws))
<section class="sec sec-tint ws-heavy" id="s05">
  <div class="wrap-wide grid ws-layout" style="gap:var(--sp-14);align-items:center;grid-template-columns:0.92fr 1.08fr">
    <div class="reveal">
      <span class="eyebrow">{{ __('ui.eyebrow_capability') }}</span>
      <h2 class="sec-h">{{ $blk->title ?: __('ui.home_workshops_title', ['count' => count($ws)]) }}</h2>
      <p class="sec-sub">{{ $blk->subtitle ?: __('ui.home_workshops_sub') }}</p>
      @if($wsStats->count())
      <div class="ws-facts">
        @foreach($wsStats as $s)
        <div class="wsf">
          <span class="wsf-n">{{ $s['num'] }}<small>{{ $s['unit'] ?? '' }}</small></span>
          <span class="wsf-l">{{ $s['label'] }}</span>
        </div>
        @endforeach
      </div>
      @endif
      <div class="actions" style="margin-top:var(--sp-7)">
        <a class="btn-text" href="{{ url('/factory/') }}">{{ __('ui.home_workshops_btn1') }}<span class="arr">→</span></a>
        <a class="btn-text" href="{{ url('/') }}#s08">{{ __('ui.home_workshops_btn2') }}<span class="arr">→</span></a>
      </div>
    </div>
    <div class="grid g2 reveal ws-grid" style="gap:var(--sp-5)">
      @foreach($ws as $i => $w)
        <div class="wscard">
          <span class="feat-ic">@include('site._feat_media', ['image' => $w['image'] ?? null, 'icon' => $w['icon'] ?? ($wsIcons[$i] ?? 'factory'), 'size' => 28, 'alt' => $w['title'] ?? ''])</span>
          <h4>{{ $w['title'] ?? '' }}</h4>
          @if(!empty($w['text']))<p>{{ $w['text'] }}</p>@endif
        </div>
      @endforeach
    </div>
  </div>
</section>
@endif
