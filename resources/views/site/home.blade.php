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
      // 描述优先当前语言组织摘要（站点隔离 + locale-aware），其次站点描述，最后 UI 兜底，
      // 避免单语 site_description 直接泄漏到另一语言 / 另一站点。
      $blankCompanySummary = trim((string) (\App\Support\Catalog::company()['summary'] ?? ''));
      $blankDesc = $blankCompanySummary !== ''
        ? $blankCompanySummary
        : trim((string) ($siteSettings['site_description'] ?? ''));
    @endphp
    <section class="section"><div class="wrap">
      <span class="eyebrow">{{ config('app.name') }}</span>
      <h1>{{ $blankName }}</h1>
      <p>{{ $blankDesc !== '' ? $blankDesc : __('ui.blank_home_lead') }}</p>
      <div class="actions">
        @if(! empty(\App\Support\Catalog::company()))
          <a class="btn btn-primary" href="{{ url('/contact/') }}">{{ __('ui.contact_us') }}<span class="arr">→</span></a>
        @endif
        <a class="btn" href="{{ url('/knowledge/') }}">{{ __('ui.browse_content') }}<span class="arr">→</span></a>
      </div>
    </div></section>
  @endforelse
@endsection
