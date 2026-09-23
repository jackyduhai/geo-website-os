@extends('layouts.site')

@php
  // 布局 <title> / robots 读取 $seo（不读 @section('title')），错误页需显式声明
  $seo = [
      'title_full'  => __('ui.err_403_title').'｜GEO Website OS',
      'description' => __('ui.err_403_desc'),
      'noindex'     => true,
  ];
@endphp

@php
  $navTree = $navTree ?? \App\Providers\AppServiceProvider::navTree();
  $siteSettings = $siteSettings ?? \App\Models\Setting::allCached();
@endphp

@section('content')
<div class="wrap" style="padding:70px 20px;text-align:center;max-width:640px">
  <p style="font-size:56px;font-weight:800;color:var(--line);margin:0 0 8px;line-height:1">403</p>
  <h1 style="font-size:22px;margin:0 0 12px">{{ __('ui.err_403_title') }}</h1>
  <p style="color:var(--ink-muted);margin:0 0 26px">{{ __('ui.err_403_body') }}</p>
  <p style="margin-bottom:30px">
    @if(!empty($siteSettings['contact_phone']))
      <a class="btn" href="tel:{{ preg_replace('/[^0-9]/', '', $siteSettings['contact_phone']) }}">{{ __('ui.call_phone', ['phone' => $siteSettings['contact_phone']]) }}</a>
    @endif
    <a class="btn" href="{{ \App\Support\PublicUrl::home() }}">{{ __('ui.back_home') }}</a>
  </p>
</div>
@endsection
