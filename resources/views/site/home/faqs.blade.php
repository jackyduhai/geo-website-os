{{-- S09 首页 FAQ（narrow 960，details 折叠且答案在初始 DOM；FAQPage JSON-LD 由 HomeController 生成） --}}
@if(!empty($homeFaqs))
@php
  $faqPairs = array_map(fn ($f) => ['q' => $f['title'] ?? ($f['q'] ?? ''), 'a' => $f['text'] ?? ($f['a'] ?? '')], $homeFaqs);
@endphp
<section class="sec" id="s09">
  <div class="wrap-narrow">
    <div class="sec-head center">
      <span class="eyebrow">FAQ · 常见问题</span>
      <h2 class="sec-h">{{ $blk->title ?: '合作前，你可能想先了解这些' }}</h2>
    </div>
    @include('site._faq_list', ['faqs' => $faqPairs])
  </div>
</section>
@endif
