@extends('layouts.site')

@section('title', $seo['title'])
@section('meta_description', $seo['description'] ?? '')

@section('content')
{{-- 无 hero block 时提供视觉隐藏 H1（SEO / 屏幕阅读器），有 hero 时 H1 由 hero 承担 --}}
@unless($hasHero)
  <h1 style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
     clip:rect(0,0,0,0);white-space:nowrap;border:0">{{ $page->title }}</h1>
@endunless

@foreach($template->slotNames() as $slotName)
  {!! $slotsHtml[$slotName] ?? '' !!}
@endforeach
@endsection
