{{-- 统一收口 CTA：浅灰非反白。可选 $variant='factory'（全站唯一变体）。联系页不输出。
     文案后台「系统 → 站点设置 → 文案话术」可改，取数层 App\Support\Copy（留空回退默认）。 --}}
@php
  $bc = \App\Support\Copy::bcta($variant ?? null);
  $bcTitle = $bc['title'];
  $bcDesc  = $bc['desc'];
  $bcPrimary = $bc['primaryCta'];
  $bcSecondary = $bc['secondaryCta'];
  $bcPhone = config('copy.nav.phone');
  $bcPhoneTel = config('copy.nav.phoneTel');
@endphp
<section class="bcta" aria-labelledby="bcta-title" {!! \App\Support\Blocks\SectionSemantic::forSection('conversion','conversion','Organization','contact') !!}>
  <div class="wrap bcta-in">
    <h2 id="bcta-title">{{ $bcTitle }}</h2>
    <p class="bcta-d">{{ $bcDesc }}</p>
    <div class="actions">
      <a class="btn btn-lg btn-primary" href="{{ url('/') }}#s08">{{ $bcPrimary }}</a>
      <a class="btn btn-lg btn-outline" href="{{ url('/cooperation/') }}">{{ $bcSecondary }}</a>
    </div>
    @if(!empty($bcPhone))
    <div class="bcta-phone">{{ __('ui.bcta_or_call') }} <a href="tel:{{ $bcPhoneTel }}">{{ $bcPhone }}</a></div>
    @endif
  </div>
</section>
