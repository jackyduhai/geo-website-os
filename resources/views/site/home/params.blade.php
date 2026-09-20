{{-- S04 参数级交付（全站反白区块之一）：左差异化说明，右真实规格 / 工艺 ParamTable。 --}}
@php
  $diffPoints = ! empty($paramDifferentiators) ? $paramDifferentiators : array_slice($differentiatorItems ?? [], 0, 3);
@endphp
<section class="sec is-inverse" id="s04">
  <div class="wrap">
    <div class="params-layout">
      <div class="params-side reveal">
        <span class="eyebrow accent">PARAMETERS · 参数级交付</span>
        <h2 class="sec-h">{{ $blk->title ?: '把参数做到用量、温度和时间，产线照着就能复现' }}</h2>
        <p class="sec-sub muted">{{ $blk->subtitle ?: '不止给一款产品，而是给出标准化规格与工艺参数，不同批次、不同产线都能稳定还原同一品质。' }}</p>
        @if(count($diffPoints))
        <ul class="params-points">
          @foreach($diffPoints as $d)
            <li><strong>{{ $d['title'] ?? '' }}</strong>：{{ $d['text'] ?? '' }}</li>
          @endforeach
        </ul>
        @endif
        <div class="actions" style="margin-top:24px">
          <a class="btn btn-primary btn-lg" href="{{ url('/') }}#s08">{{ $ctaText ?? '免费获取样品' }}<span class="arr">→</span></a>
        </div>
      </div>
      <div class="reveal">
        @include('site._param_table', [
          'rows' => $paramRows ?? [],
          'head' => ['产品 / 规格', '用量 / 工艺参数'],
        ])
        <p class="tbl-note muted">以上为工艺说明参数，具体以对应产品规格表为准，可按目标需求定向调整。</p>
      </div>
    </div>
  </div>
</section>
