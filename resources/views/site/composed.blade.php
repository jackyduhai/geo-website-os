@extends('layouts.site')

@section('title', $seo['title'])
@section('meta_description', $seo['description'] ?? '')

@section('content')
{{-- Product 详情顶部产品体系 SubNav（Service / Page 无） --}}
@if(! empty($subnav))
  @include('site._subnav', ['subnav' => $subnav])
@endif

{{-- 无 hero 时提供视觉隐藏 H1（SEO / 屏幕阅读器）；有 hero 时 H1 由 hero 承担 --}}
@unless($hasHero)
  <h1 style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
     clip:rect(0,0,0,0);white-space:nowrap;border:0">{{ $seo['title'] }}</h1>
@endunless

@foreach($template->slotNames() as $slotName)
  @php $slotHtml = $slotsHtml[$slotName] ?? ''; @endphp
  {{-- home 模板 main 槽无区块：渲染中性欢迎屏（出厂 blank 首页兜底，添加区块后自动替换） --}}
  @if($slotHtml === '' && $template->key === 'home' && $slotName === 'main')
    @include('site.home._welcome')
  @else
    {!! $slotHtml !!}
  @endif
@endforeach
@endsection
