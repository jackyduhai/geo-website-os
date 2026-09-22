@extends('layouts.site')
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
@include('site._subnav', ['subnav' => $subnav])

<section class="page-hero">
  <div class="wrap-narrow">
    <span class="eyebrow">KNOWLEDGE · 知识中心</span>
    <h1 class="ph-h">{{ $active ? ($channels[$active] ?? '知识中心') : '产品知识、选型指南与常见问题' }}</h1>
    <p class="ph-lead">沉淀产品知识、选型方法与常见问题，帮助你快速了解我们的产品与服务。</p>
  </div>
</section>

<section class="sec">
  <div class="wrap">
    @if($items->count())
      <div class="kgrid kgrid-3">
        @foreach($items as $c)
          <a class="kcard reveal @if($c->cover) has-cover @endif" href="{{ $c->url() }}">
            @if($c->cover)<span class="kc-cover"><x-picture :src="$c->cover->url()" :alt="$c->title" loading="lazy" decoding="async" /></span>@endif
            <span class="kt">{{ optional($c->group)->name ?? ($c->category->name ?? '行业知识') }}</span>
            <h3>{{ $c->title }}</h3>
            <p>{{ $c->summary }}</p>
            <div class="km"><span>阅读全文<span class="arr">→</span></span><time>{{ optional($c->published_at)->format('Y-m-d') }}</time></div>
          </a>
        @endforeach
      </div>
      <div class="pager">{{ $items->links() }}</div>
    @else
      <p class="empty-note">该栏目内容正在整理中，可先联系我们获取资料。</p>
    @endif
  </div>
</section>

@include('site._bottom_cta')
@endsection
