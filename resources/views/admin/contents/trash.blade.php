@extends('admin.layout')
@section('title','内容回收站')
@section('page-desc','回收站中的内容已撤出前台与机器面（sitemap / llms.txt / GEO），但完整保留数据与关联；可无损恢复，或永久删除（不可恢复）。恢复后将按当前规则重新判定公共资格。')

@section('content')
<div class="filter-bar">
  <a class="tab" href="{{ route('admin.contents.index','all') }}">← 返回内容列表</a>
  <div class="filter-search">
    <form method="get" class="flex gap-2 items-center">
      <input type="text" name="q" value="{{ request('q') }}" placeholder="搜索标题 / slug" class="w-220">
      <button class="btn btn-sm">搜索</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>回收站（{{ $items->total() }}）</h2>
  </div>
  <table class="tbl">
    <thead><tr>
      <th>标题</th><th class="w-120">类型</th><th class="w-150">栏目 / 分组</th>
      <th class="w-120">删除时间</th><th class="w-200">操作</th>
    </tr></thead>
    <tbody>
    @forelse($items as $c)
      <tr>
        <td>
          {{ $c->title }}
          <div class="small muted">/{{ $c->slug }} · {{ $c->locale }}</div>
        </td>
        <td>{{ ['article'=>'文章','page'=>'单页'][$c->type] ?? $c->type }}</td>
        <td class="small">{{ $c->category->name ?? '—' }}@if($c->group) / {{ $c->group->name }}@endif</td>
        <td class="small mono nowrap">{{ optional($c->deleted_at)->format('Y-m-d H:i') }}</td>
        <td class="actions">
          <form class="form-inline" method="post" action="{{ route('admin.contents.restore',$c->id) }}">@csrf
            <button class="btn btn-sm btn-ok">恢复</button></form>
          <form class="form-inline" method="post" action="{{ route('admin.contents.force-delete',$c->id) }}"
                onsubmit="return confirm('永久删除后不可恢复，且将清理该内容的自有数据，确认？')">@csrf @method('DELETE')
            <button class="btn btn-sm btn-danger">永久删除</button></form>
        </td>
      </tr>
    @empty
      <tr><td colspan="5" class="empty-cell">回收站为空</td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="pager">{{ $items->appends(request()->query())->links() }}</div>
</div>
@endsection
