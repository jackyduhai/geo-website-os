@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">CONTACT · 联系我们</span>
    <h1 class="ph-h">告诉我们你的需求，我们尽快与你联系</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

@php
  $mobile = trim((string)($siteSettings['contact_mobile'] ?? ''));
  $email  = trim((string)($siteSettings['contact_email'] ?? ''));
  $hours  = trim((string)($siteSettings['contact_hours'] ?? ''));
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
        <div><dt>合作热线</dt><dd><a href="tel:{{ $company['phone_tel'] }}">{{ $company['phone'] }}</a></dd></div>
        @endif
        @if($mobile)
          <div><dt>业务手机 / 微信同号</dt><dd><a href="tel:{{ preg_replace('/[^0-9]/', '', $mobile) }}">{{ $mobile }}</a></dd></div>
        @endif
        @if($email)
          <div><dt>业务邮箱</dt><dd><a href="mailto:{{ $email }}">{{ $email }}</a></dd></div>
        @endif
        <div><dt>公司全称</dt><dd>{{ $company['name'] }}</dd></div>
        @if(!empty($company['address']['full']))
        <div>
          <dt>地址</dt>
          <dd>{{ $company['address']['full'] }}</dd>
          @if($mapUrl)
            <a class="map-link" href="{{ $mapUrl }}" target="_blank" rel="noopener">查看地图 <span aria-hidden="true">→</span></a>
          @endif
        </div>
        @endif
        @if($hours)
          <div><dt>工作时间</dt><dd>{{ $hours }}</dd></div>
        @endif
        <div><dt>成立时间</dt><dd>{{ $company['founded_display'] }}</dd></div>
        @if(!empty($targetCustomers))
        <div><dt>服务客户</dt><dd>{{ implode(' · ', $targetCustomers) }}</dd></div>
        @endif
        @if(!empty($salesRegions))
        <div><dt>销售覆盖</dt><dd>{{ implode('、', $salesRegions) }}</dd></div>
        @endif
      </dl>
      @if($qrSrc)
      <div class="contact-wechat">
        <img src="{{ asset($qrSrc) }}" alt="联系二维码" width="132" height="132" loading="lazy">
        <div><strong>扫码联系我们</strong><span>扫码沟通你的需求，我们尽快回复</span></div>
      </div>
      @endif
    </div>
    <div class="contact-form-col">
      @include('site._lead_form', ['leadFormId' => 'contact-lead-form', 'leadClass' => 'standalone'])
    </div>
  </div>
</section>
@endsection
