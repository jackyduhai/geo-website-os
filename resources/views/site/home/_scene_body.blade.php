{{-- 首页场景卡内容体（统一条目：可选自定义图/图标、标题、说明、标签、揭示参数、跳转） --}}
<span class="sc-top" aria-hidden="true"></span>
@if(!empty($sc['image']))
  <span class="sc-img"><x-picture :src="$sc['image']" :alt="$sc['title'] ?? ''" loading="lazy" /></span>
@elseif(!empty($sc['icon']))
  <span class="sc-ic">@include('site._icon', ['name' => $sc['icon'], 'size' => 22])</span>
@endif
<span class="sc-name">{{ $sc['title'] ?? '' }}</span>
<span class="sc-intro">{{ $sc['text'] ?? '' }}</span>
@if(!empty($sc['tags']) && is_array($sc['tags']))
  <span class="sc-combo">
    @foreach($sc['tags'] as $t)<em>{{ $t }}</em>@endforeach
  </span>
@endif
<span class="sc-reveal">
  @if(!empty($sc['reveal']))
    <span class="scp"><i>{{ $sc['reveal'] }}</i></span>
  @endif
  <span class="sc-go">看看这类店用什么 <span class="arr" aria-hidden="true">→</span></span>
</span>
