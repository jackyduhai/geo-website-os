{{-- 通用组合 Block · 联系信息：电话 / 邮箱 / 地址 / 微信二维码，各段可独立开关。
     数据口径与联系页一致：Catalog company + siteSettings，不写死。 --}}
@php
  $c = $block->cfg();
  $ciCompany = $company ?? \App\Support\Catalog::company();
  $ciSettings = $siteSettings ?? [];
  $ciPhone = $ciCompany['phone'] ?? '';
  $ciPhoneTel = $ciCompany['phone_tel'] ?? '';
  $ciEmail = trim((string) ($ciSettings['contact_email'] ?? ''));
  $ciAddress = $ciCompany['address']['full'] ?? '';
  $ciQr = trim((string) ($ciSettings['contact_wechat_qr'] ?? ''));

  $sp = array_key_exists('show_phone', $c) ? ! empty($c['show_phone']) : true;
  $se = array_key_exists('show_email', $c) ? ! empty($c['show_email']) : true;
  $sa = array_key_exists('show_address', $c) ? ! empty($c['show_address']) : true;
  $ss = array_key_exists('show_social', $c) ? ! empty($c['show_social']) : true;

  $ciHasAny = ($sp && $ciPhone) || ($se && $ciEmail) || ($sa && $ciAddress) || ($ss && $ciQr);
@endphp
@if($ciHasAny)
  <section class="sec">
    <div class="wrap">
      @if(! empty($c['title']))<div class="sec-head"><h2 class="sec-h">{{ $c['title'] }}</h2></div>@endif
      <dl class="grid g2" style="gap:22px 40px;margin:0">
        @if($sp && $ciPhone)
          <div><dt style="font-size:13px;color:var(--ink-muted);margin-bottom:4px">{{ __('ui.dt_hotline') }}</dt>
            <dd style="margin:0;font-size:16px;font-weight:600"><a href="tel:{{ $ciPhoneTel }}">{{ $ciPhone }}</a></dd></div>
        @endif
        @if($se && $ciEmail)
          <div><dt style="font-size:13px;color:var(--ink-muted);margin-bottom:4px">{{ __('ui.dt_email') }}</dt>
            <dd style="margin:0;font-size:16px;font-weight:600"><a href="mailto:{{ $ciEmail }}">{{ $ciEmail }}</a></dd></div>
        @endif
        @if($sa && $ciAddress)
          <div><dt style="font-size:13px;color:var(--ink-muted);margin-bottom:4px">{{ __('ui.dt_address') }}</dt>
            <dd style="margin:0;font-size:15px;line-height:1.7">{{ $ciAddress }}</dd></div>
        @endif
        @if($ss && $ciQr)
          <div><dt style="font-size:13px;color:var(--ink-muted);margin-bottom:4px">{{ __('ui.qr_title') }}</dt>
            <dd style="margin:0"><img src="{{ asset($ciQr) }}" alt="{{ __('ui.qr_alt') }}" width="120" height="120" loading="lazy"></dd></div>
        @endif
      </dl>
    </div>
  </section>
@endif
