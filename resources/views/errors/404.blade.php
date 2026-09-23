@extends('layouts.site')

@php
  // 布局 <title> / robots 读取 $seo（不读 @section('title')），错误页需显式声明
  $seo = [
      'title_full'  => __('ui.404_title').'｜GEO Website OS',
      'description' => __('ui.404_desc'),
      'noindex'     => true,
  ];
@endphp

@php
  $siteSettings = $siteSettings ?? \App\Models\Setting::allCached();
  $e404 = \App\Support\Copy::error404();
  // 快捷入口与主导航同源：后台对一级栏目的改名 / 隐藏 / 自定义一级在这里同步生效，
  // 不写死栏目名；外链与纯父级（#）占位不作为站内恢复入口。
  $entries = [];
  foreach (\App\Providers\AppServiceProvider::mainMenu() as $m) {
      $href = (string) ($m['url'] ?? '');
      if (! empty($m['external']) || $href === '' || $href === '#' || str_starts_with($href, 'tel:') || str_starts_with($href, 'mailto:')) {
          continue;
      }
      $entries[] = [$m['name'], $href];
  }
  if (! collect($entries)->contains(fn ($e) => str_contains($e[1], '/contact'))) {
      $entries[] = [__('nav.contact'), \App\Support\PublicUrl::url('contact/')];
  }
@endphp

@section('content')
<section class="sec">
  <div class="wrap-narrow center-txt" style="padding:64px 0">
    <p style="font-size:72px;font-weight:800;color:var(--brand);line-height:1;margin:0 0 16px">{{ $e404['code'] }}</p>
    <h1 style="font-size:28px;margin:0 0 12px">{{ $e404['title'] }}</h1>
    <p class="prose" style="margin:0 auto 28px;max-width:520px">{{ $e404['desc'] }}</p>
    <div class="actions" style="justify-content:center;margin-bottom:40px">
      <a class="btn btn-primary btn-lg" href="{{ \App\Support\PublicUrl::home() }}">{{ $e404['primaryCta'] }}<span class="arr">→</span></a>
      <a class="btn btn-secondary btn-lg" href="{{ \App\Support\PublicUrl::url('contact/') }}">{{ $e404['secondaryCta'] }}</a>
    </div>
    <div class="kgrid kgrid-3 err-entries" style="text-align:left">
      @foreach($entries as [$label, $href])
        <a class="kcard" href="{{ $href }}"><h3 style="margin:0">{{ $label }}</h3><span class="km"><span>{{ __('ui.enter') }}<span class="arr">→</span></span></span></a>
      @endforeach
    </div>
  </div>
</section>
@endsection
