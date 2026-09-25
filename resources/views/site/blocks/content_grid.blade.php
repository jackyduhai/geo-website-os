{{-- 通用组合 Block · 内容网格（数据源）：标题 + 文章卡片（图 / 标题 / 摘要 / 可选日期）。 --}}
@php
  $c = $block->cfg();
  $cgTitle = trim((string) ($c['title'] ?? ''));
  $cgSub = trim((string) ($c['subtitle'] ?? ''));
  $cgCards = $data ?? [];
@endphp
@if(! empty($cgCards))
  <section class="sec">
    <div class="wrap">
      @if($cgTitle !== '' || $cgSub !== '')
        <div class="sec-head row">
          <div>
            @if($cgTitle)<h2 class="sec-h">{{ $cgTitle }}</h2>@endif
            @if($cgSub)<p class="sec-sub">{{ $cgSub }}</p>@endif
          </div>
        </div>
      @endif
      <div class="grid g3 reveal" style="gap:28px">
        @foreach($cgCards as $card)
          <a href="{{ $card['url'] }}" class="card" style="display:flex;flex-direction:column;overflow:hidden;padding:0">
            <div style="aspect-ratio:4/3;background:var(--tint);position:relative">
              @if(! empty($card['image']))
                <img src="{{ $card['image'] }}" alt="{{ $card['name'] }}" loading="lazy"
                     style="width:100%;height:100%;object-fit:cover">
              @else
                <span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--brand)">
                  @include('site._icon', ['name' => 'doc', 'size' => 36])
                </span>
              @endif
            </div>
            <div style="padding:18px 20px 20px">
              @if(! empty($card['date']))
                <span style="font-size:var(--fs-label);color:var(--ink-muted)">{{ $card['date']->format('Y-m-d') }}</span>
              @endif
              <h3 style="font-size:var(--fs-base);margin:4px 0 8px">{{ $card['name'] }}</h3>
              <p style="margin:0;font-size:var(--fs-xs);color:var(--ink-muted);line-height:1.7;
                 display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ $card['summary'] }}</p>
            </div>
          </a>
        @endforeach
      </div>
    </div>
  </section>
@endif
