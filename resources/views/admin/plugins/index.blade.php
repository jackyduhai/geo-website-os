@extends('admin.layout')
@section('title','插件管理')
@section('page-desc','插件是文件资源（plugins/{目录}），这里只做发现与按站点启用 / 停用，不提供上传安装。注册不等于授权：所有已安装插件的路由在启动时统一注册，但只有在「当前站点」启用后，该站才能访问；其他站点或停用状态下路由一律 404。下表的路由探测是对当前站点发起的真实前台请求结果。')

@section('content')
@if(!empty($invalid))
<div class="card" style="border-left:3px solid var(--danger,#c0392b);">
  <h2 style="color:var(--danger,#c0392b);">检测到无效插件目录（不会注册、不可启用）</h2>
  <table class="tbl">
    <thead><tr><th class="w-160">目录</th><th>问题</th></tr></thead>
    <tbody>
    @foreach($invalid as $dir => $reason)
      <tr><td class="mono">{{ $dir }}</td><td><span class="badge err">{{ $reason }}</span></td></tr>
    @endforeach
    </tbody>
  </table>
  <p class="small empty-cell">请在服务器文件系统中补齐或修正该目录的 plugin.json；修正前它不会出现在下方可启用列表。</p>
</div>
@endif

<div class="card">
  <table class="tbl">
    <thead>
      <tr>
        <th>插件</th>
        <th class="w-110">本站状态</th>
        <th>注册路由 · 当前站实时探测</th>
        <th class="w-200">依赖 / 能力</th>
        <th class="actions w-150">操作</th>
      </tr>
    </thead>
    <tbody>
    @forelse($rows as $slug => $p)
      <tr>
        <td>
          <strong>{{ $p['name'] }}</strong>
          <span class="badge archived ml-1">v{{ $p['version'] }}</span>
          <div class="mono small">{{ $slug }}</div>
          <div class="small">{{ $p['description'] ?: '（插件未提供描述）' }}</div>
          <div class="mono small empty-cell">Provider：{{ $p['provider'] }}</div>
        </td>
        <td>
          @if($p['is_enabled'])<span class="badge published">已启用</span>@else<span class="badge archived">已停用</span>@endif
        </td>
        <td class="small">
          @forelse($p['routes'] as $rt)
            <div class="mono nowrap">
              <span class="badge archived">{{ implode(',', $rt['methods']) }}</span>
              {{ $rt['uri'] }}
              @if(array_key_exists($rt['uri'], $p['probe']))
                @php $code = $p['probe'][$rt['uri']]; @endphp
                @if($code === 200)
                  <span class="badge published">探测 {{ $code }}</span>
                @elseif($code === 404)
                  <span class="badge archived">探测 {{ $code }}（已隔离）</span>
                @else
                  <span class="badge gap">探测 {{ $code }}</span>
                @endif
              @endif
            </div>
          @empty
            <span class="empty-cell small">无前台路由</span>
          @endforelse
        </td>
        <td class="small">
          @if(!empty($p['dependencies']))
            <div class="mb-1">依赖：
              @foreach($p['dependencies'] as $dep)
                @if(isset($p['blockers'][$dep]))
                  <span class="badge err ml-1">{{ $dep }} · {{ $p['blockers'][$dep] }}</span>
                @else
                  <span class="badge published ml-1">{{ $dep }}</span>
                @endif
              @endforeach
            </div>
          @else
            <div class="empty-cell small mb-1">无依赖</div>
          @endif
          @if(!empty($p['capabilities']))
            <div>能力：
              @foreach($p['capabilities'] as $cap)<span class="badge info ml-1">{{ $cap }}</span>@endforeach
            </div>
          @endif
        </td>
        <td class="actions">
          @if($p['is_enabled'])
            <form method="post" class="form-inline" action="{{ route('admin.plugins.disable', $slug) }}"
                  onsubmit="return confirm('在当前站点停用插件「{{ $p['name'] }}」？停用后其前台路由在本站立即返回 404。')">
              @csrf
              <button class="btn btn-sm btn-danger">停用</button>
            </form>
          @elseif(!empty($p['blockers']))
            <button class="btn btn-sm" disabled title="{{ implode('；', $p['blockers']) }}">依赖未满足</button>
          @else
            <form method="post" class="form-inline" action="{{ route('admin.plugins.enable', $slug) }}"
                  onsubmit="return confirm('在当前站点启用插件「{{ $p['name'] }}」？')">
              @csrf
              <button class="btn btn-sm btn-primary">启用</button>
            </form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="5" class="empty-cell">plugins/ 目录下还没有已安装插件。把插件目录放到 plugins/{slug}/ 并提供合法 plugin.json 后会出现在这里。</td></tr>
    @endforelse
    </tbody>
  </table>
</div>

@if($cross !== null)
<div class="card mt-2">
  <h2>各站点插件启用态（只读总览）</h2>
  <table class="tbl">
    <thead>
      <tr>
        <th>站点</th>
        @foreach($rows as $slug => $p)<th class="nowrap">{{ $p['name'] }}<div class="mono small">{{ $slug }}</div></th>@endforeach
      </tr>
    </thead>
    <tbody>
    @foreach($cross['sites'] as $i => $site)
      <tr>
        <td><strong>{{ $site->name }}</strong><div class="mono small">{{ $site->slug }} · {{ $site->domain ?: '—' }}</div></td>
        @foreach($rows as $slug => $p)
          <td>
            @if(in_array($slug, $cross['enabled'][$i] ?? [], true))
              <span class="badge published">启用</span>
            @else
              <span class="badge archived">停用</span>
            @endif
          </td>
        @endforeach
      </tr>
    @endforeach
    </tbody>
  </table>
  <p class="small empty-cell">要修改某站点的插件，请用顶部站点切换器切到该站点后再启用 / 停用；此处仅作跨站隔离核对。</p>
</div>
@endif

<div class="card mt-2">
  <h2>插件机制说明</h2>
  <ul class="small" style="line-height:1.9;margin:0;padding-left:18px;">
    <li><strong>注册 ≠ 授权</strong>：插件路由在启动时统一注册，并由 per-site 守卫 EnsurePluginEnabled 把关；后台启用只是写当前站点的启用列表，不动态注册路由，也无法绕过守卫。</li>
    <li><strong>依赖闸门</strong>：启用前会校验其声明的 dependencies 已安装且在本站启用，否则拒绝启用且不写设置；停用被其他启用插件依赖的插件时也会被拒绝。</li>
    <li><strong>隔离</strong>：A 站启用、B 站停用时，同一插件路由在 A 站返回 200、在 B 站返回 404，互不影响。</li>
    <li>插件文件的安装 / 升级通过文件部署或 Plugin SDK 完成，后台不写文件、不做在线安装。</li>
  </ul>
</div>
@endsection
