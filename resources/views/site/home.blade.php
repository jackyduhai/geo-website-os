{{--
  首页：结构完全由后台「首页装修」驱动。
  按 page_blocks.sort 顺序渲染启用区块，关闭即不显示、顺序可拖拽（排序值）、内容可配置。
  每个区块一个局部：site/home/<type>.blade.php
--}}
@extends('layouts.site')

@section('content')
  @forelse($blocks as $blk)
    @includeIf('site.home.' . $blk->type, ['blk' => $blk])
  @empty
    <section class="section"><div class="wrap">
      <span class="eyebrow">源头工厂 · 定制制造</span>
      <h1>{{ $siteSettings['site_name'] ?? config('app.name') }}</h1>
      <p>{{ $siteSettings['site_description'] ?? '' }}</p>
      <div class="hero-actions">
        <a class="btn" href="{{ url('/contact/contact-us') }}">业务与打样咨询<span class="arr">→</span></a>
      </div>
    </div></section>
  @endforelse
@endsection
