@extends('admin.layout')
@section('title','表单管理')
@section('page-desc','产品化表单：管理员可不改 PHP 创建 Contact / Demo / Download / Appointment 等不同字段的表单。表单提交后保存为完整 Submission，并投影为「客户留言」；字段支持中文 / English 两语言。')

@section('page-actions')
  <div class="btn-row">
    <a class="btn btn-sm btn-primary" href="{{ route('admin.forms.create') }}">＋ 新建表单</a>
    <a class="btn btn-sm" href="{{ route('admin.forms.submissions') }}">全部提交记录</a>
  </div>
@endsection

@section('content')
<div class="card">
  <table class="tbl">
    <thead>
      <tr>
        <th class="w-220">表单名称</th>
        <th class="w-160">slug</th>
        <th class="w-90">状态</th>
        <th class="w-90">字段数</th>
        <th class="w-90">提交数</th>
        <th class="actions w-320">操作</th>
      </tr>
    </thead>
    <tbody>
    @forelse($forms as $f)
      <tr>
        <td>
          <strong>{{ $f->name }}</strong>
          @if($f->slug === \App\Models\Form::DEFAULT_SLUG)<span class="badge info ml-1">默认</span>@endif
        </td>
        <td class="mono small">{{ $f->slug }}</td>
        <td>
          @if($f->isEnabled())
            <span class="badge published">启用</span>
          @else
            <span class="badge archived">停用</span>
          @endif
        </td>
        <td class="small">{{ (int) ($f->fields_count / max(1, count(\App\Support\Localization\LocaleRegistry::supported()))) }}</td>
        <td class="small">{{ (int) $f->submissions_count }}</td>
        <td class="actions">
          <a class="btn btn-sm" href="{{ route('admin.forms.edit',$f) }}">编辑 / 字段</a>
          <a class="btn btn-sm" href="{{ route('admin.forms.formSubmissions',$f) }}">提交</a>
          <form class="form-inline" method="post" action="{{ route('admin.forms.toggle',$f) }}">
            @csrf <button class="btn btn-sm">{{ $f->isEnabled() ? '停用' : '启用' }}</button>
          </form>
          @if($f->slug !== \App\Models\Form::DEFAULT_SLUG)
            <form class="form-inline" method="post" action="{{ route('admin.forms.destroy',$f) }}"
                  onsubmit="return confirm('删除表单「{{ $f->name }}」？其字段定义将一并删除（历史提交保留）。')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-danger">删</button>
            </form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="6" class="empty-cell">还没有表单，点右上角「新建表单」开始。</td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="pager">{{ $forms->links() }}</div>
</div>

<div class="card mt-2">
  <h2>使用说明</h2>
  <ul class="small" style="line-height:1.9;margin:0;padding-left:18px;">
    <li><strong>在前台放置表单</strong>：编辑「组合页面 / Landing」，添加「咨询表单」区块并选择本表单 ID；默认联系页 contact 已引用默认 contact 表单。</li>
    <li><strong>邮件通知</strong>：默认关闭。开启后收件人取表单级「通知收件人」，未配置则用站点设置里的默认收件人，两者都无则不发送；通知失败不影响提交保存。</li>
    <li><strong>提交数据</strong>：完整字段保存在「全部提交记录」，并向后兼容投影到「客户留言」。</li>
  </ul>
</div>
@endsection
