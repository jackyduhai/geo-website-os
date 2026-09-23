{{-- S1 信任数据：浅色大数字行（数字只从已核定事实派生，禁止虚构） --}}
@if(!empty($stats))
<section class="stats" aria-label="{{ __('ui.stats_aria') }}">
  <div class="wrap stats-in">
    @foreach($stats as $s)
      <div class="stat reveal">
        <div class="stat-n"><span data-count="{{ $s['num'] }}">{{ number_format((int) $s['num']) }}</span><i>{{ $s['unit'] }}</i></div>
        <div class="stat-l">{{ $s['label'] }}</div>
      </div>
    @endforeach
  </div>
</section>
@endif
