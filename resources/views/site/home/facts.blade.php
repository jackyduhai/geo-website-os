{{-- S6 资质与产能（已核定事实，GEO 证据层；首页精选 8 条，全量见关于页，禁止虚构） --}}
@if(($factRows ?? collect())->isNotEmpty())
@php($homeFacts = $factRows->take(8))
<section class="sec">
  <div class="wrap">
    <div class="sec-head row">
      <div>
        <span class="eyebrow">TRUST · 资质与产能</span>
        <h2 class="sec-h">{{ $blk->title ?: '可核验的资质、产能与交付能力' }}</h2>
      </div>
      <a class="btn-text" href="{{ url('/about/profile/') }}">查看企业概况<span class="arr">→</span></a>
    </div>
    <dl class="facts reveal">
      @foreach($homeFacts as $f)
        <div class="fact">
          <dt>{{ $f->label }}</dt>
          <dd>{{ $f->value }}</dd>
        </div>
      @endforeach
    </dl>
  </div>
</section>
@endif
