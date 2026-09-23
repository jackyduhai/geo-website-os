{{-- S6 资质与产能（已核定事实，GEO 证据层；首页精选 8 条，全量见关于页，禁止虚构） --}}
@if(($factRows ?? collect())->isNotEmpty())
@php($homeFacts = $factRows->take(8))
<section class="sec">
  <div class="wrap">
    <div class="sec-head row">
      <div>
        <span class="eyebrow">{{ __('ui.eyebrow_trust') }}</span>
        <h2 class="sec-h">{{ $blk->title ?: __('ui.home_facts_title') }}</h2>
      </div>
      <a class="btn-text" href="{{ url('/about/profile/') }}">{{ __('ui.home_facts_btn') }}<span class="arr">→</span></a>
    </div>
    <dl class="facts reveal">
      @foreach($homeFacts as $f)
        <div class="fact">
          <dt>{{ $f['label'] }}</dt>
          <dd>{{ $f['value'] }}</dd>
        </div>
      @endforeach
    </dl>
  </div>
</section>
@endif
