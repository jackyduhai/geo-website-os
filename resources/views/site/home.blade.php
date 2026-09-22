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
    @php
      $blankName = $siteSettings['site_name'] ?? config('app.name');
      $blankDesc = trim((string) ($siteSettings['site_description'] ?? ''));
    @endphp
    <section class="section"><div class="wrap">
      <span class="eyebrow">{{ config('app.name') }}</span>
      <h1>{{ $blankName }}</h1>
      <p>{{ $blankDesc !== '' ? $blankDesc : '站点已就绪。在后台创建内容与实体、完成站点设置并发布后，首页将展示你的信息。' }}</p>
      <div class="actions">
        @if(! empty(\App\Support\Catalog::company()))
          <a class="btn btn-primary" href="{{ url('/contact/') }}">联系我们<span class="arr">→</span></a>
        @endif
        <a class="btn" href="{{ url('/knowledge/') }}">浏览内容<span class="arr">→</span></a>
      </div>
    </div></section>
  @endforelse
@endsection
