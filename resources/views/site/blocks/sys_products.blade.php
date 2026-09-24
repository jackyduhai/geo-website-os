{{-- 系统块 sys_products：产品总览 / 单系列主体。
     - 总览（默认）：page-hero + 按系列分组 + 底部 CTA（$lines / $lead / $flatMode）。
     - 单系列（$singleLine + $lineProducts）：page-hero + 该系列产品网格。
     数据由 ProductController 从 Catalog / Narrative 准备，经 Render Context 注入；
     subnav 由 composed 布局统一渲染，不在此重复。 --}}
@if(! empty($singleLine))
  <section class="page-hero">
    <div class="wrap-narrow">
      <span class="eyebrow">{{ __('ui.product_series') }}</span>
      <h1 class="ph-h">{{ $singleLine['name'] }}</h1>
      <p class="ph-lead">{{ $singleLine['desc'] ?? '' }}</p>
    </div>
  </section>

  <section class="sec">
    <div class="wrap">
      <div class="prod-grid">
        @foreach($lineProducts as $p)
          @include('site._product_card', ['p' => $p, 'lineName' => $singleLine['name']])
        @endforeach
      </div>
    </div>
  </section>

  @include('site._bottom_cta')
@else
  <section class="page-hero">
    <div class="wrap-narrow">
      <span class="eyebrow">{{ __('ui.eyebrow_products') }}</span>
      @if($flatMode ?? false)
      <h1 class="ph-h">{{ __('ui.products') }}</h1>
      @else
      <h1 class="ph-h">{{ __('ui.big_lines', ['count' => count($lines)]) }}</h1>
      @endif
      <p class="ph-lead">{{ $lead }}</p>
    </div>
  </section>

  @foreach($lines as $line)
    <section class="sec {{ $loop->even ? 'sec-tint' : '' }}" id="{{ $line['slug'] ?? 'all' }}">
      <div class="wrap">
        <div class="sec-head row">
          <div>
            <h2 class="sec-h">{{ $line['name'] }}<span class="pcard-count">{{ __('ui.unit_count', ['count' => count($line['products'])]) }}</span></h2>
            <p class="sec-sub">{{ $line['desc'] ?? '' }}</p>
          </div>
          @if(!empty($line['slug']) && count($line['products']) >= 1)
            <a class="btn-text" href="{{ url('/products/' . $line['slug'] . '/') }}">{{ __('ui.view_line') }}<span class="arr">→</span></a>
          @endif
        </div>
        @if(count($line['products']))
          <div class="prod-grid">
            @foreach($line['products'] as $p)
              @include('site._product_card', ['p' => $p, 'lineName' => $line['name']])
            @endforeach
          </div>
        @else
          <p class="empty-note">{{ __('ui.line_empty') }}</p>
        @endif
      </div>
    </section>
  @endforeach

  @include('site._bottom_cta')
@endif
