@extends('admin.layout')
@section('title','主题管理')
@section('page-desc','主题是文件资源（resources/themes/{目录}），这里只做发现、预览与按站点激活，不提供上传或在线编辑。激活态按站点独立保存：顶部切换到哪个站点，激活就作用于哪个站点，各站互不影响。预览仅在新窗口临时渲染，不会改变当前激活主题。')

@section('content')
@if(!empty($invalid))
<div class="card" style="border-left:3px solid var(--danger,#c0392b);">
  <h2 style="color:var(--danger,#c0392b);">检测到无效主题目录（不会被激活）</h2>
  <table class="tbl">
    <thead><tr><th class="w-160">目录</th><th>问题</th></tr></thead>
    <tbody>
    @foreach($invalid as $dir => $reason)
      <tr><td class="mono">{{ $dir }}</td><td><span class="badge err">{{ $reason }}</span></td></tr>
    @endforeach
    </tbody>
  </table>
  <p class="small empty-cell">请在服务器文件系统中补齐或修正该目录的 theme.json；修正前它不会出现在下方可激活列表。</p>
</div>
@endif

@if(!empty($duplicates))
<div class="card" style="border-left:3px solid var(--warn,#d97706);">
  <h2 style="color:var(--warn,#d97706);">存在重名主题</h2>
  <p class="small">以下主题显示名相同但来自不同目录，可能造成混淆；激活与预览仍按目录标识区分：</p>
  <p>@foreach($duplicates as $dn)<span class="badge gap ml-1">{{ $dn }}</span>@endforeach</p>
</div>
@endif

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">
@foreach($themes as $slug => $t)
  @php $isActive = ($slug === $active); @endphp
  <div class="card" @if($isActive) style="border-color:var(--primary,#2563eb);" @endif>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
      <strong style="font-size:15px;">{{ $t['name'] }}</strong>
      @if($isActive)<span class="badge published">当前主题</span>@else<span class="badge archived">未激活</span>@endif
    </div>
    <div class="small mono mt-2">目录：{{ $slug }} · v{{ $t['version'] }}</div>
    <p class="small" style="margin:8px 0;min-height:34px;">{{ $t['description'] ?: '（主题未提供描述）' }}</p>
    @if(!empty($t['capabilities']))
      <div class="small mb-2">能力：
        @foreach($t['capabilities'] as $cap)<span class="badge info ml-1">{{ $cap }}</span>@endforeach
      </div>
    @endif
    <div class="btn-row mt-2">
      <a class="btn btn-sm" href="{{ route('admin.themes.preview', $slug) }}" target="_blank" rel="noopener">预览</a>
      @if($isActive)
        <button class="btn btn-sm" disabled>已激活</button>
      @else
        <form method="post" class="form-inline" action="{{ route('admin.themes.activate', $slug) }}"
              onsubmit="return confirm('为当前站点切换到主题「{{ $t['name'] }}」？')">
          @csrf
          <button class="btn btn-sm btn-primary">激活</button>
        </form>
      @endif
    </div>
  </div>
@endforeach
</div>

@if($cross !== null)
<div class="card mt-2">
  <h2>各站点当前激活主题（只读总览）</h2>
  <table class="tbl">
    <thead><tr><th>站点</th><th class="w-160">标识</th><th class="w-160">域名</th><th class="w-180">激活主题</th></tr></thead>
    <tbody>
    @foreach($cross as $row)
      <tr>
        <td><strong>{{ $row['site']->name }}</strong></td>
        <td class="mono small">{{ $row['site']->slug }}</td>
        <td class="mono small">{{ $row['site']->domain ?: '—' }}</td>
        <td><span class="badge {{ $row['theme']===$active ? 'published' : 'archived' }}">{{ $row['theme'] }}</span></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <p class="small empty-cell">要修改某站点的主题，请用顶部站点切换器切到该站点后再激活；此处仅作跨站核对。</p>
</div>
@endif

<div class="card mt-2">
  <h2>主题机制说明</h2>
  <ul class="small" style="line-height:1.9;margin:0;padding-left:18px;">
    <li>主题通过同名视图覆盖（如 site/home、layouts/site）工作，未覆盖的视图自动回退到基础视图。</li>
    <li>激活是原子操作：目标主题清单缺失 / 无效时直接拒绝，不会留下「半激活」状态。</li>
    <li>主题文件的安装、升级与删除通过文件部署 / Theme SDK 完成，后台不写文件，以避免服务器写文件安全面。</li>
    <li>主题配色 Token 的细粒度设置归「站点设置 → 外观与主题」，本页负责整主题的选择与预览。</li>
  </ul>
</div>
@endsection
