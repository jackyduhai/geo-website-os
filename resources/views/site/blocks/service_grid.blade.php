{{-- 通用组合 Block · 服务网格（数据源）：标题 + 服务卡片，仅含公开页服务。 --}}
@php
  $c = $block->cfg();
  $sgTitle = trim((string) ($c['title'] ?? ''));
  $sgSub = trim((string) ($c['subtitle'] ?? ''));
  $sgCards = $data ?? [];
@endphp
@if(! empty($sgCards))
  <section class="sec sec-tint">
    <div class="wrap">
      @if($sgTitle !== '' || $sgSub !== '')
        <div class="sec-head row">
          <div>
            @if($sgTitle)<h2 class="sec-h">{{ $sgTitle }}</h2>@endif
            @if($sgSub)<p class="sec-sub">{{ $sgSub }}</p>@endif
          </div>
        </div>
      @endif
      <div class="grid g3 reveal" style="gap:var(--sp-7)">
        @foreach($sgCards as $card)
          <a href="{{ $card['url'] }}" class="card" style="display:flex;flex-direction:column;overflow:hidden;padding:0">
            <div style="aspect-ratio:4/3;background:var(--surface,#fff);position:relative">
              @if(! empty($card['image']))
                <img src="{{ $card['image'] }}" alt="{{ $card['name'] }}" loading="lazy"
                     style="width:100%;height:100%;object-fit:cover">
              @else
                <span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--brand)">
                  @include('site._icon', ['name' => $card['icon'] ?? 'sliders', 'size' => 36])
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
