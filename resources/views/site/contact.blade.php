@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">{{ __('ui.eyebrow_contact') }}</span>
    <h1 class="ph-h">{{ __('ui.contact_h1') }}</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

@php
  $mobile = trim((string)($siteSettings['contact_mobile'] ?? ''));
  $email  = trim((string)($siteSettings['contact_email'] ?? ''));
  $isEnContact = \App\Support\Localization\LocaleContext::current() !== \App\Support\Localization\LocaleRegistry::default();
  $hoursEnRaw = trim((string)($siteSettings['contact_hours_en'] ?? ''));
  $hours  = trim((string)($isEnContact && $hoursEnRaw !== '' ? $hoursEnRaw : ($siteSettings['contact_hours'] ?? '')));
  $mapUrl = trim((string)($siteSettings['contact_map_url'] ?? ''));
  $qrSrc  = trim((string)($siteSettings['contact_wechat_qr'] ?? ''));
  $targetCustomers = $company['target_customers'] ?? [];
  $salesRegions = \App\Support\Catalog::salesRegions();
@endphp
<section class="sec">
  <div class="wrap contact-grid">
    <div class="contact-info">
      <dl class="contact-facts">
        @if(!empty($company['phone']))
        <div><dt>{{ __('ui.dt_hotline') }}</dt><dd><a href="tel:{{ $company['phone_tel'] }}">{{ $company['phone'] }}</a></dd></div>
        @endif
        @if($mobile)
          <div><dt>{{ __('ui.dt_mobile') }}</dt><dd><a href="tel:{{ preg_replace('/[^0-9]/', '', $mobile) }}">{{ $mobile }}</a></dd></div>
        @endif
        @if($email)
          <div><dt>{{ __('ui.dt_email') }}</dt><dd><a href="mailto:{{ $email }}">{{ $email }}</a></dd></div>
        @endif
        <div><dt>{{ __('ui.dt_company_full') }}</dt><dd>{{ $company['name'] }}</dd></div>
        @if(!empty($company['address']['full']))
        <div>
          <dt>{{ __('ui.dt_address') }}</dt>
          <dd>{{ $company['address']['full'] }}</dd>
          @if($mapUrl)
            <a class="map-link" href="{{ $mapUrl }}" target="_blank" rel="noopener">{{ __('ui.view_map') }} <span aria-hidden="true">→</span></a>
          @endif
        </div>
        @endif
        @if($hours)
          <div><dt>{{ __('ui.dt_hours') }}</dt><dd>{{ $hours }}</dd></div>
        @endif
        <div><dt>{{ __('ui.dt_founded') }}</dt><dd>{{ $company['founded_display'] }}</dd></div>
        @if(!empty($targetCustomers))
        <div><dt>{{ __('ui.dt_target') }}</dt><dd>{{ implode(' · ', $targetCustomers) }}</dd></div>
        @endif
        @if(!empty($salesRegions))
        <div><dt>{{ __('ui.dt_sales') }}</dt><dd>{{ implode(\App\Support\Localization\LocaleContext::current() === \App\Support\Localization\LocaleRegistry::default() ? '、' : ', ', $salesRegions) }}</dd></div>
        @endif
      </dl>
      @if($qrSrc)
      <div class="contact-wechat">
        <img src="{{ asset($qrSrc) }}" alt="{{ __('ui.qr_alt') }}" width="132" height="132" loading="lazy">
        <div><strong>{{ __('ui.qr_title') }}</strong><span>{{ __('ui.qr_desc') }}</span></div>
      </div>
      @endif
    </div>
    <div class="contact-form-col">
      @include('site._lead_form', ['leadFormId' => 'contact-lead-form', 'leadClass' => 'standalone'])
    </div>
  </div>
</section>
@endsection
