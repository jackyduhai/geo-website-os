{{-- 通用组合 Block · 联系信息：完整联系事实（座机 / 手机 / 邮箱 / 公司全称 / 地址·地图 /
     营业时间 / 成立时间 / 目标客户 / 销售区域 / 微信二维码），各段按大类开关。
     数据口径与联系页一致：Catalog company + siteSettings，不写死。 --}}
@php
  $c = $block->cfg();
  $ciCompany = $company ?? \App\Support\Catalog::company();
  $ciSettings = $siteSettings ?? [];
  $ciPhone = $ciCompany['phone'] ?? '';
  $ciPhoneTel = $ciCompany['phone_tel'] ?? '';
  $ciMobile = trim((string) ($ciSettings['contact_mobile'] ?? ''));
  $ciEmail = trim((string) ($ciSettings['contact_email'] ?? ''));
  $isEnContact = \App\Support\Localization\LocaleContext::current() !== \App\Support\Localization\LocaleRegistry::default();
  $hoursEnRaw = trim((string) ($ciSettings['contact_hours_en'] ?? ''));
  $ciHours = trim((string) ($isEnContact && $hoursEnRaw !== '' ? $hoursEnRaw : ($ciSettings['contact_hours'] ?? '')));
  $ciMap = trim((string) ($ciSettings['contact_map_url'] ?? ''));
  $ciQr = trim((string) ($ciSettings['contact_wechat_qr'] ?? ''));
  $ciTarget = $ciCompany['target_customers'] ?? [];
  $ciRegions = \App\Support\Catalog::salesRegions();
  $ciListSep = $isEnContact ? ', ' : '、';

  $sp = array_key_exists('show_phone', $c) ? ! empty($c['show_phone']) : true;
  $se = array_key_exists('show_email', $c) ? ! empty($c['show_email']) : true;
  $sa = array_key_exists('show_address', $c) ? ! empty($c['show_address']) : true;
  $ss = array_key_exists('show_social', $c) ? ! empty($c['show_social']) : true;

  $ciHasAddress = ! empty($ciCompany['address']['full']) || $ciHours
      || ! empty($ciCompany['founded_display']);
  $ciHasFacts = ($sp && ($ciPhone || $ciMobile)) || ($se && $ciEmail)
      || ($sa && $ciHasAddress) || ($ss && $ciQr)
      || ! empty($ciCompany['name']) || ! empty($ciTarget) || ! empty($ciRegions);
@endphp
@if($ciHasFacts)
  <section class="sec">
    <div class="wrap">
      @if(! empty($c['title']))<div class="sec-head"><h2 class="sec-h">{{ $c['title'] }}</h2></div>@endif
      <dl class="contact-facts" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:var(--sp-6) var(--sp-10);margin:0">
        @if($sp && $ciPhone)
          <div><dt>{{ __('ui.dt_hotline') }}</dt><dd><a href="tel:{{ $ciPhoneTel }}">{{ $ciPhone }}</a></dd></div>
        @endif
        @if($sp && $ciMobile)
          <div><dt>{{ __('ui.dt_mobile') }}</dt><dd><a href="tel:{{ preg_replace('/[^0-9]/', '', $ciMobile) }}">{{ $ciMobile }}</a></dd></div>
        @endif
        @if($se && $ciEmail)
          <div><dt>{{ __('ui.dt_email') }}</dt><dd><a href="mailto:{{ $ciEmail }}">{{ $ciEmail }}</a></dd></div>
        @endif
        @if(! empty($ciCompany['name']))
          <div><dt>{{ __('ui.dt_company_full') }}</dt><dd>{{ $ciCompany['name'] }}</dd></div>
        @endif
        @if($sa && ! empty($ciCompany['address']['full']))
          <div>
            <dt>{{ __('ui.dt_address') }}</dt>
            <dd>{{ $ciCompany['address']['full'] }}
              @if($ciMap)<a class="map-link" href="{{ $ciMap }}" target="_blank" rel="noopener">{{ __('ui.view_map') }} <span aria-hidden="true">→</span></a>@endif
            </dd>
          </div>
        @endif
        @if($sa && $ciHours)
          <div><dt>{{ __('ui.dt_hours') }}</dt><dd>{{ $ciHours }}</dd></div>
        @endif
        @if(! empty($ciCompany['founded_display']))
          <div><dt>{{ __('ui.dt_founded') }}</dt><dd>{{ $ciCompany['founded_display'] }}</dd></div>
        @endif
        @if(! empty($ciTarget))
          <div><dt>{{ __('ui.dt_target') }}</dt><dd>{{ implode(' · ', $ciTarget) }}</dd></div>
        @endif
        @if(! empty($ciRegions))
          <div><dt>{{ __('ui.dt_sales') }}</dt><dd>{{ implode($ciListSep, $ciRegions) }}</dd></div>
        @endif
      </dl>
      @if($ss && $ciQr)
        <div class="contact-wechat" style="margin-top:var(--sp-7)">
          <img src="{{ asset($ciQr) }}" alt="{{ __('ui.qr_alt') }}" width="132" height="132" loading="lazy">
          <div><strong>{{ __('ui.qr_title') }}</strong><span>{{ __('ui.qr_desc') }}</span></div>
        </div>
      @endif
    </div>
  </section>
@endif
