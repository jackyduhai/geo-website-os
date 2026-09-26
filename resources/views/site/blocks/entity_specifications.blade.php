{{-- Entity Detail 系统块 · 规格 / 参数表（Entity 直驱，无数据不渲染）。
     Product：规格与交付表（sec-tint）；Service：关键参数证据表（is-inverse）。 --}}
@php
  $isService = ($entity->type ?? '') === 'service';

  $specRows = collect([
      [__('ui.spec_net'), $product['net_weight'] ?? null],
      [__('ui.spec_packaging'), $product['packaging'] ?? null],
      [__('ui.spec_shelf'), $product['shelf_life'] ?? null],
      [__('ui.spec_storage'), $product['storage'] ?? null],
      [__('ui.spec_moq'), $product['moq'] ?? null],
  ])->filter(fn ($r) => filled($r[1]))->values()->all();

  $isP2 = ($scene['priority'] ?? 'P0') === 'P2';
  $paramRows = [];
  if ($isService) {
      if ($isP2) {
          foreach (($scene['param_note'] ?? []) as $pn) {
              $paramRows[] = ['label' => $pn['label'], 'value' => $pn['value']];
          }
      } elseif ($keyProduct) {
          foreach (($keyProduct['key_params'] ?? []) as $kp) {
              $paramRows[] = ['label' => $kp['label'], 'value' => $kp['value']];
          }
      }
  }
@endphp

@if($isService)
  @if(! empty($paramRows))
  <section class="sec is-inverse" {!! \App\Support\Blocks\SectionSemantic::forSection('product','education','Service') !!}>
    <div class="wrap-narrow">
      <div class="sec-head">
        <span class="eyebrow accent">{{ __('ui.params_eyebrow') }}</span>
        <h2 class="sec-h">{{ $isP2 ? __('ui.params_h2_p2') : __('ui.params_h2_p0') }}</h2>
      </div>
      @include('site._param_table', ['rows' => $paramRows])
      @if(! $isP2 && ! empty($scene['key_param_display']))
        <p class="tbl-note muted">{{ $scene['key_param_display'] }}</p>
      @endif
    </div>
  </section>
  @endif
@else
  @if(! empty($specRows))
  <section class="sec sec-tint" {!! \App\Support\Blocks\SectionSemantic::forSection('product','education','Product') !!}>
    <div class="wrap-narrow">
      <div class="sec-head">
        <span class="eyebrow">{{ __('ui.eyebrow_spec') }}</span>
        <h2 class="sec-h">{{ __('ui.spec_h2') }}</h2>
      </div>
      @include('site._param_table', ['rows' => $specRows])
    </div>
  </section>
  @endif
@endif
