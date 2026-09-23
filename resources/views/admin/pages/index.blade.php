@extends('admin.layout')
@section('title','组合页面')
@section('page-desc','通过模板与区块组合落地页 / 专题页：页面只承载结构，业务内容来自内容、实体与媒体。新建页面后进入「组合内容」，添加并排序区块，再发布。')

@section('page-actions')
  <a class="btn btn-sm btn-primary" href="{{ route('admin.pages.create') }}">＋ 新建页面</a>
@endsection

@section('content')
<div class="card">
  <div class="card-head">
    <h2>全部页面 <span class="hint">共 {{ $groups->count() }} 个翻译组</span></h2>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th class="w-260">页面（各语言）</th>
        <th class="w-140">模板</th>
        <th class="w-200">访问地址</th>
        <th class="w-90">状态</th>
        <th class="actions w-320">操作</th>
      </tr>
    </thead>
    <tbody>
    @forelse($groups as $pages)
      @foreach($pages as $i => $page)
        <tr>
          <td>
            <strong>{{ $page->title ?: '（未命名）' }}</strong>
            <span class="badge info ml-1">{{ $page->locale }}</span>
            @if($i > 0)<span class="hint">↳ 译文</span>@endif
          </td>
          <td class="small">{{ $page->template }}</td>
          <td class="mono small">
            @if($page->slug)/{{ $page->locale === 'en' ? 'en/' : '' }}{{ $page->slug }}@else — @endif
          </td>
          <td>
            @if($page->status === 'published')
              <span class="badge published">已发布</span>
            @else
              <span class="badge archived">草稿</span>
            @endif
          </td>
          <td class="actions">
            <a class="btn btn-sm btn-primary" href="{{ route('admin.pages.composer', $page) }}">组合内容</a>
            <a class="btn btn-sm" href="{{ route('admin.pages.edit', $page) }}">设置</a>
            @if($page->status === 'published')
              <form class="form-inline" method="post"
                    action="{{ route('admin.pages.publish', [$page, 'unpublish']) }}">
                @csrf
                <button class="btn btn-sm">下架</button>
              </form>
            @else
              <form class="form-inline" method="post"
                    action="{{ route('admin.pages.publish', [$page, 'publish']) }}">
                @csrf
                <button class="btn btn-sm">发布</button>
              </form>
            @endif
            <form class="form-inline" method="post" action="{{ route('admin.pages.destroy', $page) }}"
                  onsubmit="return confirm('删除该页面及其全部区块？此操作不可恢复。')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-danger">删</button>
            </form>
          </td>
        </tr>
      @endforeach
    @empty
      <tr><td colspan="5" class="empty-cell">
        还没有组合页面。<a href="{{ route('admin.pages.create') }}">创建第一个页面 →</a>
      </td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
