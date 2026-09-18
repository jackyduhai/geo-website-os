@extends('admin.layout')
@section('title','内容结构')
@section('page-desc','知识分组是知识中心的子分类，启用后自动进入顶部导航「知识中心」下拉与 llms.txt，停用即从前台移除。')

@section('content')
<div class="filter-bar">
  <a class="tab" href="{{ route('admin.categories.index') }}">栏目分类</a>
  <a class="tab on" href="{{ route('admin.groups.index') }}">知识分组</a>
</div>

<div class="grid g2">
  <div class="card">
    <h2>新建分组</h2>
    <form method="post" action="{{ route('admin.groups.store') }}">
      @csrf
      <div class="form-row">
        <label>所属栏目 <span class="req">*</span></label>
        <select name="category_id" required>
          @foreach($categories as $c)
            <option value="{{ $c->id }}" @selected($c->slug === 'knowledge')>{{ $c->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="form-grid">
        <div class="form-row"><label>分组名 <span class="req">*</span></label>
          <input type="text" name="name" required></div>
        <div class="form-row"><label>slug <span class="req">*</span></label>
          <input type="text" name="slug" required placeholder="lowercase-dash"></div>
      </div>
      <div class="form-row"><label><span class="label-with-tip">简介 <x-admin-tip text="可选；会用于知识子栏目页与 llms.txt 等 AI 摘要。"/></span></label><input type="text" name="description" placeholder="一句话说明该分组内容"></div>
      <div class="form-grid">
        <div class="form-row"><label>排序</label><input type="number" name="sort" value="0"></div>
        <div class="form-row"><label>启用</label>
          <select name="is_active"><option value="1">启用</option><option value="0">停用</option></select></div>
      </div>
      <button class="btn btn-primary">创建</button>
    </form>
  </div>

  <div class="card">
    <h2>现有分组</h2>
    <table class="tbl">
      <thead><tr><th>分组</th><th>栏目</th><th>状态</th><th class="actions">操作</th></tr></thead>
      <tbody>
      @forelse($groups as $g)
        <tr>
          <td>
            <details>
              <summary><strong>{{ $g->name }}</strong> <span class="small muted mono">/{{ $g->slug }}</span></summary>
              <form method="post" action="{{ route('admin.groups.update',$g) }}" class="mt-2">
                @csrf @method('PUT')
                <div class="form-row"><label>名称</label><input type="text" name="name" value="{{ $g->name }}"></div>
                <div class="form-row"><label>slug</label><input type="text" name="slug" value="{{ $g->slug }}"></div>
                <div class="form-row"><label>简介</label><input type="text" name="description" value="{{ $g->description }}"></div>
                <input type="hidden" name="category_id" value="{{ $g->category_id }}">
                <button class="btn btn-sm btn-primary">保存修改</button>
              </form>
            </details>
          </td>
          <td class="small">{{ optional($g->category)->name }}</td>
          <td>@if($g->is_active)<span class="badge ok">启用</span>@else<span class="badge draft">停用</span>@endif</td>
          <td class="actions">
            <form class="form-inline" method="post" action="{{ route('admin.groups.destroy',$g) }}"
                  onsubmit="return confirm('删除分组 {{ $g->name }}？')">@csrf @method('DELETE')
              <button class="btn btn-sm btn-danger">删</button></form>
          </td>
        </tr>
      @empty
        <tr><td colspan="4" class="empty-cell">暂无分组</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
