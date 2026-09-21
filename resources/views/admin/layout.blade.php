<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', '仪表盘') · GEO Website OS 后台</title>
<link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
@stack('head')
</head>
<body>
@php
  // 后台信息架构：按「运营对象」分组（路由名与控制器保持不变，仅重组导航层）
  $newInquiries = \App\Models\Inquiry::where('status','new')->count();
  $routeName = request()->route() ? request()->route()->getName() : '';
  $settingsGroup = request()->routeIs('admin.settings.*') ? request()->route('group') : null;
  $is = fn($name) => $routeName === $name || str_starts_with($routeName, $name.'.');

  // 一级直达项（高频/单页功能，带图标）
  $navDirect = [
      ['仪表盘', $is('admin.dashboard'), route('admin.dashboard'), null, 'grid'],
      ['客户留言', str_starts_with($routeName,'admin.inquiries'), route('admin.inquiries.index',['status'=>'new']), $newInquiries, 'inbox'],
      ['首页整体装修', str_starts_with($routeName,'admin.blocks'), route('admin.blocks.index'), null, 'template'],
      ['顶部导航与页脚', str_starts_with($routeName,'admin.menus'), route('admin.menus.index'), null, 'panel-top'],
      ['事实库', str_starts_with($routeName,'admin.facts'), route('admin.facts.index'), null, 'shield'],
  ];
  // 真分组（≥4 条目才设分组头，分组头带图标，子项纯文字缩进）
  $navGroups = [
    ['label'=>'内容中心','icon'=>'file-text','links'=>[
        ['内容管理', str_starts_with($routeName,'admin.contents'), route('admin.contents.index','article')],
        ['页面文案', str_starts_with($routeName,'admin.narrative'), route('admin.narrative.index')],
        ['栏目与知识分组', str_starts_with($routeName,'admin.categories') || str_starts_with($routeName,'admin.groups'), route('admin.categories.index')],
        ['媒体库', str_starts_with($routeName,'admin.media'), route('admin.media.index')],
    ]],
    ['label'=>'搜索与 AI（SEO/GEO）','icon'=>'search','links'=>[
        ['SEO 设置', $settingsGroup==='seo', route('admin.settings.index','seo')],
        ['GEO 设置', $settingsGroup==='geo', route('admin.settings.index','geo')],
        ['抓取产出 / Sitemap', $is('admin.geo.tools') || $is('admin.geo.preview'), route('admin.geo.tools')],
        ['301 跳转', str_starts_with($routeName,'admin.redirects'), route('admin.redirects.index')],
        ['GEOFlow 对接', $settingsGroup==='sync', route('admin.settings.index','sync')],
        ['同步日志', $is('admin.geo.sync-logs'), route('admin.geo.sync-logs')],
    ]],
    ['label'=>'站点设置','icon'=>'settings','links'=>[
        ['公司基础信息', $settingsGroup==='general', route('admin.settings.index','general')],
        ['联系方式', $settingsGroup==='contact', route('admin.settings.index','contact')],
        ['全站文案话术', $settingsGroup==='copy', route('admin.settings.index','copy')],
        ['外观与主题', $settingsGroup==='theme', route('admin.settings.index','theme')],
    ]],
  ];

  // 站点管理是跨站租户根操作，仅超级管理员可见；并在导航最前方提供入口。
  $isSuperAdmin = \App\Support\SystemAuthorization::canCrossSite();
  if ($isSuperAdmin) {
      array_unshift($navDirect,
          ['站点管理', str_starts_with($routeName, 'admin.sites'), route('admin.sites.index'), null, 'globe']);
  }
  // 顶部站点切换器数据（仅超管；多于一个站点时才有切换意义）。
  $adminSites = $isSuperAdmin ? \App\Models\Site::orderBy('id')->get() : collect();
  $currentAdminSiteSlug = session('admin_site_slug') ?: optional(\App\Support\SiteContext::currentSite())->slug;
@endphp

<div class="side-overlay" data-close-side></div>
<div class="layout">
  <aside class="side" id="adminSide">
    <a class="brand" href="{{ route('admin.dashboard') }}">
      <img src="{{ asset('img/logo.png') }}" alt="GEO Website OS">
      <span>GEO Website OS 后台</span>
    </a>

    <nav class="side-nav" aria-label="后台主导航">
      <div class="nav-direct">
        @foreach($navDirect as $l)
          <a href="{{ $l[2] }}" class="{{ $l[1] ? 'on' : '' }}">
            @include('admin.partials.nav-icon',['name'=>$l[4]])
            <span class="nav-text">{{ $l[0] }}</span>
            @if(!empty($l[3]))<span class="nav-count">{{ $l[3] }}</span>@endif
          </a>
        @endforeach
      </div>
      @foreach($navGroups as $group)
        <div class="nav-group">
          <div class="nav-group-head">
            @include('admin.partials.nav-icon',['name'=>$group['icon']])
            <span>{{ $group['label'] }}</span>
          </div>
          <div class="nav-sub">
            @foreach($group['links'] as $l)
              <a href="{{ $l[2] }}" class="nav-sub-link {{ $l[1] ? 'on' : '' }}">{{ $l[0] }}</a>
            @endforeach
          </div>
        </div>
      @endforeach
    </nav>
  </aside>

  <div class="main">
    <header class="topbar">
      <button type="button" class="side-toggle" id="sideToggle" aria-label="打开导航菜单">☰</button>
      <div class="crumb">官网后台 / <b>@yield('title','仪表盘')</b></div>
      <div class="who">
        @if($isSuperAdmin && $adminSites->count() > 1)
          <form method="post" action="{{ route('admin.sites.switch') }}" class="site-switch-form">
            @csrf
            <label class="site-switch-label" for="adminSiteSwitch">管理站点</label>
            <select name="site_id" id="adminSiteSwitch" class="site-switch-select"
                    onchange="this.form.submit()" title="切换当前后台管理的站点">
              @foreach($adminSites as $s)
                <option value="{{ $s->id }}" @selected($s->slug === $currentAdminSiteSlug)>{{ $s->name }}</option>
              @endforeach
            </select>
          </form>
        @endif
        <a href="{{ url('/') }}" target="_blank">查看官网 ↗</a>
        <a href="{{ route('admin.password') }}">修改密码</a>
        <span>{{ auth()->user()->name ?? '' }}（{{ auth()->user()->email ?? '' }}）</span>
        <form method="post" action="{{ route('admin.logout') }}">@csrf
          <button class="btn btn-sm" type="submit">退出登录</button>
        </form>
      </div>
    </header>

    <main class="content">
      @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
      @if(session('error'))<div class="alert alert-error">{{ session('error') }}</div>@endif
      @if($errors->any())
        <div class="alert alert-error">
          请修正以下问题：
          <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
      @endif

      <div class="page-head">
        <div>
          @php $__pageDesc = trim($__env->yieldContent('page-desc')); @endphp
          <h1>@yield('title','仪表盘')@if($__pageDesc !== '')<x-admin-tip type="help" place="bottom" :width="300" :text="$__pageDesc" />@endif</h1>
        </div>
        @hasSection('page-actions')<div class="page-actions">@yield('page-actions')</div>@endif
      </div>

      @yield('content')
    </main>
  </div>
</div>
@stack('scripts')
<script>
// 移动端（≤900px）侧栏抽屉：汉堡打开、遮罩或点导航后收起
(function(){
  var body=document.body, btn=document.getElementById('sideToggle');
  function close(){ body.classList.remove('side-open'); }
  if(btn){ btn.addEventListener('click',function(){ body.classList.toggle('side-open'); }); }
  document.querySelectorAll('[data-close-side]').forEach(function(el){ el.addEventListener('click',close); });
  document.querySelectorAll('#adminSide a').forEach(function(a){ a.addEventListener('click',close); });
})();
// 窄屏 Tooltip 边缘钳制：气泡默认左缘对齐图标，图标靠近视口中/右侧时按视口边缘修正，箭头始终对准图标
(function(){
  function clampTips(){
    if(window.matchMedia('(min-width:561px)').matches) return;
    document.querySelectorAll('.tip').forEach(function(t){
      var r=t.getBoundingClientRect(), vw=document.documentElement.clientWidth;
      var w=Math.min(parseInt(getComputedStyle(t).getPropertyValue('--tip-w'))||260, 260, vw-32);
      var cx=r.left+r.width/2, left=Math.max(16,Math.min(cx-w/2, vw-w-16));
      t.style.setProperty('--tx',(left-r.left)+'px');
    });
  }
  document.addEventListener('mouseover',function(e){ var t=e.target.closest&&e.target.closest('.tip'); if(t) clampTips(); },true);
  document.addEventListener('focusin',function(e){ if(e.target.closest&&e.target.closest('.tip')) clampTips(); },true);
  document.addEventListener('touchstart',function(e){ var t=e.target.closest&&e.target.closest('.tip'); if(t) clampTips(); },true);
  window.addEventListener('resize',clampTips);
})();
</script>
</body>
</html>
