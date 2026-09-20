@extends('layouts.site')

@php
  // 布局 <title> / robots 读取 $seo（不读 @section('title')），错误页需显式声明
  $seo = [
      'title_full'  => '系统维护中｜GEO Website OS',
      'description' => '网站正在例行维护，请稍后再访问。',
      'noindex'     => true,
  ];
@endphp

@php
  $navTree = $navTree ?? \App\Providers\AppServiceProvider::navTree();
  $siteSettings = $siteSettings ?? \App\Models\Setting::allCached();
@endphp

@section('content')
<div class="wrap" style="padding:70px 20px;text-align:center;max-width:640px">
  <p style="font-size:56px;font-weight:800;color:var(--line);margin:0 0 8px;line-height:1">503</p>
  <h1 style="font-size:22px;margin:0 0 12px">系统维护中</h1>
  <p style="color:var(--ink-muted);margin:0 0 26px">网站正在例行维护，请稍后再访问。业务咨询可直接拨打我们的电话。</p>
  <p style="margin-bottom:30px">
    @if(!empty($siteSettings['contact_phone']))
      <a class="btn" href="tel:{{ preg_replace('/[^0-9]/', '', $siteSettings['contact_phone']) }}">{{ $siteSettings['contact_phone'] }}</a>
    @else
      <a class="btn" href="{{ url('/') }}">返回首页</a>
    @endif
  </p>
</div>
@endsection
