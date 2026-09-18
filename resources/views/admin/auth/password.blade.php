@extends('admin.layout')
@section('title','修改密码')
@section('page-desc','定期修改后台登录密码。')

@section('content')
<div class="card narrow-sm">
  <h2>修改登录密码</h2>
  <form method="post" action="{{ route('admin.password.update') }}">
    @csrf @method('PUT')
    <div class="form-row">
      <label>当前密码 <span class="req">*</span></label>
      <input type="password" name="current_password" required autocomplete="current-password">
    </div>
    <div class="form-row">
      <label><span class="label-with-tip">新密码 <span class="req">*</span><x-admin-tip type="warning" text="至少 8 位，需同时包含大写字母、小写字母与数字。"/></span></label>
      <input type="password" name="password" required autocomplete="new-password">
    </div>
    <div class="form-row">
      <label>确认新密码 <span class="req">*</span></label>
      <input type="password" name="password_confirmation" required autocomplete="new-password">
    </div>
    <button class="btn btn-primary" type="submit">保存新密码</button>
  </form>
</div>
@endsection
