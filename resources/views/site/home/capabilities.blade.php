{{-- S2 价值主张 + 能力点（无边框结构行；条目可后台装修：图标/标题/文案） --}}
@php($capItems = $capabilityItems ?? [])
@if(count($capItems))
<section class="sec">
  <div class="wrap grid g2 ws-layout" style="gap:56px;align-items:center">
    <div class="reveal">
      <span class="eyebrow">{{ __('ui.eyebrow_who') }}</span>
      <h2 class="sec-h">{{ $blk->title ?: __('ui.home_cap_title') }}</h2>
      <p class="sec-sub">{{ \App\Support\Catalog::company()['summary'] ?? '' }}</p>
      <div class="actions" style="margin-top:30px">
        @if(\App\Support\Catalog::hasProduction())
        <a class="btn" href="{{ url('/factory/') }}">{{ __('ui.home_cap_btn1') }}<span class="arr">→</span></a>
        @endif
        <a class="btn-o" href="{{ url('/') }}#s08">{{ __('ui.home_cap_btn2') }}<span class="arr">→</span></a>
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
@endif
