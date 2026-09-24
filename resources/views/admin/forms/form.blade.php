@extends('admin.layout')
@section('title', $form->exists ? '编辑表单' : '新建表单')

@section('page-actions')
  @if($form->exists)
    <div class="btn-row">
      <a class="btn btn-sm btn-primary" href="{{ route('admin.forms.fields.create',$form) }}">＋ 添加字段</a>
      <a class="btn btn-sm" href="{{ route('admin.forms.formSubmissions',$form) }}">查看提交</a>
    </div>
  @endif
@endsection

@section('content')
@php
  $action = $form->exists ? route('admin.forms.update',$form) : route('admin.forms.store');
@endphp

<div class="card">
  <form method="post" action="{{ $action }}">
    @csrf
    @if($form->exists) @method('PUT') @endif

    <div class="form-grid">
      <div class="form-row">
        <label>表单名称 <span class="req">*</span></label>
        <input type="text" name="name" value="{{ old('name',$form->name) }}" maxlength="120" required>
      </div>
      <div class="form-row">
        <label>slug（提交 URL 标识） <span class="req">*</span></label>
        <input type="text" name="slug" value="{{ old('slug',$form->slug) }}" maxlength="120" required>
        <p class="hint small">提交地址为 forms/{slug}/submit；站点内唯一。</p>
      </div>
      <div class="form-row">
        <label>表单标题（前台可选，留空不显示）</label>
        <input type="text" name="title" value="{{ old('title',$form->title) }}" maxlength="160">
      </div>
      <div class="form-row">
        <label>提交成功提示（留空用系统默认）</label>
        <textarea name="success_message" rows="2" maxlength="400">{{ old('success_message',$form->success_message) }}</textarea>
      </div>
      <div class="form-row">
        <label>状态</label>
        <select name="status">
          <option value="{{ \App\Models\Form::STATUS_ENABLED }}" @selected(old('status',$form->status ?? \App\Models\Form::STATUS_ENABLED)===\App\Models\Form::STATUS_ENABLED)>启用</option>
          <option value="{{ \App\Models\Form::STATUS_DISABLED }}" @selected(old('status',$form->status)===\App\Models\Form::STATUS_DISABLED)>停用</option>
        </select>
      </div>
    </div>

    <h2 class="mt-2">提交行为</h2>
    <div class="form-grid">
      <label class="form-check">
        <input type="checkbox" name="honeypot_enabled" value="1" @checked(old('honeypot_enabled',$form->honeypot_enabled ?? true))>
        启用蜜罐反垃圾（隐藏字段，机器人填写则静默丢弃）
      </label>
      <label class="form-check">
        <input type="checkbox" name="consent_required" value="1" @checked(old('consent_required',$form->consent_required ?? false))>
        要求用户勾选同意（consent）
      </label>
      <label class="form-check">
        <input type="checkbox" name="notification_enabled" value="1" @checked(old('notification_enabled',$form->notification_enabled ?? false))>
        提交后发送邮件通知（默认关闭）
      </label>
      <div class="form-row">
        <label>通知渠道</label>
        <input type="text" name="notification_channels" value="{{ old('notification_channels',$form->notification_channels ?? 'email') }}" maxlength="60">
      </div>
      <div class="form-row">
        <label>表单级通知收件人（逗号 / 分号 / 换行分隔，可多个）</label>
        <textarea name="notification_recipients" rows="2" maxlength="255"
                  placeholder="例如 sales@example.com">{{ old('notification_recipients',$form->notification_recipients) }}</textarea>
        <p class="hint small">留空则回退站点设置的默认收件人；两者都无则不发送。</p>
      </div>
    </div>

    <div class="btn-row mt-2">
      <button class="btn btn-primary" type="submit">{{ $form->exists ? '保存表单设置' : '创建表单' }}</button>
      <a class="btn" href="{{ route('admin.forms.index') }}">返回列表</a>
    </div>
  </form>
</div>

@if($form->exists)
  <div class="card mt-2">
    <h2>字段（按逻辑字段分组，结构跨语言一致，文案分语言）</h2>
    @php
      $groups = $form->fields->groupBy('name');
    @endphp
    <table class="tbl">
      <thead>
        <tr>
          <th class="w-160">字段 name</th>
          <th class="w-90">类型</th>
          <th class="w-80">必填</th>
          <th>中文 label</th>
          <th>English label</th>
          <th class="w-80">排序</th>
          <th class="actions w-160">操作</th>
        </tr>
      </thead>
      <tbody>
      @forelse($groups as $name => $rows)
        @php
          $zh = $rows->firstWhere('locale','zh-CN');
          $en = $rows->firstWhere('locale','en');
          $any = $zh ?: $rows->first();
        @endphp
        <tr>
          <td class="mono small">{{ $name }}</td>
          <td><span class="badge archived">{{ \App\Support\Forms\FieldTypeRegistry::label($any->type) }}</span></td>
          <td>{{ $any->required ? '是' : '否' }}</td>
          <td class="small">{{ $zh?->label ?: '—' }}</td>
          <td class="small">{{ $en?->label ?: '—' }}</td>
          <td class="small mono">{{ $any->sort_order }}</td>
          <td class="actions">
            <a class="btn btn-sm" href="{{ route('admin.forms.fields.edit',[$form,$any]) }}">编辑</a>
            <form class="form-inline" method="post" action="{{ route('admin.forms.fields.destroy',[$form,$any]) }}"
                  onsubmit="return confirm('删除字段「{{ $name }}」的所有语言版本？')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-danger">删</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="empty-cell">还没有字段，点右上角「添加字段」。</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
@endif
@endsection
