{{-- 通用组合 Block · 产品网格（数据源）：标题 + 产品卡片（图 / 图标 + 名称 + 摘要）。
     卡片来自 BlockRegistry::resolveData，仅含公开落地页产品。 --}}
@php
  $c = $block->cfg();
  $pgTitle = trim((string) ($c['title'] ?? ''));
  $pgSub = trim((string) ($c['subtitle'] ?? ''));
  $pgCards = $data ?? [];
@endphp
@if(! empty($pgCards))
  <section class="sec" {!! $semanticAttrs ?? '' !!}>
    <div class="wrap">
      @if($pgTitle !== '' || $pgSub !== '')
        <div class="sec-head row">
          <div>
            @if($pgTitle)<h2 class="sec-h">{{ $pgTitle }}</h2>@endif
            @if($pgSub)<p class="sec-sub">{{ $pgSub }}</p>@endif
          </div>
        </div>
      @endif
      <div class="grid g3 reveal" style="gap:var(--sp-7)">
        @foreach($pgCards as $card)
          <a href="{{ $card['url'] }}" class="card" style="display:flex;flex-direction:column;overflow:hidden;padding:0">
            <div style="aspect-ratio:4/3;background:var(--tint);position:relative">
              @if(! empty($card['image']))
                <img src="{{ $card['image'] }}" alt="{{ $card['name'] }}" loading="lazy"
                     style="width:100%;height:100%;object-fit:cover">
              @else
                <span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--brand)">
                  @include('site._icon', ['name' => $card['icon'] ?? 'package', 'size' => 36])
                </span>
              @endif
            </div>
            <div style="padding:var(--sp-5) var(--sp-5) var(--sp-5)">
              <h3 style="font-size:var(--fs-base);margin:0 0 var(--sp-2)">{{ $card['name'] }}</h3>
              <p style="margin:0;font-size:var(--fs-xs);color:var(--ink-muted);line-height:1.7;
                 display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ $card['summary'] }}</p>
            </div>
          </a>
        @endforeach
      </div>
    </div>
  </section>
@endif
