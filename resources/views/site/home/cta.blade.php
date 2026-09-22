{{-- S08 转化区（id=s08，全站 CTA 锚点）：左信息 / 右统一咨询表单；移动端表单提前。 --}}
@php
  $navPhone = config('copy.nav.phone');
  $navPhoneTel = config('copy.nav.phoneTel');
  $company = $company ?? \App\Support\Catalog::company();
@endphp
<section class="sec sec-tint" id="s08">
  <div class="wrap contact-grid">
    <div class="cta-info reveal">
      <span class="eyebrow">CONTACT · 联系我们</span>
      <h2 class="sec-h">{{ $blk->title ?: '告诉我们你的需求' }}</h2>
      <p class="sec-sub">{{ $blk->subtitle ?: '留下你的需求与联系方式，我们会尽快与你沟通。' }}</p>
      <ul class="cta-facts">
        @if(!empty($navPhone))
          <li>
            <span class="ci-k">合作热线</span>
            <a class="ci-v" href="tel:{{ $navPhoneTel }}">{{ $navPhone }}</a>
          </li>
        @endif
        @if(!empty($company['address']['full']))
        <li><span class="ci-k">地址</span><span class="ci-v">{{ $company['address']['full'] }}</span></li>
        @endif
        @if(!empty($company['target_customers']))
        <li><span class="ci-k">服务客户</span><span class="ci-v">{{ implode(' · ', $company['target_customers']) }}</span></li>
        @endif
      </ul>
    </div>
    <div class="reveal">
      @include('site._lead_form', ['leadFormId' => 'home-lead-form', 'leadClass' => 'standalone'])
    </div>
  </div>
</section>
