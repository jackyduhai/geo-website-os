{{-- S02 应用场景（六类客户分诊）：后台「应用场景」条目优先（可改文案/换图/改链接），缺省由 Facts 规范化 --}}
@if(!empty($sceneList))
<section class="sec sec-tint" id="s02">
  <div class="wrap">
    <div class="sec-head row">
      <div>
        <span class="eyebrow">SOLUTIONS · 应用场景</span>
        <h2 class="sec-h">{{ $blk->title ?: '先选你的生意类型，再看用什么Sample Marinade' }}</h2>
        <p class="sec-sub">{{ $blk->subtitle ?: '按门店经营类型分诊：每类场景都给出推荐产品组合，以及可直接复现的投料配比与工艺参数。' }}</p>
      </div>
      <a class="btn-text" href="{{ url('/solutions/') }}">全部场景<span class="arr">→</span></a>
    </div>
    <div class="scene-grid">
      @foreach($sceneList as $sc)
        @php $scLink = $sc['link'] ?? null; @endphp
        @if($scLink)
          <a class="scene-card" href="{{ $scLink }}">
            @include('site.home._scene_body', ['sc' => $sc])
          </a>
        @else
          <div class="scene-card">
            @include('site.home._scene_body', ['sc' => $sc])
          </div>
        @endif
      @endforeach
    </div>
  </div>
</section>
@endif
