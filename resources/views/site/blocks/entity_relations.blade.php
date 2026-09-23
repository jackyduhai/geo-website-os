{{-- Entity Detail 系统块 · 关联实体（Entity 直驱，按 part 渲染）。
     Product  main(part=scenes)   适用场景 chips；related(part=related) 相关产品 + bottom CTA。
     Service  main(part=pain_combo) 痛点 + 推荐组合；related(part=adjacent) 相邻场景 + bottom CTA。
     相关 / 相邻均复用原 partials（_product_card / adj-card），保持迁移前后对拍一致。 --}}
@php
  $isService = ($entity->type ?? '') === 'service';
  $part = (string) ($block->cfg()['part'] ?? '');
@endphp

@if($isService)
  @if($part === 'adjacent')
    @if(! empty($prev) || ! empty($next))
    <section class="sec">
      <div class="wrap">
        <div class="adj-grid">
          @foreach(['prev' => $prev, 'next' => $next] as $dir => $adj)
            @if(! empty($adj))
              <a class="adj-card" href="{{ url('/solutions/' . $adj['slug'] . '/') }}">
                <span class="adj-dir">{{ $dir === 'prev' ? __('ui.adj_prev') : __('ui.adj_next') }}</span>
                <span class="adj-name">{{ $adj['name'] }}<span class="arr">→</span></span>
              </a>
            @endif
          @endforeach
        </div>
      </div>
    </section>
    @endif
  @else
    @if(! empty($scene['pain_points']))
    <section class="sec">
      <div class="wrap">
        <div class="sec-head">
          <span class="eyebrow">{{ __('ui.pain_eyebrow') }}</span>
          <h2 class="sec-h">{{ __('ui.pain_h2') }}</h2>
        </div>
        <div class="pain-grid">
          @foreach($scene['pain_points'] as $pp)
            <div class="pain-card reveal">
              <h3>{{ $pp['title'] }}</h3>
              <p>{{ $pp['desc'] }}</p>
            </div>
          @endforeach
        </div>
      </div>
    </section>
    @endif

    @if(! empty($combo))
    <section class="sec sec-tint">
      <div class="wrap">
        <div class="sec-head">
          <span class="eyebrow">{{ __('ui.combo_eyebrow') }}</span>
          <h2 class="sec-h">{{ __('ui.combo_h2') }}</h2>
          @if(! empty($scene['combo_reason']))<p class="sec-sub">{{ $scene['combo_reason'] }}</p>@endif
        </div>
        <div class="prod-grid combo-grid">
          @foreach($combo as $cp)
            @include('site._product_card', ['p' => $cp])
          @endforeach
        </div>
      </div>
    </section>
    @endif
  @endif
@else
  @if($part === 'related')
    @if(! empty($related))
    <section class="sec">
      <div class="wrap">
        <div class="sec-head">
          <span class="eyebrow">{{ __('ui.eyebrow_related') }}</span>
          <h2 class="sec-h">{{ __('ui.related_h2') }}</h2>
        </div>
        <div class="prod-grid">
          @foreach($related as $rp)
            @include('site._product_card', ['p' => $rp])
          @endforeach
        </div>
      </div>
    </section>
    @endif
  @else
    @if(! empty($scenes))
    <section class="sec">
      <div class="wrap">
        <div class="sec-head row">
          <div>
            <span class="eyebrow">{{ __('ui.eyebrow_scen') }}</span>
            <h2 class="sec-h">{{ __('ui.scen_h2') }}</h2>
          </div>
          <a class="btn-text" href="{{ url('/solutions/') }}">{{ __('ui.all_scen') }}<span class="arr">→</span></a>
        </div>
        <div class="scene-links">
          @foreach($scenes as $sc)
            <a class="scene-chip" href="{{ url('/solutions/' . $sc['slug'] . '/') }}">{{ $sc['name'] }}<span class="arr">→</span></a>
          @endforeach
        </div>
      </div>
    </section>
    @endif
  @endif
@endif
