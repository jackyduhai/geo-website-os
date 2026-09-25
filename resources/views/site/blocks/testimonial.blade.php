{{-- 通用组合 Block · 客户评价：标题 + 评价卡片（引用 / 姓名 / 职务·公司）。 --}}
@php
  $c = $block->cfg();
  $tmTitle = trim((string) ($c['title'] ?? ''));
  $tmRows = [];
  foreach (is_array($c['items'] ?? null) ? $c['items'] : [] as $i) {
      $quote = trim((string) ($i['quote'] ?? ''));
      if ($quote === '') { continue; }
      $tmRows[] = ['quote' => $quote, 'name' => trim((string) ($i['name'] ?? '')),
          'role' => trim((string) ($i['role'] ?? '')), 'company' => trim((string) ($i['company'] ?? ''))];
  }
@endphp
@if(! empty($tmRows))
  <section class="sec sec-tint">
    <div class="wrap">
      @if($tmTitle)<div class="sec-head center"><h2 class="sec-h">{{ $tmTitle }}</h2></div>@endif
      <div class="grid g3 reveal" style="gap:28px">
        @foreach($tmRows as $t)
          <figure class="card" style="padding:28px;margin:0">
            <span style="font-size:var(--fs-h2);line-height:1;color:var(--brand);font-weight:700" aria-hidden="true">&ldquo;</span>
            <blockquote style="margin:8px 0 16px;font-size:var(--fs-sm);line-height:1.8">{{ $t['quote'] }}</blockquote>
            <figcaption>
              <strong style="font-size:var(--fs-sm)">{{ $t['name'] }}</strong>
              @if($t['role'] !== '' || $t['company'] !== '')
                <span style="display:block;font-size:var(--fs-label);color:var(--ink-muted);margin-top:2px">
                  {{ $t['role'] }}@if($t['role'] !== '' && $t['company'] !== '') · @endif{{ $t['company'] }}
                </span>
              @endif
            </figcaption>
          </figure>
        @endforeach
      </div>
    </div>
  </section>
@endif
