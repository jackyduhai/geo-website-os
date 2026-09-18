{{-- S07 客户合作剪影（匿名）：后台条目优先（可改文案/换图），缺省由 Facts 规范化；不出现客户名/商标/效果数字。 --}}
@if(! empty($caseList))
<section class="sec" id="s07">
  <div class="wrap-wide">
    <div class="sec-head center">
      <span class="eyebrow">CASES · 合作剪影</span>
      <h2 class="sec-h">{{ $blk->title ?? '不同生意，都在用同一套稳定标准' }}</h2>
      <p class="sec-sub">{{ $blk->subtitle ?? '为保护客户经营信息，以下均做匿名处理，仅呈现业态与所用产品组合。' }}</p>
    </div>
    <div class="case-grid">
      @foreach($caseList as $case)
        <article class="case-card reveal">
          @if(!empty($case['image']))
            <div class="case-img"><x-picture :src="$case['image']" :alt="$case['title'] ?? ''" loading="lazy" /></div>
          @endif
          <div class="case-meta">
            <span class="case-type">{{ $case['title'] ?? '' }}</span>
            @if(!empty($case['sub']))<span class="case-region">{{ $case['sub'] }}</span>@endif
          </div>
          @if(!empty($case['tags']) && is_array($case['tags']))
            <div class="case-combo">
              @foreach($case['tags'] as $cn)<em>{{ $cn }}</em>@endforeach
            </div>
          @endif
          @if(!empty($case['text']))<p class="case-use">{{ $case['text'] }}</p>@endif
        </article>
      @endforeach
    </div>
  </div>
</section>
@endif
