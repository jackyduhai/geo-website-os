@extends('admin.layout')
@section('title','组合内容 · '.($page->title ?: $page->slug))
@section('page-desc','在模板槽位内添加、排序、显隐区块。区块内容为结构化数据，由系统渲染；设置完成后发布页面。')

@section('page-actions')
  <a class="btn btn-sm" href="{{ route('admin.pages.index') }}">← 返回列表</a>
  <a class="btn btn-sm" target="_blank"
     href="{{ route('admin.pages.preview', $page) }}">预览 ↗</a>
  @if($page->status === 'published')
    <a class="btn btn-sm" target="_blank"
       href="{{ \App\Support\PublicUrl::url('/'.ltrim((string) $page->slug, '/')) }}">查看前台 ↗</a>
  @endif
@endsection

@section('content')
<div class="card">
  <div class="card-head">
    <h2>{{ $page->title ?: '（未命名）' }}
      <span class="badge info ml-1">{{ $page->locale }}</span>
      <span class="badge ml-1 {{ $page->status === 'published' ? 'published' : 'archived' }}">
        {{ $page->status === 'published' ? '已发布' : '草稿' }}
      </span>
    </h2>
  </div>
  <div class="actions">
    <a class="btn btn-sm" href="{{ route('admin.pages.edit', $page) }}">页面设置</a>
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
        <button class="btn btn-sm btn-primary">发布页面</button>
      </form>
    @endif
  </div>
</div>

@foreach($template->slotNames() as $slotName)
  @php
    $slotLabel = $template->slot($slotName)['label'] ?? $slotName;
    $slotBlocks = $page->blocks->where('slot', $slotName)->sortBy('sort');
  @endphp
  <div class="card mt-2">
    <div class="card-head">
      <h2>{{ $slotLabel }}
        <span class="hint">槽位 {{ $slotName }} · {{ $slotBlocks->count() }} 个区块</span>
      </h2>
    </div>
    <table class="tbl">
      <thead>
        <tr>
          <th class="w-60">排序</th>
          <th>区块</th>
          <th class="w-90">状态</th>
          <th class="actions w-340">操作</th>
        </tr>
      </thead>
      <tbody>
      @forelse($slotBlocks as $block)
        <tr>
          <td class="mono small">{{ $block->sort }}</td>
          <td>
            <strong>{{ $blockTypes[$block->type]->label ?? $block->type }}</strong>
            <span class="hint mono">{{ $block->type }}</span>
          </td>
          <td>
            @if($block->is_active)
              <span class="badge published">显示</span>
            @else
              <span class="badge archived">已隐藏</span>
            @endif
          </td>
          <td class="actions">
            <a class="btn btn-sm btn-primary"
               href="{{ route('admin.pages.editBlock', [$page, $block]) }}">编辑内容</a>
            <form class="form-inline" method="post"
                  action="{{ route('admin.pages.moveBlock', [$page, $block, 'up']) }}">
              @csrf
              <button class="btn btn-sm">↑</button>
            </form>
            <form class="form-inline" method="post"
                  action="{{ route('admin.pages.moveBlock', [$page, $block, 'down']) }}">
              @csrf
              <button class="btn btn-sm">↓</button>
            </form>
            <form class="form-inline" method="post"
                  action="{{ route('admin.pages.toggleBlock', [$page, $block]) }}">
              @csrf
              <button class="btn btn-sm">{{ $block->is_active ? '隐藏' : '显示' }}</button>
            </form>
            <form class="form-inline" method="post"
                  action="{{ route('admin.pages.duplicateBlock', [$page, $block]) }}">
              @csrf
              <button class="btn btn-sm">复制</button>
            </form>
            <form class="form-inline" method="post"
                  action="{{ route('admin.pages.destroyBlock', [$page, $block]) }}"
                  onsubmit="return confirm('删除该区块？区块引用的业务内容不会被删除。')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-danger">删</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="4" class="empty-cell">该槽位还没有区块。</td></tr>
      @endforelse
      </tbody>
    </table>
    <div class="form-actions">
      <a class="btn btn-sm"
         href="{{ route('admin.pages.addBlock', ['page' => $page, 'slot' => $slotName]) }}">
        ＋ 添加区块到「{{ $slotLabel }}」
      </a>
    </div>
  </div>
@endforeach
@endsection
