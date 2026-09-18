{{-- S08 转化区（id=s08，全站 CTA 锚点）：左信息 / 右统一咨询表单；移动端表单提前。 --}}
@php
  $navPhone = config('copy.nav.phone');
  $navPhoneTel = config('copy.nav.phoneTel');
  $company = $company ?? \App\Support\Facts::company();
@endphp
<section class="sec sec-tint" id="s08">
  <div class="wrap contact-grid">
    <div class="cta-info reveal">
      <span class="eyebrow">CONTACT · 获取样品</span>
      <h2 class="sec-h">{{ $blk->title ?: '先拿一份样品试试' }}</h2>
      <p class="sec-sub">{{ $blk->subtitle ?: '说清你的产品和口味方向，我们安排寄样，并附上标准化配比与工艺参数。' }}</p>
      <ul class="cta-facts">
        @if(!empty($navPhone))
          <li>
            <span class="ci-k">全国合作热线</span>
            <a class="ci-v" href="tel:{{ $navPhoneTel }}">{{ $navPhone }}</a>
          </li>
        @endif
        <li><span class="ci-k">厂区地址</span><span class="ci-v">{{ $company['address']['full'] ?? '' }}</span></li>
        <li><span class="ci-k">服务客户</span><span class="ci-v">Sample Snack门店 · 连锁品牌 · 渠道经销商</span></li>
      </ul>
    </div>
    <div class="reveal">
      @include('site._lead_form', ['leadFormId' => 'home-lead-form', 'leadClass' => 'standalone'])
    </div>
  </div>
</section>
