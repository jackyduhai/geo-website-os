{{-- S06 合作方式：后台「合作方式」条目优先（可改文案/换图），缺省由 Catalog（站点目录读模型）规范化 --}}
@if(!empty($coopModes))
<section class="sec sec-tint" id="s06">
  <div class="wrap">
    <div class="sec-head row">
      <div>
        <span class="eyebrow">COOPERATION · 合作方式</span>
        <h2 class="sec-h">{{ $blk->title ?: count($coopModes).' 种合作方式，按需选择' }}</h2>
        <p class="sec-sub">{{ $blk->subtitle ?: '多种合作路径，满足不同业务需求。' }}</p>
      </div>
      <a class="btn-text" href="{{ url('/cooperation/') }}">合作流程与常见问题<span class="arr">→</span></a>
    </div>
    <div class="home-coop">
      @foreach($coopModes as $m)
        @php $mLink = $m['link'] ?? url('/cooperation/'); @endphp
        <div class="card coop-card reveal">
          <span class="feat-ic g">
            @if(!empty($m['image']))
              <x-picture :src="$m['image']" :alt="$m['title'] ?? ''" loading="lazy" />
            @else
              @include('site._icon', ['name' => $m['icon'] ?? 'check'])
            @endif
          </span>
          <h3>{{ $m['title'] ?? '' }}</h3>
          <p>{{ $m['text'] ?? '' }}</p>
          @if(!empty($m['points']) && is_array($m['points']))
            <ul class="coop-points">
              @foreach($m['points'] as $pt)<li>{{ $pt }}</li>@endforeach
            </ul>
          @endif
          <a class="btn-text" href="{{ $mLink }}">{{ $m['cta'] ?? '了解合作方式' }}<span class="arr">→</span></a>
        </div>
      @endforeach
    </div>
  </div>
</section>
@endif
