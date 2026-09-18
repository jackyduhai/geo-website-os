{{-- S2 价值主张 + 能力点（无边框结构行；条目可后台装修：图标/标题/文案） --}}
@php($capItems = $capabilityItems ?? [])
<section class="sec">
  <div class="wrap grid g2 ws-layout" style="gap:56px;align-items:center">
    <div class="reveal">
      <span class="eyebrow">WHO WE ARE · 我们是谁</span>
      <h2 class="sec-h">{{ $blk->title ?: '专注中式Sample Snack风味的调味研发与生产源头工厂' }}</h2>
      <p class="sec-sub">{{ $siteSettings['site_description'] ?? '' }}</p>
      <div class="actions" style="margin-top:30px">
        <a class="btn" href="{{ url('/factory/') }}">看研发与工厂实力<span class="arr">→</span></a>
        <a class="btn-o" href="{{ url('/') }}#s08">获取定制方案 / 打样<span class="arr">→</span></a>
      </div>
    </div>
    <div class="grid reveal" style="gap:0">
      @foreach($capItems as $it)
        <div class="cap">
          <span class="feat-ic">@include('site._feat_media', ['image' => $it['image'] ?? null, 'icon' => $it['icon'] ?? 'default', 'alt' => $it['title'] ?? ''])</span>
          <div>
            <h4>{{ $it['title'] ?? '' }}</h4>
            <p>{{ $it['text'] ?? '' }}</p>
          </div>
        </div>
      @endforeach
    </div>
  </div>
</section>
