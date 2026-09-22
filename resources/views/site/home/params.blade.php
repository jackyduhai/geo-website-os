{{-- S04 参数级交付（全站反白区块之一）：左差异化说明，右真实规格 / 参数 ParamTable。 --}}
@php
  $diffPoints = ! empty($paramDifferentiators) ? $paramDifferentiators : array_slice($differentiatorItems ?? [], 0, 3);
@endphp
@if(count($diffPoints) || count($paramRows ?? []))
<section class="sec is-inverse" id="s04">
  <div class="wrap">
    <div class="params-layout">
      <div class="params-side reveal">
        <span class="eyebrow accent">PARAMETERS · 参数级交付</span>
        <h2 class="sec-h">{{ $blk->title ?: '标准化参数，清晰可核对' }}</h2>
        <p class="sec-sub muted">{{ $blk->subtitle ?: '提供标准化规格与参数说明，便于按需核对与稳定落地。' }}</p>
        @if(count($diffPoints))
        <ul class="params-points">
          @foreach($diffPoints as $d)
            <li><strong>{{ $d['title'] ?? '' }}</strong>：{{ $d['text'] ?? '' }}</li>
          @endforeach
        </ul>
        @endif
        <div class="actions" style="margin-top:24px">
          <a class="btn btn-primary btn-lg" href="{{ url('/') }}#s08">{{ $ctaText ?? '联系我们' }}<span class="arr">→</span></a>
        </div>
      </div>
      <div class="reveal">
        @include('site._param_table', [
          'rows' => $paramRows ?? [],
          'head' => ['产品 / 规格', '参数 / 说明'],
        ])
        <p class="tbl-note muted">以上为参考参数，具体以对应产品规格说明为准，可按实际需求调整。</p>
      </div>
    </div>
  </div>
</section>
@endif
