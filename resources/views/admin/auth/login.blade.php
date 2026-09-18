<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>登录 · Example Admin</title>
<link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
</head>
<body class="login-page">
<form class="login-card" method="post" action="{{ route('admin.login.attempt') }}">
  @csrf
  <img class="login-logo" src="{{ asset('img/logo.png') }}" alt="Example">
  <div class="login-brand">Example Admin</div>
  <div class="login-sub">中式Sample SnackSample Marinade与调理鸡肉 OEM 代工 · 内容管理系统</div>

  @if(session('error'))<div class="login-alert">{{ session('error') }}</div>@endif
  @if($errors->any())
    <div class="login-alert">{{ $errors->first() }}</div>
  @endif

  <label for="email">管理员邮箱</label>
  <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus placeholder="admin@example.test">

  <label for="password">密码</label>
  <input id="password" type="password" name="password" required>

  <label class="login-remember"><input type="checkbox" name="remember" value="1"> 7 天内免登录</label>

  <button class="login-btn" type="submit">登 录</button>
  <div class="login-foot">仅授权管理人员可登录，登录操作会被记录</div>
</form>
</body>
</html>
