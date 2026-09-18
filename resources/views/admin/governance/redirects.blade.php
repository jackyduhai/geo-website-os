@extends('admin.layout')
@section('title','301 跳转')
@section('page-desc','旧链接改版或页面迁移时配置 301/302，保住搜索引擎权重与旧入口流量；命中次数自动统计。')

@section('content')
<div class="grid g2">
  <div class="card">
    <h2>新增跳转规则</h2>
    <form method="post" action="{{ route('admin.redirects.store') }}">
      @csrf
      <div class="form-row"><label><span class="label-with-tip">旧路径 from <span class="req">*</span><x-admin-tip text="需要被跳转的旧地址，站内路径以 / 开头，也支持旧版带查询串的地址（如 ?cb-13-1.html）。"/></span></label>
        <input type="text" name="from_path" placeholder="?cb-13-1.html 或 /old/page" required></div>
      <div class="form-row"><label><span class="label-with-tip">新路径 to <span class="req">*</span><x-admin-tip text="跳转目标，站内路径以 / 开头（如 /about）；外链以 https:// 开头。"/></span></label>
        <input type="text" name="to_path" placeholder="/about" required></div>
      <div class="form-grid">
        <div class="form-row"><label><span class="label-with-tip">状态码 <x-admin-tip text="301 永久：传递搜索权重，用于页面迁移；302 临时：短期活动 / 维护。"/></span></label>
          <select name="code"><option value="301">301 永久</option><option value="302">302 临时</option></select></div>
        <div class="form-row"><label class="checkline mt-4"><input type="checkbox" name="is_active" value="1" checked> 启用</label></div>
      </div>
      <button class="btn btn-primary">添加</button>
    </form>
  </div>
  <div class="card">
    <h2>现有规则（命中次数降序）</h2>
    <table class="tbl">
      <thead><tr><th>from</th><th>to</th><th>码</th><th>命中</th><th class="actions">操作</th></tr></thead>
      <tbody>
      @forelse($redirects as $r)
        <tr>
          <td class="small mono">{{ $r->from_path }}</td>
          <td class="small mono">{{ $r->to_path }}</td>
          <td>{{ $r->code }}</td>
          <td>{{ $r->hits }}</td>
          <td class="actions">
            <details>
              <summary class="btn btn-sm">编辑</summary>
              <form method="post" action="{{ route('admin.redirects.update',$r) }}" class="mt-2">
                @csrf @method('PUT')
                <div class="form-row"><input type="text" name="from_path" value="{{ $r->from_path }}"></div>
                <div class="form-row"><input type="text" name="to_path" value="{{ $r->to_path }}"></div>
                <div class="form-row"><select name="code"><option value="301" @selected($r->code==301)>301</option><option value="302" @selected($r->code==302)>302</option></select></div>
                <label class="checkline mb-3"><input type="checkbox" name="is_active" value="1" @checked($r->is_active)> 启用</label>
                <button class="btn btn-sm btn-primary">保存</button>
              </form>
            </details>
            <form class="form-inline" method="post" action="{{ route('admin.redirects.destroy',$r) }}"
                  onsubmit="return confirm('删除规则？')">@csrf @method('DELETE')
              <button class="btn btn-sm btn-danger">删</button></form>
          </td>
        </tr>
      @empty
        <tr><td colspan="5" class="empty-cell">暂无规则</td></tr>
      @endforelse
      </tbody>
    </table>
    <div class="pager">{{ $redirects->links() }}</div>
  </div>
</div>
@endsection
