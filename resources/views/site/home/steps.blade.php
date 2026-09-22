{{-- S5 合作流程（描边数字 + 连线；步骤条目可后台装修，序号自动生成） --}}
@php($st = $stepItems ?? [])
@if(count($st))
<section class="sec sec-tint">
  <div class="wrap">
    <div class="sec-head center">
      <span class="eyebrow">PROCESS · 合作流程</span>
      <h2 class="sec-h">{{ $blk->title ?: count($st).' 步标准合作流程' }}</h2>
      <p class="sec-sub">{{ $blk->subtitle }}</p>
    </div>
    <div class="steps">
      @foreach($st as $i => $s)
        <div class="step reveal">
          <span class="step-n">{{ $i + 1 }}</span>
          <h4>{{ $s['title'] ?? '' }}</h4>
          <p>{{ $s['text'] ?? '' }}</p>
        </div>
      @endforeach
    </div>
  </div>
</section>
@endif
