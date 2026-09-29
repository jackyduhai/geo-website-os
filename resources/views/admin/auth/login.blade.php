<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>登录 · GEO Website OS 后台</title>
<link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
</head>
<body class="login-page">
@php
  // 品牌：自定义 logo（完整横版）时只显示 logo；否则纯图标 + 公司名称。
  // 复用现有站点设置，不另造事实源（与 admin layout / SchemaBuilder 同口径）。
  $loginCustomLogo = trim((string)($siteSettings['geo_org_logo'] ?? ''));
  $loginCompanyName = trim((string)($siteSettings['geo_org_name'] ?? ''))
      ?: trim((string)($siteSettings['site_name'] ?? ''))
      ?: config('app.name');
@endphp
<form class="login-card" method="post" action="{{ route('admin.login.attempt') }}">
  @csrf
  @if($loginCustomLogo !== '')
    <img class="login-logo-full" src="{{ asset($loginCustomLogo) }}" alt="{{ $loginCompanyName }}">
  @else
    <img class="login-logo" src="{{ asset('img/logo-icon.png') }}" alt="{{ $loginCompanyName }}">
  @endif
  <div class="login-brand">{{ $loginCompanyName }}</div>
  <div class="login-sub">多站点 GEO 内容管理系统</div>

  @if(session('error'))<div class="login-alert">{{ session('error') }}</div>@endif
  @if($errors->any())
    <div class="login-alert">{{ $errors->first() }}</div>
  @endif

  <label for="email">管理员邮箱</label>
  <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus placeholder="admin@example.com">

  <label for="password">密码</label>
  <input id="password" type="password" name="password" required>

  <label class="login-remember"><input type="checkbox" name="remember" value="1"> 7 天内免登录</label>

  <button class="login-btn" type="submit">登 录</button>
  <div class="login-foot">仅授权管理人员可登录，登录操作会被记录</div>
</form>
</body>
</html>
