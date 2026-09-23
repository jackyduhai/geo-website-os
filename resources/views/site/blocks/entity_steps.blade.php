{{-- Entity Detail 系统块 · 步骤 / 流程（Entity 直驱，无数据不渲染）。
     Product：使用步骤（sec-tint）；Service：使用流程建议。 --}}
@php
  $isService = ($entity->type ?? '') === 'service';
  $isP2 = ($scene['priority'] ?? 'P0') === 'P2';

  $processSteps = array_map(
      fn ($p) => [
          'title' => $p['step'],
          'text'  => $p['value'] . (filled($p['note'] ?? null) ? '（' . $p['note'] . '）' : ''),
      ],
      $product['params'] ?? []
  );

  $flowSteps = [];
  if ($isService && ! $isP2 && $keyProduct) {
      foreach (($keyProduct['params'] ?? []) as $p) {
          $flowSteps[] = [
              'title' => $p['step'],
              'text'  => $p['value'] . (filled($p['note'] ?? null) ? '（' . $p['note'] . '）' : ''),
          ];
      }
  }
@endphp

@if($isService)
  @if(! empty($flowSteps))
  <section class="sec">
    <div class="wrap">
      <div class="sec-head">
        <span class="eyebrow">{{ __('ui.workflow_eyebrow') }}</span>
        <h2 class="sec-h">{{ __('ui.workflow_h2') }}</h2>
      </div>
      @include('site._process_steps', ['steps' => $flowSteps])
    </div>
  </section>
  @endif
@else
  @if(! empty($processSteps))
  <section class="sec sec-tint">
    <div class="wrap">
      <div class="sec-head">
        <span class="eyebrow">{{ __('ui.eyebrow_usage') }}</span>
        <h2 class="sec-h">{{ __('ui.usage_h2') }}</h2>
      </div>
      @include('site._process_steps', ['steps' => $processSteps])
    </div>
  </section>
  @endif
@endif
