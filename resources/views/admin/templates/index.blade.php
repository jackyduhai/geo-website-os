@extends('admin.layout')
@section('title','模板生态')
@section('page-desc','模板包是声明式文件资源（resources/templates/{目录}），这里只做发现、预览、对比、按站点激活与初始化默认值，不提供上传或在线编辑。激活态按站点独立保存：顶部切换到哪个站点，激活就作用于哪个站点。激活只生成页面骨架（配方）；建议默认设置 / 菜单 / SEO 需点「初始化默认值」显式落地，且不覆盖已有配置。')

@section('content')
@if(!empty($invalid))
<div class="card" style="border-left:3px solid var(--danger,#c0392b);">
  <h2 style="color:var(--danger,#c0392b);">检测到无效模板包目录（不会被激活）</h2>
  <table class="tbl">
    <thead><tr><th class="w-160">目录</th><th>问题</th></tr></thead>
    <tbody>
    @foreach($invalid as $dir => $reasons)
      <tr><td class="mono">{{ $dir }}</td><td>
        @foreach($reasons as $reason)<span class="badge err ml-1">{{ $reason }}</span>@endforeach
      </td></tr>
    @endforeach
    </tbody>
  </table>
  <p class="small empty-cell">请在文件系统中补齐或修正该目录的 manifest.json；修正前它不会出现在下方可激活列表。</p>
</div>
@endif

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
@foreach($packs as $id => $m)
  @php $isActive = ($id === $active); @endphp
  <div class="card" @if($isActive) style="border-color:var(--primary,#2563eb);" @endif>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
      <strong style="font-size:15px;">{{ $m['name'] }}</strong>
      @if($isActive)<span class="badge published">当前模板</span>@else<span class="badge archived">未激活</span>@endif
    </div>
    <div class="small mono mt-2">包：{{ $id }} · v{{ $m['version'] }}</div>
    <div class="small mt-1">行业：{{ implode('、', (array)($m['industry'] ?? [])) }} · 语言：{{ implode('/', (array)($m['locales'] ?? [])) }}</div>

    <div class="small mt-2" style="min-height:46px;">
      @if(!empty($m['identity']['audience']))
        <div class="small">适用：{{ implode('；', (array)$m['identity']['audience']) }}</div>
      @endif
      <div class="mt-1">
        @if(!empty($m['purpose']['primary']))<span class="badge info">目标：{{ $m['purpose']['primary'] }}</span>@endif
        @if(!empty($m['conversion']['primary']))<span class="badge gap">转化：{{ $m['conversion']['primary'] }}</span>@endif
      </div>
      <div class="mt-1">
        @foreach((array)($m['entities'] ?? []) as $ent)<span class="badge ml-1">{{ $ent }}</span>@endforeach
      </div>
    </div>

    {{-- RC-11 F · 结构特征。
         manifest 只说明「什么行业」，看不出「长什么样」；真正决定页面形态的是
         recipes/*.json 的 block 组合与顺序。这段把该结构直接暴露给使用者，
         即使暂无预览截图，8 个模板也能被真实区分开。 --}}
    @php $st = $structure[$id] ?? null; @endphp
    @if($st)
      <div class="tpl-struct">
        <div class="tpl-struct-row">
          <span class="tpl-k">页面配方</span>
          <span class="tpl-v">
            @if($st['recipe_count'] > 0)
              {{ $st['recipe_count'] }} 个
              <span class="small muted">（{{ implode(' / ', $st['page_list']) }}）</span>
            @else
              <span class="muted">无</span>
            @endif
          </span>
        </div>
        <div class="tpl-struct-row">
          <span class="tpl-k">区块总数</span>
          <span class="tpl-v">{{ $st['block_total'] }} 个
            @if($st['block_counts'])
              <span class="small muted">（{{ implode(' · ', array_map(
                fn ($t, $c) => $c . '× ' . (\App\Support\Templates\TemplatePackageManager::blockLabels()[$t] ?? $t),
                array_keys($st['block_counts']),
                $st['block_counts']
              )) }}）</span>
            @endif
          </span>
        </div>
        @if($st['homepage_blocks'])
          <div class="tpl-struct-row">
            <span class="tpl-k">首页结构</span>
            <span class="tpl-v">
              @foreach($st['homepage_blocks'] as [$bt, $btLabel])
                <span class="tpl-block">{{ $btLabel }}</span>
              @endforeach
            </span>
          </div>
        @endif
      </div>
    @endif

    <div class="btn-row mt-2">
      {{-- RC-11 G：活站预览。在**隔离的预览站**里激活并渲染，
           真实站点零风险（bootstrap 写库无自动回滚，绝不在真实站上试）。
           预览站不常驻，关掉即整体回收。 --}}
      @php $previewSite = \App\Models\Site::withoutGlobalScopes()->where('slug', 'tpl-preview-' . $id)->first(); @endphp
      <form method="post" class="form-inline" action="{{ route('admin.templates.live-preview.open', $id) }}">
        @csrf
        <button class="btn btn-sm {{ $previewSite ? '' : 'btn-primary' }}">看真实效果</button>
      </form>
      @if($previewSite)
        <form method="post" class="form-inline" action="{{ route('admin.templates.live-preview.close', $id) }}"
              onsubmit="return confirm('关闭预览并删除该模板的预览站及其全部数据？')">
          @csrf
          <button class="btn btn-sm">关闭预览</button>
        </form>
        <a class="btn btn-sm" href="{{ \App\Support\Templates\TemplatePreviewSite::url($id) }}"
           target="_blank" rel="noopener">打开 ↗</a>
      @endif
      <a class="btn btn-sm" href="{{ route('admin.templates.preview', $id) }}" target="_blank" rel="noopener">截图</a>
      <a class="btn btn-sm" href="{{ route('admin.templates.compare', $id) }}">对比</a>
      @if($isActive)
        <form method="post" class="form-inline" action="{{ route('admin.templates.deactivate') }}"
              onsubmit="return confirm('停用当前模板包并恢复核心模板？')">
          @csrf
          <button class="btn btn-sm">停用</button>
        </form>
      @else
        <form method="post" class="form-inline" action="{{ route('admin.templates.activate', $id) }}"
              onsubmit="return confirm('为当前站点激活模板「{{ $m['name'] }}」并生成页面骨架？建议默认值不会被覆盖。')">
          @csrf
          <button class="btn btn-sm btn-primary">激活</button>
        </form>
      @endif
    </div>
    <div class="btn-row mt-1">
      <form method="post" class="form-inline" action="{{ route('admin.templates.bootstrap', $id) }}"
            onsubmit="return confirm('为模板「{{ $m['name'] }}」初始化建议默认设置 / 菜单 / SEO？已有配置不会被覆盖。')">
        @csrf
        <button class="btn btn-sm">初始化默认值</button>
      </form>
    </div>
  </div>
@endforeach
</div>

@if($cross !== null)
<div class="card mt-2">
  <h2>各站点当前激活模板包（只读总览）</h2>
  <table class="tbl">
    <thead><tr><th>站点</th><th class="w-160">标识</th><th class="w-160">域名</th><th class="w-200">激活模板包</th></tr></thead>
    <tbody>
    @foreach($cross as $row)
      <tr>
        <td><strong>{{ $row['site']->name }}</strong></td>
        <td class="mono small">{{ $row['site']->slug }}</td>
        <td class="mono small">{{ $row['site']->domain ?: '—' }}</td>
        <td>@if($row['pack'] !== '')<span class="badge {{ $row['pack']===$active ? 'published' : 'archived' }}">{{ $row['pack'] }}</span>@else<span class="small">核心模板</span>@endif</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <p class="small empty-cell">要修改某站点的模板，请用顶部站点切换器切到该站点后再激活；此处仅作跨站核对。</p>
</div>
@endif

<div class="card mt-2">
  <h2>模板机制说明</h2>
  <ul class="small" style="line-height:1.9;margin:0;padding-left:18px;">
    <li><strong>主题管视觉、模板管结构、区块管能力、内容管事实</strong>：模板包只声明页面组合，不携带 PHP / Blade / JS / CSS。</li>
    <li>激活前自动跑三层校验（清单 / 配方 / 运行时兼容），存在错误直接拒绝，不会留下「半激活」状态。</li>
    <li>业务事实（产品 / 服务 / 文章）仍是唯一事实源，模板不会把它们复制进页面。</li>
    <li>模板文件的安装、升级与删除通过文件部署 / Template SDK 完成，后台不写文件。</li>
  </ul>
</div>
@endsection
