@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">CONTACT · 联系我们</span>
    <h1 class="ph-h">说清你的需求，我们安排寄样与方案</h1>
    <p class="ph-lead">{{ $lead }}</p>
  </div>
</section>

@php
  $mobile = trim((string)($siteSettings['contact_mobile'] ?? ''));
  $email  = trim((string)($siteSettings['contact_email'] ?? ''));
  $hours  = trim((string)($siteSettings['contact_hours'] ?? ''));
  $mapUrl = trim((string)($siteSettings['contact_map_url'] ?? ''));
  $qrSrc  = trim((string)($siteSettings['contact_wechat_qr'] ?? '')) ?: 'img/wechat-qr.png';
@endphp
<section class="sec">
  <div class="wrap contact-grid">
    <div class="contact-info">
      <dl class="contact-facts">
        <div><dt>全国合作热线</dt><dd><a href="tel:{{ $company['phone_tel'] }}">{{ $company['phone'] }}</a></dd></div>
        @if($mobile)
          <div><dt>业务手机 / 微信同号</dt><dd><a href="tel:{{ preg_replace('/[^0-9]/', '', $mobile) }}">{{ $mobile }}</a></dd></div>
        @endif
        @if($email)
          <div><dt>业务邮箱</dt><dd><a href="mailto:{{ $email }}">{{ $email }}</a></dd></div>
        @endif
        <div><dt>公司全称</dt><dd>{{ $company['name'] }}</dd></div>
        <div>
          <dt>厂区地址</dt>
          <dd>{{ $company['address']['full'] }}</dd>
          @if($mapUrl)
            <a class="map-link" href="{{ $mapUrl }}" target="_blank" rel="noopener">查看地图 <span aria-hidden="true">→</span></a>
          @endif
        </div>
        @if($hours)
          <div><dt>工作时间</dt><dd>{{ $hours }}</dd></div>
        @endif
        <div><dt>成立时间</dt><dd>{{ $company['founded_display'] }}</dd></div>
        <div><dt>服务客户</dt><dd>Sample Snack门店 · 连锁品牌 · 渠道经销商</dd></div>
        <div><dt>销售覆盖</dt><dd>东北、华北、华东、华中、西北、西南、华南七大区域</dd></div>
      </dl>
      <div class="contact-wechat">
        <img src="{{ asset($qrSrc) }}" alt="Example微信二维码" width="132" height="132" loading="lazy">
        <div><strong>微信扫码 · 索要样品</strong><span>加微信沟通需求，安排寄样与定制方案</span></div>
      </div>
    </div>
    <div class="contact-form-col">
      @include('site._lead_form', ['leadFormId' => 'contact-lead-form', 'leadClass' => 'standalone'])
    </div>
  </div>
</section>
@endsection
