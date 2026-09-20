{{-- S05 核心车间（重音段：左叙述+关键数据，右 2×2 能力格；可后台装修，无实拍图时用统一线性图标） --}}
@php
  $ws = $workshopItems ?? ($workshops ?? []);
  $wsIcons = ['package', 'sliders', 'gear', 'shield', 'factory'];
  $wsStats = collect($stats ?? [])->filter(fn ($s) => in_array(($s['unit'] ?? ''), ['㎡', '吨'], true))->values();
@endphp
@if(count($ws))
<section class="sec sec-tint ws-heavy" id="s05">
  <div class="wrap-wide grid ws-layout" style="gap:56px;align-items:center;grid-template-columns:0.92fr 1.08fr">
    <div class="reveal">
      <span class="eyebrow">CAPABILITY · 核心能力</span>
      <h2 class="sec-h">{{ $blk->title ?: count($ws).' 个车间一体协同，从研发到量产闭环' }}</h2>
      <p class="sec-sub">{{ $blk->subtitle ?: '自有生产车间全面投产，支撑从研发到成品的一体化交付。' }}</p>
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
      <div class="actions" style="margin-top:26px">
        <a class="btn-text" href="{{ url('/factory/') }}">查看工厂与产能<span class="arr">→</span></a>
        <a class="btn-text" href="{{ url('/') }}#s08">预约工厂参观<span class="arr">→</span></a>
      </div>
    </div>
    <div class="grid g2 reveal ws-grid" style="gap:20px">
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
