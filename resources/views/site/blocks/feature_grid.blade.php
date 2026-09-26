{{-- 通用组合 Block · 特性网格：标题 + 卡片网格（图标 / 标题 / 说明），列数 2-4。 --}}
@php
  $c = $block->cfg();
  $fgTitle = trim((string) ($c['title'] ?? ''));
  $fgSub = trim((string) ($c['subtitle'] ?? ''));
  $fgCols = max(2, min(4, (int) ($c['columns'] ?? 3)));
  $fgItems = is_array($c['items'] ?? null) ? $c['items'] : [];
  $fgItems = array_filter($fgItems, fn ($i) => trim((string) ($i['title'] ?? '')) !== '');
@endphp
@if(! empty($fgItems))
  <section class="sec" {!! $semanticAttrs ?? '' !!}>
    <div class="wrap">
      @if($fgTitle !== '' || $fgSub !== '')
        <div class="sec-head center">
          @if($fgTitle)<h2 class="sec-h">{{ $fgTitle }}</h2>@endif
          @if($fgSub)<p class="sec-sub">{{ $fgSub }}</p>@endif
        </div>
      @endif
      <div class="grid reveal" style="grid-template-columns:repeat({{ $fgCols }},1fr);gap:var(--sp-7)">
        @foreach($fgItems as $it)
          <div class="card" style="padding:var(--sp-7)">
            <span class="feat-ic">@include('site._icon', ['name' => $it['icon'] ?? 'default'])</span>
            <h3 style="margin:var(--sp-4) 0 var(--sp-2);font-size:var(--fs-h4)">{{ $it['title'] ?? '' }}</h3>
            <p style="margin:0;color:var(--ink-muted);font-size:var(--fs-xs);line-height:1.7">{{ $it['text'] ?? '' }}</p>
          </div>
        @endforeach
      </div>
    </div>
  </section>
@endif
