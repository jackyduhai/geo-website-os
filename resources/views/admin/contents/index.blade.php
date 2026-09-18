@extends('admin.layout')
@section('title','内容管理')
@section('page-desc','文章 / 新闻、单页、产品统一在此发布与维护；发布须通过 GEO 四层门禁，草稿不进前台与 sitemap / llms.txt。')

@section('content')
<div class="filter-bar">
  <a class="tab {{ $tab==='article' ? 'on' : '' }}" href="{{ route('admin.contents.index','article') }}">文章 / 新闻</a>
  <a class="tab {{ $tab==='page' ? 'on' : '' }}" href="{{ route('admin.contents.index','page') }}">单页</a>
  <a class="tab {{ $tab==='product' ? 'on' : '' }}" href="{{ route('admin.contents.index','product') }}">产品</a>
  <a class="tab {{ $tab==='all' ? 'on' : '' }}" href="{{ route('admin.contents.index','all') }}">全部</a>
  <div class="filter-search">
    <form method="get" class="flex gap-2 items-center">
      <input type="text" name="q" value="{{ request('q') }}" placeholder="搜索标题 / 摘要" class="w-220">
      <button class="btn btn-sm">搜索</button>
    </form>
    <div class="dropdown">
      <button type="button" class="btn btn-sm btn-primary">＋ 新建内容 ▾</button>
      <div class="dropdown-menu">
        <a href="{{ route('admin.contents.create','article') }}">文章 / 新闻</a>
        <a href="{{ route('admin.contents.create','page') }}">单页</a>
        <a href="{{ route('admin.contents.create','product') }}">产品</a>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>内容列表（{{ $items->total() }}）</h2>
  </div>
  <table class="tbl">
    <thead><tr>
      <th>标题</th><th class="w-120">类型</th><th class="w-150">栏目 / 分组</th><th class="w-90">状态</th>
      <th class="w-120">发布日期</th><th class="w-120">复核到期</th><th class="actions w-230">操作</th>
    </tr></thead>
    <tbody>
    @forelse($items as $c)
      <tr>
        <td>
          {{ $c->title }}
          @if($c->lock_manual)<span class="badge info ml-1" title="该页含人工锁定内容，AI 同步不得覆盖">🔒 人工锁定</span>@endif
          <div class="small muted">/{{ $c->slug }} @if($c->external_id)<span class="mono">· ext:{{ $c->external_id }}</span>@endif</div>
        </td>
        <td>{{ ['article'=>'文章','page'=>'单页','product'=>'产品'][$c->type] ?? $c->type }}</td>
        <td class="small">{{ $c->category->name ?? '—' }}@if($c->group) / {{ $c->group->name }}@endif</td>
        <td>
          @if($c->status==='published')<span class="badge published">已发布</span>
          @elseif($c->status==='draft')<span class="badge draft">草稿</span>
          @else<span class="badge archived">归档</span>@endif
        </td>
        <td class="small mono nowrap">{{ optional($c->published_at)->format('Y-m-d') ?: '—' }}</td>
        <td class="small mono nowrap">
          @if($c->review_due)
            <span class="@if($c->review_due->isPast()) text-err @endif">{{ $c->review_due->format('Y-m-d') }}</span>
          @else — @endif
        </td>
        <td class="actions">
          <a class="btn btn-sm" href="{{ route('admin.contents.edit',$c) }}">编辑</a>
          @if($c->status==='published')
            <form class="form-inline" method="post" action="{{ route('admin.contents.unpublish',$c) }}">@csrf
              <button class="btn btn-sm">下架</button></form>
          @else
            <form class="form-inline" method="post" action="{{ route('admin.contents.publish',$c) }}">@csrf
              <button class="btn btn-sm btn-ok">发布</button></form>
          @endif
          <form class="form-inline" method="post" action="{{ route('admin.contents.destroy',$c) }}"
                onsubmit="return confirm('删除后进入归档（软删除），确认？')">@csrf @method('DELETE')
            <button class="btn btn-sm btn-danger">删</button></form>
        </td>
      </tr>
    @empty
      <tr><td colspan="7" class="empty-cell">暂无内容</td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="pager">{{ $items->appends(request()->query())->links() }}</div>
</div>
@endsection
