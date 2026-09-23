{{-- 通用组合 Block · FAQ：标题 + details 折叠（答案在初始 DOM，服务 SEO/GEO）；产出 FAQPage 结构化数据。 --}}
@php
  $c = $block->cfg();
  $fqTitle = trim((string) ($c['title'] ?? ''));
  $fqSub = trim((string) ($c['subtitle'] ?? ''));
  $fqPairs = [];
  foreach (is_array($c['items'] ?? null) ? $c['items'] : [] as $i) {
      $q = trim((string) ($i['q'] ?? ''));
      $a = trim((string) ($i['a'] ?? ''));
      if ($q !== '' && $a !== '') { $fqPairs[] = ['q' => $q, 'a' => $a]; }
  }
@endphp
@if(! empty($fqPairs))
  <section class="sec{{ ! empty($c['tint']) ? ' sec-tint' : '' }}" id="block-faq-{{ $block->id }}">
    <div class="wrap-narrow">
      <div class="sec-head center">
        <span class="eyebrow">{{ __('ui.eyebrow_faq') }}</span>
        @if($fqTitle)<h2 class="sec-h">{{ $fqTitle }}</h2>@endif
        @if($fqSub)<p class="sec-sub">{{ $fqSub }}</p>@endif
      </div>
      @include('site._faq_list', ['faqs' => $fqPairs])
    </div>
  </section>
@endif
