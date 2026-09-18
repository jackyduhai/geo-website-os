{{-- FAQ：details/summary，答案在初始 DOM（服务 SEO/GEO），默认全部折叠。
     变量：$faqs=[['q','a']]；可选 $openFirst。 --}}
@if(! empty($faqs))
<div class="faq-list">
  @foreach($faqs as $i => $f)
    <details @if(! empty($openFirst) && $i === 0) open @endif>
      <summary>
        <span>{{ $f['q'] }}</span>
        <svg class="faq-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
      </summary>
      <div class="faq-a">{{ $f['a'] }}</div>
    </details>
  @endforeach
</div>
@endif
