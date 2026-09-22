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
        发布于 {{ $content->published_at->format('Y-m-d') }}
        @if($content->reviewed_at) · 复核于 {{ $content->reviewed_at->format('Y-m-d') }} @endif
      </p>
    @endif
  </div>
</div>

<article class="wrap" style="padding-top:8px">

  {{-- 答案块：DOM 首个内容区块，结论前置 --}}
  @if($content->geo_conclusion)
    <section class="answer" aria-labelledby="conclusion">
      <div class="answer-t" id="conclusion">结论</div>
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
    <section class="sec" style="padding:24px 0 6px">
      <h2 class="sec-h" style="font-size:21px">说明</h2>
      <div class="prose" style="font-size:16px;line-height:1.9">{!! nl2br(e($content->geo_explanation)) !!}</div>
    </section>
  @endif

  {{-- 正文 --}}
  @if($content->body)
    <section class="sec" style="padding:24px 0 6px">
      <div class="prose" style="font-size:16px;line-height:1.9">
        {!! $content->bodyHtml() !!}
      </div>
    </section>
  @endif

  {{-- 证据链 --}}
  @if($evidence)
    <section class="sec" style="padding:28px 0 6px">
      <h2 class="sec-h" style="font-size:21px">依据</h2>
      <p class="sec-sub">以下为可核验的事实来源</p>
      <div class="grid g2" style="margin-top:16px">
        @foreach($evidence as $e)
          <div class="card">
            <div style="font-size:13px;color:var(--ink-muted);margin-bottom:5px">{{ $e['label'] ?? '' }}</div>
            <div style="font-size:16px;font-weight:700;margin-bottom:7px">{{ $e['value'] ?? '' }}</div>
            @if(!empty($e['source']))
              <div style="font-size:12.5px;color:var(--ink-muted)">来源：{{ $e['source'] }}</div>
            @endif
          </div>
        @endforeach
      </div>
    </section>
  @endif

  {{-- 边界 --}}
  @if($content->geo_boundary)
    <section class="sec" style="padding:28px 0 6px">
      <h2 class="sec-h" style="font-size:21px">适用范围与边界</h2>
      <div class="card" style="font-size:15px;line-height:1.85;margin-top:14px">{!! nl2br(e($content->geo_boundary)) !!}</div>
    </section>
  @endif

  {{-- FAQ --}}
  @if($faqs)
    <section class="sec" style="padding:28px 0 6px">
      <h2 class="sec-h" style="font-size:21px">常见问题</h2>
      <div style="margin-top:16px">
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
    <section class="sec" style="padding:34px 0 10px">
      <h2 class="sec-h" style="font-size:22px">在线留言</h2>
      <p style="color:var(--ink-muted);margin:0 0 18px">填写以下信息，业务会在工作时间与您联系；带 <span style="color:var(--brand);font-weight:700">*</span> 为必填。</p>
      @include('site._lead_form')
    </section>
  @elseif(!empty($siteSettings['contact_phone']))
    <section class="sec" style="padding:34px 0 10px">
      <div class="cta-band" style="text-align:center">
        <h2 style="font-size:23px">有产品或合作需求？欢迎联系我们。</h2>
        <p style="margin-left:auto;margin-right:auto">告诉我们你的需求，我们会尽快与你沟通对接。</p>
        <div class="cta-row" style="justify-content:center">
          <span class="cta-phone">{{ $siteSettings['contact_phone'] }}</span>
          <a class="btn-ghost btn-lg" href="{{ url('/contact/') }}">在线留言</a>
        </div>
      </div>
    </section>
  @endif

  {{-- 相关内容 --}}
  @if(($related ?? false) && $related->isNotEmpty())
    <section class="sec" style="padding:16px 0 0">
      <h2 class="sec-h" style="font-size:21px">相关内容</h2>
      <div class="posts" style="margin-top:14px">
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
