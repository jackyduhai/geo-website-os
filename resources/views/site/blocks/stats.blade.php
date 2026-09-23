{{-- 通用组合 Block · 数据指标：可选标题 + 大数字行（value 可含符号 / 单位，不强制数字格式化）。 --}}
@php
  $c = $block->cfg();
  $stTitle = trim((string) ($c['title'] ?? ''));
  $stRows = [];
  foreach (is_array($c['items'] ?? null) ? $c['items'] : [] as $i) {
      if (trim((string) ($i['value'] ?? '')) === '') { continue; }
      $stRows[] = ['value' => $i['value'], 'unit' => trim((string) ($i['unit'] ?? '')),
          'label' => trim((string) ($i['label'] ?? ''))];
  }
@endphp
@if(! empty($stRows))
  @if($stTitle !== '')
    <section class="sec sec-tint">
      <div class="wrap"><div class="sec-head center"><h2 class="sec-h">{{ $stTitle }}</h2></div></div>
    </section>
  @endif
  <section class="stats" aria-label="{{ $stTitle ?: __('ui.stats_aria') }}">
    <div class="wrap stats-in">
      @foreach($stRows as $s)
        <div class="stat reveal">
          <div class="stat-n"><span>{{ $s['value'] }}</span>@if($s['unit'] !== '')<i>{{ $s['unit'] }}</i>@endif</div>
          <div class="stat-l">{{ $s['label'] }}</div>
        </div>
      @endforeach
    </div>
  </section>
@endif
