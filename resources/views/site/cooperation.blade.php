@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">COOPERATION · 合作方式</span>
    <h1 class="ph-h">{{ count($coop['types']) }} 种合作方式，从研发到稳定供货</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

{{-- 合作方式 --}}
<section class="sec">
  <div class="wrap">
    <div class="coop-grid3">
      @foreach($coop['types'] as $t)
        <div class="coop-mode reveal">
          <span class="feat-ic g">@include('site._icon', ['name' => 'check'])</span>
          <h2 class="cm-h">{{ $t['name'] }}</h2>
          <p class="cm-fit">{{ $t['fit'] }}</p>
          <ul class="coop-points">
            @foreach($t['includes'] as $inc)<li>{{ $inc }}</li>@endforeach
          </ul>
          <a class="btn-text" href="{{ url('/') }}#s08">{{ $t['cta'] }}<span class="arr">→</span></a>
        </div>
      @endforeach
    </div>
  </div>
</section>

{{-- 合作流程 --}}
@if(!empty($coop['process']))
<section class="sec sec-tint">
  <div class="wrap">
    <div class="sec-head center">
      <span class="eyebrow">PROCESS · 合作流程</span>
      <h2 class="sec-h">{{ count($coop['process']) }} 步走完，从沟通到持续供货</h2>
    </div>
    @include('site._process_steps', [
      'steps' => array_map(fn($s) => ['title' => $s['name'], 'text' => $s['desc']], $coop['process']),
      'small' => true,
    ])
  </div>
</section>
@endif

{{-- FAQ --}}
@if(!empty($faqs))
<section class="sec">
  <div class="wrap-narrow">
    <div class="sec-head center"><span class="eyebrow">FAQ · 常见问题</span><h2 class="sec-h">合作前，你可能想先确认这些</h2></div>
    @include('site._faq_list', ['faqs' => $faqs])
  </div>
</section>
@endif

@include('site._bottom_cta')
@endsection
