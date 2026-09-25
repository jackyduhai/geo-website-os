@extends('layouts.site')

@section('content')

@php
  $evidence = $content->evidenceList();
  $faqs     = $content->faqList();
  $keyFacts = $content->keyFactList();
@endphp

<div class="page-head">
  <div class="wrap">
    <h1>{{ $content->title }}</h1>
    @if($content->published_at)
      <p class="meta" style="margin:0">
        {{ __('ui.c_published', ['date' => $content->published_at->format('Y-m-d')]) }}
        @if($content->reviewed_at) · {{ __('ui.c_reviewed', ['date' => $content->reviewed_at->format('Y-m-d')]) }} @endif
      </p>
    @endif
  </div>
</div>

<article class="wrap" style="padding-top:8px">

  {{-- 答案块：DOM 首个内容区块，结论前置 --}}
  @if($content->geo_conclusion)
    <section class="answer" aria-labelledby="conclusion">
      <div class="answer-t" id="conclusion">{{ __('ui.c_conclusion') }}</div>
      <p class="answer-c">{{ $content->geo_conclusion }}</p>
    </section>
  @endif

  {{-- 关键事实：可被直接引用的硬信息 --}}
  @if($keyFacts)
    <dl class="facts auto">
      @foreach($keyFacts as $k)
        <div><dt>{{ $k['key'] }}</dt><dd>{{ $k['value'] }}</dd></div>
      @endforeach
    </dl>
  @endif

  {{-- 解释 --}}
  @if($content->geo_explanation)
    <section class="sec" style="padding:var(--sp-6) 0 var(--sp-2)">
      <h2 class="sec-h" style="font-size:var(--fs-h3)">{{ __('ui.c_explain') }}</h2>
      <div class="prose" style="font-size:var(--fs-base);line-height:1.9">{!! nl2br(e($content->geo_explanation)) !!}</div>
    </section>
  @endif

  {{-- 正文 --}}
  @if($content->body)
    <section class="sec" style="padding:var(--sp-6) 0 var(--sp-2)">
      <div class="prose" style="font-size:var(--fs-base);line-height:1.9">
        {!! $content->bodyHtml() !!}
      </div>
    </section>
  @endif

  {{-- 证据链 --}}
  @if($evidence)
    <section class="sec" style="padding:var(--sp-7) 0 var(--sp-2)">
      <h2 class="sec-h" style="font-size:var(--fs-h3)">{{ __('ui.c_evidence') }}</h2>
      <p class="sec-sub">{{ __('ui.c_evidence_sub') }}</p>
      <div class="grid g2" style="margin-top:var(--sp-4)">
        @foreach($evidence as $e)
          <div class="card">
            <div style="font-size:var(--fs-2xs);color:var(--ink-muted);margin-bottom:var(--sp-1)">{{ $e['label'] ?? '' }}</div>
            <div style="font-size:var(--fs-base);font-weight:700;margin-bottom:var(--sp-2)">{{ $e['value'] ?? '' }}</div>
            @if(!empty($e['source']))
              <div style="font-size:var(--fs-label);color:var(--ink-muted)">{{ __('ui.c_source') }}{{ $e['source'] }}</div>
            @endif
          </div>
        @endforeach
      </div>
    </section>
  @endif

  {{-- 边界 --}}
  @if($content->geo_boundary)
    <section class="sec" style="padding:var(--sp-7) 0 var(--sp-2)">
      <h2 class="sec-h" style="font-size:var(--fs-h3)">{{ __('ui.c_boundary') }}</h2>
      <div class="card" style="font-size:var(--fs-sm);line-height:1.85;margin-top:var(--sp-4)">{!! nl2br(e($content->geo_boundary)) !!}</div>
    </section>
  @endif

  {{-- FAQ --}}
  @if($faqs)
    <section class="sec" style="padding:var(--sp-7) 0 var(--sp-2)">
      <h2 class="sec-h" style="font-size:var(--fs-h3)">{{ __('ui.c_faq') }}</h2>
      <div style="margin-top:var(--sp-4)">
        @foreach($faqs as $f)
          <details class="faq">
            <summary>{{ $f['q'] }}</summary>
            <p class="faq-a">{{ $f['a'] }}</p>
          </details>
        @endforeach
      </div>
    </section>
  @endif

  {{-- 转化入口：联系页直接放留言表单，其余页面放 CTA 色带 --}}
  @if(optional($content->category)->slug === 'contact')
    <section class="sec" style="padding:var(--sp-9) 0 var(--sp-3)">
      <h2 class="sec-h" style="font-size:var(--fs-h3)">{{ __('ui.c_inquiry') }}</h2>
      <p style="color:var(--ink-muted);margin:0 0 var(--sp-5)">{{ __('ui.c_inquiry_sub') }}</p>
      @php $contentLeadForm = app(\App\Support\Forms\FormResolver::class)->defaultContact(); @endphp
      @if($contentLeadForm)
        @include('site.dynamic_form', ['formModel' => $contentLeadForm, 'leadFormId' => 'content-lead-form'])
      @endif
    </section>
  @elseif(!empty($siteSettings['contact_phone']))
    <section class="sec" style="padding:var(--sp-9) 0 var(--sp-3)">
      <div class="cta-band" style="text-align:center">
        <h2 style="font-size:var(--fs-h3)">{{ __('ui.c_cta_h2') }}</h2>
        <p style="margin-left:auto;margin-right:auto">{{ __('ui.c_cta_sub') }}</p>
        <div class="cta-row" style="justify-content:center">
          <span class="cta-phone">{{ $siteSettings['contact_phone'] }}</span>
          <a class="btn-ghost btn-lg" href="{{ url('/contact/') }}">{{ __('ui.c_inquiry') }}</a>
        </div>
      </div>
    </section>
  @endif

  {{-- 相关内容 --}}
  @if(($related ?? false) && $related->isNotEmpty())
    <section class="sec" style="padding:var(--sp-4) 0 0">
      <h2 class="sec-h" style="font-size:var(--fs-h3)">{{ __('ui.c_related') }}</h2>
      <div class="posts" style="margin-top:var(--sp-4)">
        @foreach($related as $p)
          <a class="post" href="{{ $p->url() }}">
            <h3>{{ $p->title }}</h3>
            <p>{{ $p->summary }}</p>
          </a>
        @endforeach
      </div>
    </section>
  @endif

</article>

@endsection
