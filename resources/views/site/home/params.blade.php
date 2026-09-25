{{-- S04 参数级交付（全站反白区块之一）：左差异化说明，右真实规格 / 参数 ParamTable。 --}}
@php
  $diffPoints = ! empty($paramDifferentiators) ? $paramDifferentiators : array_slice($differentiatorItems ?? [], 0, 3);
@endphp
@if(count($diffPoints) || count($paramRows ?? []))
<section class="sec is-inverse" id="s04">
  <div class="wrap">
    <div class="params-layout">
      <div class="params-side reveal">
        <span class="eyebrow accent">{{ __('ui.eyebrow_parameters') }}</span>
        <h2 class="sec-h">{{ $blk->title ?: __('ui.home_params_title') }}</h2>
        <p class="sec-sub muted">{{ $blk->subtitle ?: __('ui.home_params_sub') }}</p>
        @if(count($diffPoints))
        <ul class="params-points">
          @foreach($diffPoints as $d)
            <li><strong>{{ $d['title'] ?? '' }}</strong>：{{ $d['text'] ?? '' }}</li>
          @endforeach
        </ul>
        @endif
        <div class="actions" style="margin-top:var(--sp-6)">
          <a class="btn btn-primary btn-lg" href="{{ url('/') }}#s08">{{ $ctaText ?? __('ui.contact_us') }}<span class="arr">→</span></a>
        </div>
      </div>
      <div class="reveal">
        @include('site._param_table', [
          'rows' => $paramRows ?? [],
          'head' => [__('ui.tbl_head_product'), __('ui.tbl_head_param')],
        ])
        <p class="tbl-note muted">{{ __('ui.tbl_note') }}</p>
      </div>
    </div>
  </div>
</section>
@endif
