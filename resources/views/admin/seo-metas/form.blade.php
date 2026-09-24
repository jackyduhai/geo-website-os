@extends('admin.layout')
@section('title', $mode==='create' ? '新建 SEO 覆盖' : '编辑 SEO 覆盖')

@section('content')
@php
  $kw = old('keywords_text', isset($seo->keywords) && is_array($seo->keywords) ? implode(', ', $seo->keywords) : '');
  $rb = old('robots_text', isset($seo->robots) && is_array($seo->robots) ? implode(', ', $seo->robots) : '');
  $meta = old('metadata_text', isset($seo->metadata) && is_array($seo->metadata) && $seo->metadata
      ? json_encode($seo->metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '');

  // 语言切换参数（统一走 create，由控制器按 locale 分流到已有覆盖的 edit）。
  $switchBase = ['scope' => $scope];
  if ($scope === 'content') { $switchBase['content_id'] = $selectedContent; }
  if ($scope === 'entity')  { $switchBase['entity_id'] = $selectedEntity; }
  if ($scope === 'page')    { $switchBase['page_id'] = $selectedPage; }

  $objLabel = ['content' => '内容', 'entity' => '实体', 'page' => '页面'];
@endphp

{{-- ============ 语言切换 Tabs（create / edit 均显示） ============ --}}
<div class="card">
  <div class="card-head"><h2>选择要维护的前台语言</h2></div>
  <div class="tabs">
    @foreach($supportedLocales as $l)
      @php $active = $l === $locale; @endphp
      <a class="tab @if($active) active @endif"
         href="{{ route('admin.seo-metas.create', $switchBase + ['locale' => $l]) }}">
        {{ $l === 'zh-CN' ? '中文（zh-CN）' : 'English（en）' }}
      </a>
    @endforeach
  </div>
  <div class="hint small">同一对象的中文与英文 SEO 分别维护、互不影响；当前正在编辑 <strong>{{ $locale }}</strong>。</div>
</div>

{{-- ============ 第一步：非 site 作用域先选绑定对象 ============ --}}
@if($mode==='create' && $scope!=='site' && $target===null)
  <div class="card narrow-md mt-2">
    <div class="card-head"><h2>选择要覆盖 SEO 的{{ $objLabel[$scope] ?? '对象' }}</h2></div>
    <form method="get" action="{{ route('admin.seo-metas.create') }}">
      <input type="hidden" name="scope" value="{{ $scope }}">
      <input type="hidden" name="locale" value="{{ $locale }}">
      <div class="form-grid">
        <div class="form-row">
          <label class="req">{{ $objLabel[$scope] ?? '对象' }}</label>
          @if($scope==='content')
            <select name="content_id" class="select" required size="12">
              @foreach($contents as $c)
                <option value="{{ $c->id }}">[{{ $c->type }}] {{ $c->title ?: '(无标题)' }} · {{ $c->slug }} · {{ $c->status }}</option>
              @endforeach
            </select>
          @elseif($scope==='entity')
            <select name="entity_id" class="select" required size="12">
              @foreach($entities as $e)
                <option value="{{ $e->id }}">[{{ $entityTypeLabels[$e->type] ?? $e->type }}] {{ $e->name }} · {{ $e->slug }} · {{ $e->status }}</option>
              @endforeach
            </select>
          @else
            <select name="page_id" class="select" required size="12">
              @foreach($pages as $p)
                <option value="{{ $p->id }}">{{ $p->title ?: '(无标题)' }} · {{ $p->template }} · {{ $p->slug ?: '(landing)' }}</option>
              @endforeach
            </select>
          @endif
          <div class="hint small">仅列出当前站点、当前语言（{{ $locale }}）的对象；同一对象同一语言至多一条覆盖，已有覆盖会直接打开编辑。</div>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-sm btn-primary">下一步：编辑 SEO 覆盖</button>
        <a class="btn btn-sm" href="{{ route('admin.seo-metas.index') }}">返回</a>
      </div>
    </form>
  </div>
@else

{{-- ============ 完整表单 ============ --}}
@php
  $action = $mode==='create' ? route('admin.seo-metas.store') : route('admin.seo-metas.update',$seo);
@endphp
<form method="post" action="{{ $action }}">
  @csrf
  @if($mode==='edit') @method('PUT') @endif
  <input type="hidden" name="scope" value="{{ $scope }}">
  <input type="hidden" name="locale" value="{{ $locale }}">
  @if($scope==='content')<input type="hidden" name="content_id" value="{{ $selectedContent }}">@endif
  @if($scope==='entity')<input type="hidden" name="entity_id" value="{{ $selectedEntity }}">@endif
  @if($scope==='page')<input type="hidden" name="page_id" value="{{ $selectedPage }}">@endif

  {{-- 作用域与绑定对象 --}}
  <div class="card mt-2">
    <div class="card-head">
      <h2>
        {{ $scopeLabels[$scope] }} SEO 覆盖
        @if($scope==='site')
          <span class="badge info ml-1">整站默认 · 每站每语言一条</span>
        @elseif($scope==='content')
          <span class="badge published ml-1">内容</span>
        @elseif($scope==='page')
          <span class="badge published ml-1">页面</span>
        @else
          <span class="badge published ml-1">实体</span>
        @endif
        <span class="badge archived ml-1">{{ $locale }}</span>
      </h2>
      @if($mode==='edit')<span class="small">绑定对象与语言创建后不可更改。</span>@endif
    </div>
    <div class="small">
      @if($scope==='site')
        当前站点：<strong>{{ $target?->name }}</strong>
      @elseif($scope==='content')
        绑定内容：<strong>{{ $target?->title }}</strong>
        <span class="badge archived ml-1">{{ $target?->type }}</span>
        <span class="mono">{{ $target?->slug }}</span>
      @elseif($scope==='page')
        绑定页面：<strong>{{ $target?->title }}</strong>
        <span class="badge archived ml-1">{{ $target?->template }}</span>
        <span class="mono">{{ $target?->slug ?: '(landing)' }}</span>
      @else
        绑定实体：<strong>{{ $target?->name }}</strong>
        <span class="badge archived ml-1">{{ $entityTypeLabels[$target?->type] ?? $target?->type }}</span>
        <span class="mono">{{ $target?->slug }}</span>
      @endif
    </div>
    @error('scope')<div class="field-err">{{ $message }}</div>@enderror
    @error('content_id')<div class="field-err">{{ $message }}</div>@enderror
    @error('entity_id')<div class="field-err">{{ $message }}</div>@enderror
    @error('page_id')<div class="field-err">{{ $message }}</div>@enderror
  </div>

  {{-- 当前解析结果（直接来自 SeoMetaResolver，后台不自算 fallback） --}}
  @if($resolved)
  <div class="card mt-2">
    <div class="card-head">
      <h2>当前解析结果（{{ $locale }} 前台 / Schema / GEO 实际输出）</h2>
      <span class="head-actions"><span class="badge ok">由 SeoMetaResolver 在 {{ $locale }} 上下文实时解析</span></span>
    </div>
    <x-admin-tip type="info" text="这是访客与搜索引擎最终看到的值。留空的字段继续沿用继承链；保存后本卡片即时更新。后台不在此重复实现兜底逻辑。"/>
    <table class="tbl mt-1">
      <tr><th class="w-150">Title</th><td>{{ $resolved->title }}</td></tr>
      <tr><th>Description</th><td>{{ $resolved->description ?: '（空）' }}</td></tr>
      <tr><th>Canonical</th><td class="mono small">{{ $resolved->canonical }}</td></tr>
      <tr><th>OG 标题</th><td>{{ $resolved->ogTitle }}</td></tr>
      <tr><th>OG 描述</th><td>{{ $resolved->ogDescription ?: '（空）' }}</td></tr>
      <tr><th>OG 图片</th><td class="mono small">{{ $resolved->ogImage ?: '（无）' }}</td></tr>
      <tr><th>OG 类型 / Twitter</th><td class="mono small">{{ $resolved->ogType }} · {{ $resolved->twitterCard }}</td></tr>
      <tr><th>抓取指令</th>
        <td>
          @if($resolved->noindex)<span class="badge err">noindex</span>@else<span class="badge published">index</span>@endif
          @if($resolved->nofollow)<span class="badge err">nofollow</span>@else<span class="badge published">follow</span>@endif
          @if(count($resolved->robots))<span class="badge archived ml-1">{{ implode(' ', $resolved->robots) }}</span>@endif
        </td>
      </tr>
    </table>
  </div>
  @endif

  {{-- 基础 SEO --}}
  <div class="card mt-2 narrow-md">
    <div class="card-head"><h2>基础</h2></div>
    <div class="form-grid">
      <div class="form-row">
        <label>SEO 标题 Title
          <x-admin-tip text="留空则继承：{{ $scope==='content' ? '内容标题 → 站点标题 → 系统' : ($scope==='entity' ? '实体名称 → 站点标题 → 系统' : ($scope==='page' ? '页面标题 → 站点标题 → 系统' : '站点名称 → 系统')) }}。建议 60 字以内。"/></label>
        <input type="text" name="title" class="select" maxlength="255" value="{{ old('title',$seo->title) }}">
        @error('title')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <div class="form-row">
        <label>描述 Description
          <x-admin-tip text="留空则继承：{{ $scope==='content' ? '内容摘要 → 站点描述 → 系统' : ($scope==='entity' ? '实体摘要 → 实体正文 → 站点描述 → 系统' : ($scope==='page' ? '页面摘要 → 站点描述 → 系统' : '站点描述 → 系统')) }}。建议 140–160 字。"/></label>
        <textarea name="description" rows="3" class="textarea" maxlength="2000">{{ old('description',$seo->description) }}</textarea>
        @error('description')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <div class="form-row">
        <label>关键词 Keywords
          <x-admin-tip text="多个关键词用英文逗号分隔；留空表示不输出（不影响 title / description 继承）。"/></label>
        <input type="text" name="keywords_text" class="select" value="{{ $kw }}" placeholder="例如：product, service, OEM">
        @error('keywords_text')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <div class="form-row">
        <label>规范链接 Canonical
          <x-admin-tip text="留空 = 自动生成（遵循语言前缀与斜杠契约），即下方灰色自动值；填写完整 URL 则以自定义为最高优先。"/></label>
        <input type="text" name="canonical" class="select mono" value="{{ old('canonical',$seo->canonical) }}" placeholder="留空自动生成">
        @if($resolved)
          <div class="hint small">自动 / 当前值：<span class="mono">{{ $resolved->canonical }}</span></div>
        @endif
        @error('canonical')<div class="field-err">{{ $message }}</div>@enderror
      </div>
    </div>
  </div>

  {{-- 社交分享 --}}
  <div class="card mt-2 narrow-md">
    <div class="card-head"><h2>社交分享（Open Graph / Twitter）</h2></div>
    <div class="form-grid">
      <div class="form-row">
        <label>OG 标题</label>
        <input type="text" name="og_title" class="select" maxlength="255" value="{{ old('og_title',$seo->og_title) }}" placeholder="留空沿用 SEO 标题">
        @error('og_title')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <div class="form-row">
        <label>OG 描述</label>
        <textarea name="og_description" rows="2" class="textarea" maxlength="2000" placeholder="留空沿用描述">{{ old('og_description',$seo->og_description) }}</textarea>
        @error('og_description')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <div class="form-row">
        <label>OG 分享图
          <x-admin-tip text="可从媒体库选择（写入 /storage 站内公开路径），或直接填写图片完整 URL / 路径；两者都留空则按回退链（内容 OG 图 → 封面 → 站点 Logo）。"/></label>
        <select name="og_media_id" class="select">
          <option value="">— 使用下方手填 / 不指定（走回退链）—</option>
          @foreach($mediaImages as $m)
            <option value="{{ $m->id }}" @selected((string) old('og_media_id')===(string) $m->id)>/storage/{{ ltrim($m->path,'/') }} · {{ basename($m->path) }}</option>
          @endforeach
        </select>
        <input type="text" name="og_image_path" class="select mono mt-1" maxlength="500"
               value="{{ old('og_image_path',$seo->og_image_path) }}" placeholder="或手填图片 URL / 路径，如 /storage/covers/og.jpg">
        @error('og_image_path')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <div class="form-row">
        <label>OG 类型 / Twitter 卡片</label>
        <div class="form-inline">
          <select name="og_type" class="select" style="max-width:240px;">
            @foreach($ogTypes as $vk=>$vn)<option value="{{ $vk }}" @selected(old('og_type',$seo->og_type ?? 'website')===$vk)>{{ $vn }}</option>@endforeach
          </select>
          <select name="twitter_card" class="select" style="max-width:260px;">
            @foreach($twitterCards as $vk=>$vn)<option value="{{ $vk }}" @selected(old('twitter_card',$seo->twitter_card ?? 'summary_large_image')===$vk)>{{ $vn }}</option>@endforeach
          </select>
        </div>
      </div>
    </div>
  </div>

  {{-- 抓取指令 --}}
  <div class="card mt-2 narrow-md">
    <div class="card-head"><h2>搜索引擎抓取指令</h2></div>
    <div class="form-grid">
      <div class="form-row">
        <x-admin-tip type="info" text="不创建覆盖 = 完全继承（默认可索引可跟踪）。创建覆盖后：勾选即明确禁止；不勾选即明确允许。需要 noarchive / noimageindex 等额外指令可在下方填写。"/>
        <label class="checkline"><input type="checkbox" name="noindex" value="1" @checked(old('noindex',$seo->noindex ?? false))> noindex —— 不希望该页面被搜索引擎收录</label>
        <label class="checkline mt-1"><input type="checkbox" name="nofollow" value="1" @checked(old('nofollow',$seo->nofollow ?? false))> nofollow —— 不跟踪页面上的链接</label>
        @error('noindex')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <div class="form-row">
        <label>额外 robots 指令
          <x-admin-tip text="逗号分隔，如 noarchive, noimageindex；一般页面留空即可。"/></label>
        <input type="text" name="robots_text" class="select" value="{{ $rb }}" placeholder="例如：noarchive">
        @error('robots_text')<div class="field-err">{{ $message }}</div>@enderror
      </div>
    </div>
  </div>

  {{-- 高级 --}}
  <div class="card mt-2 narrow-md">
    <div class="card-head"><h2>高级（一般无需填写）</h2></div>
    <div class="form-grid">
      <div class="form-row">
        <label>Schema 类型 schema_type
          <x-admin-tip text="覆盖该页面 JSON-LD 的 @type 提示，如 Article / Product / Organization；留空由系统按资源类型决定。"/></label>
        <input type="text" name="schema_type" class="select" maxlength="64" value="{{ old('schema_type',$seo->schema_type) }}" placeholder="留空自动">
        @error('schema_type')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <div class="form-row">
        <label>自定义 metadata（JSON）
          <x-admin-tip text="高级扩展字段，必须是合法 JSON 对象，例如 {&quot;alternate_name&quot;:&quot;...&quot;}；留空表示不设置。"/></label>
        <textarea name="metadata_text" rows="4" class="textarea mono">{{ $meta }}</textarea>
        @error('metadata_text')<div class="field-err">{{ $message }}</div>@enderror
      </div>
    </div>
  </div>

  <div class="card mt-2">
    <div class="form-actions">
      <button class="btn btn-sm btn-primary">保存 SEO 覆盖</button>
      <a class="btn btn-sm" href="{{ route('admin.seo-metas.index') }}">返回列表</a>
      @if($mode==='edit')
        <form class="form-inline ml-1" method="post" action="{{ route('admin.seo-metas.destroy',$seo) }}"
              onsubmit="return confirm('删除该 SEO 覆盖？删除后对应字段恢复自动解析（继承），不会删除绑定对象。')">
          @csrf @method('DELETE')
          <button class="btn btn-sm btn-danger">删除覆盖</button>
        </form>
      @endif
    </div>
  </div>
</form>
@endif
@endsection
