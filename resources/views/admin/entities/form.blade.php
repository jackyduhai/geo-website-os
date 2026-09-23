@extends('admin.layout')
@php
  $type = $entity->type;
  $typeLabel = $typeNames[$type] ?? $type;
  $meta = is_array($entity->metadata) ? $entity->metadata : [];
  $isProduct = $type === 'product';
  $isOrg = $type === 'organization';
  $isLocation = $type === 'location';
  $isService = $type === 'service';
  use App\Support\Localization\LocaleRegistry;
  $isDefault = $transLocale === LocaleRegistry::default();
  $localeLabels = ['zh-CN' => '中文', 'en' => 'English'];
@endphp
@section('title', $entity->exists ? '编辑实体：'.$entity->name : '新建'.$typeLabel)
@section('page-desc','实体是 GEO 知识图谱与前台目录的正式资源。带 * 为必填；slug 为小写字母 / 数字 / 连字符，同站点下同类型唯一。保存后类型不可修改。')

@section('content')
@if($anchor->exists)
  <div class="locale-tabs">
    @foreach($versions as $loc => $done)
      @php
        $tabUrl = $loc === LocaleRegistry::default()
          ? route('admin.entities.edit', ['entity' => $anchor])
          : route('admin.entities.edit', ['entity' => $anchor, 'trans' => $loc]);
      @endphp
      @if($loc === $transLocale)
        <span class="lt-cur">{{ $localeLabels[$loc] ?? $loc }}
          <span class="lt-state" aria-label="{{ $done ? '已完成' : '未完成' }}">{{ $done ? '✓' : '○' }}</span></span>
      @else
        <a class="lt-link" href="{{ $tabUrl }}">{{ $localeLabels[$loc] ?? $loc }}
          <span class="lt-state {{ $done ? 'done' : 'todo' }}" aria-label="{{ $done ? '已完成' : '未完成' }}">{{ $done ? '✓' : '○' }}</span></a>
      @endif
    @endforeach
  </div>
@endif
<div class="card narrow">
  <h2>
    {{ $entity->exists ? '编辑实体' : '新建实体' }}
    <span class="badge archived ml-1">{{ $typeLabel }}</span>
    @if($entity->exists && $entity->status==='published')
      @if($type==='product')
        <a class="btn btn-sm" target="_blank" rel="noopener"
           href="{{ url('/products/'.(!empty($meta['core']) ? $entity->slug : '')) }}">在前台查看</a>
      @elseif($type==='service')
        <a class="btn btn-sm" target="_blank" rel="noopener"
           href="{{ url('/solutions/'.$entity->slug.'/') }}">在前台查看</a>
      @endif
    @endif
  </h2>

  <form method="post"
        action="{{ $entity->exists ? route('admin.entities.update',$entity) : route('admin.entities.store') }}">
    @csrf @if($entity->exists)@method('PUT')@endif
    <input type="hidden" name="trans" value="{{ $transLocale }}">
    @if($isDefault)
      <input type="hidden" name="type" value="{{ $type }}">
    @elseif(! $entity->exists)
      <input type="hidden" name="translation_group" value="{{ $anchor->translation_group }}">
    @endif

    <div class="form-grid">
      <div class="form-row"><label>名称 <span class="req">*</span></label>
        <input type="text" name="name" value="{{ old('name', $entity->name) }}" maxlength="255" required>
        @error('name')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <div class="form-row"><label><span class="label-with-tip">slug <span class="req">*</span>
        <x-admin-tip text="实体的稳定标识，小写字母 / 数字 / 连字符，如 industrial-coating；同站点下同类型唯一，建议创建后不再修改。"/></span></label>
        <input type="text" name="slug" value="{{ old('slug', $entity->slug) }}" maxlength="128"
               placeholder="lowercase-slug" required>
        @error('slug')<div class="field-err">{{ $message }}</div>@enderror
      </div>
    </div>

    @if($isDefault)
    <div class="form-grid">
      <div class="form-row"><label>状态 <span class="req">*</span></label>
        <select name="status">
          @foreach(['draft'=>'草稿','published'=>'已发布','archived'=>'已归档'] as $vk=>$vn)
            <option value="{{ $vk }}" @selected(old('status', $entity->status ?? 'draft')===$vk)>{{ $vn }}</option>
          @endforeach
        </select>
        @error('status')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <div class="form-row"><label>排序权重</label>
        <input type="number" name="sort_order" min="0" step="1"
               value="{{ old('sort_order', $entity->sort_order ?? 0) }}">
        @error('sort_order')<div class="field-err">{{ $message }}</div>@enderror
      </div>
    </div>
    @endif

    <div class="form-row"><label>摘要</label>
      <textarea name="summary" rows="2" maxlength="1000"
                placeholder="一句话概述，用于列表与 SEO description 回退">{{ old('summary', $entity->summary) }}</textarea>
      @error('summary')<div class="field-err">{{ $message }}</div>@enderror
    </div>

    <div class="form-row"><label>正文 / 详细描述</label>
      <textarea name="description" rows="6">{{ old('description', $entity->description) }}</textarea>
      @error('description')<div class="field-err">{{ $message }}</div>@enderror
    </div>

    {{-- 产品专属 --}}
    @if($isDefault && $isProduct)
      <h2 class="mt-2">产品设置</h2>
      <div class="form-row">
        <label class="checkline">
          <input type="hidden" name="meta_core" value="0">
          <input type="checkbox" name="meta_core" value="1"
                 @checked(old('meta_core', $meta['core'] ?? true))>
          <span class="label-with-tip">核心产品（拥有独立详情页并进入 sitemap / llms.txt）
            <x-admin-tip text="勾选后该产品有独立详情页 /products/slug 并进入公开 feed；不勾选则仅在产品列表以锚点形式出现。"/></span>
        </label>
      </div>
      <div class="form-grid">
        <div class="form-row"><label><span class="label-with-tip">所属产品线
          <x-admin-tip text="可选。填写已在组织资料中定义的产品线 slug；留空则产品在总览中平铺展示。"/></span></label>
          <input type="text" name="meta_line" value="{{ old('meta_line', $meta['line'] ?? '') }}" maxlength="128"
                 placeholder="留空 = 平铺">
          @error('meta_line')<div class="field-err">{{ $message }}</div>@enderror
        </div>
        <div class="form-row"><label>产品导语（tagline）</label>
          <input type="text" name="meta_tagline" value="{{ old('meta_tagline', $meta['tagline'] ?? '') }}" maxlength="255"
                 placeholder="留空则回退到摘要">
          @error('meta_tagline')<div class="field-err">{{ $message }}</div>@enderror
        </div>
      </div>
    @endif

    {{-- 组织专属：投影 metadata.company，Catalog::company() 依赖它 --}}
    @if($isDefault && $isOrg)
      @php $company = is_array($meta['company'] ?? null) ? $meta['company'] : []; @endphp
      <h2 class="mt-2">公司 / 品牌信息</h2>
      <div class="form-grid">
        <div class="form-row"><label>品牌名</label>
          <input type="text" name="org_brand" value="{{ old('org_brand', $company['brand'] ?? '') }}" maxlength="255">
          @error('org_brand')<div class="field-err">{{ $message }}</div>@enderror
        </div>
        <div class="form-row"><label>所属行业</label>
          <input type="text" name="org_industry" value="{{ old('org_industry', $company['industry'] ?? '') }}" maxlength="255">
          @error('org_industry')<div class="field-err">{{ $message }}</div>@enderror
        </div>
      </div>
      <div class="form-grid">
        <div class="form-row"><label>联系电话</label>
          <input type="text" name="org_phone" value="{{ old('org_phone', $company['phone'] ?? '') }}" maxlength="64">
          @error('org_phone')<div class="field-err">{{ $message }}</div>@enderror
        </div>
        <div class="form-row"><label>联系邮箱</label>
          <input type="email" name="org_email" value="{{ old('org_email', $company['email'] ?? '') }}" maxlength="255">
          @error('org_email')<div class="field-err">{{ $message }}</div>@enderror
        </div>
      </div>
      <div class="form-row"><label>公司地址</label>
        <input type="text" name="org_address" value="{{ old('org_address', $company['address'] ?? '') }}" maxlength="255">
        @error('org_address')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <p class="hint">公司名称取实体名称本身（保存时自动写入组织资料），是产品 / 服务目录页可渲染的前提。</p>
    @endif

    {{-- 地点专属 --}}
    @if($isDefault && $isLocation)
      <h2 class="mt-2">地点信息</h2>
      <div class="form-row"><label>地址</label>
        <input type="text" name="loc_address" value="{{ old('loc_address', $meta['address'] ?? '') }}" maxlength="255">
        @error('loc_address')<div class="field-err">{{ $message }}</div>@enderror
      </div>
      <div class="form-grid">
        <div class="form-row"><label>纬度 latitude</label>
          <input type="text" name="loc_latitude" value="{{ old('loc_latitude', $meta['latitude'] ?? '') }}" placeholder="-90 ~ 90">
          @error('loc_latitude')<div class="field-err">{{ $message }}</div>@enderror
        </div>
        <div class="form-row"><label>经度 longitude</label>
          <input type="text" name="loc_longitude" value="{{ old('loc_longitude', $meta['longitude'] ?? '') }}" placeholder="-180 ~ 180">
          @error('loc_longitude')<div class="field-err">{{ $message }}</div>@enderror
        </div>
      </div>
    @endif

    {{-- 服务专属 --}}
    @if($isDefault && $isService)
      <h2 class="mt-2">服务信息</h2>
      <div class="form-grid">
        <div class="form-row"><label>服务范围</label>
          <input type="text" name="svc_scope" value="{{ old('svc_scope', $meta['scope'] ?? '') }}" maxlength="255">
          @error('svc_scope')<div class="field-err">{{ $message }}</div>@enderror
        </div>
        <div class="form-row"><label>方案标题引导</label>
          <input type="text" name="svc_title_q" value="{{ old('svc_title_q', $meta['title_q'] ?? '') }}" maxlength="255">
          @error('svc_title_q')<div class="field-err">{{ $message }}</div>@enderror
        </div>
      </div>
    @endif

    @if($isDefault)
    {{-- 媒体：卡片图 + OG 分享图 --}}
    <h2 class="mt-2">图片</h2>
    <div class="form-grid">
      <div class="form-row"><label><span class="label-with-tip">卡片 / 封面图
        <x-admin-tip text="从媒体库选择，用于产品卡片等前台展示；也可先到媒体库上传。"/></span></label>
        <select name="card_image" class="select">
          <option value="">— 不更换 —</option>
          @foreach($mediaImages as $m)
            <option value="{{ $m->id }}">{{ basename($m->path) }}</option>
          @endforeach
        </select>
        @if(!empty($meta['image']))
          <label class="checkline mt-1"><input type="checkbox" name="card_image_clear" value="1"> 清除当前卡片图</label>
          <div class="hint mono small">当前：{{ $meta['image'] }}</div>
        @endif
      </div>
      <div class="form-row"><label><span class="label-with-tip">OG 分享图
        <x-admin-tip text="从媒体库选择，写入社交分享 og:image；留空回退到站点 Logo。"/></span></label>
        <select name="og_image" class="select">
          <option value="">— 默认（站点 Logo）—</option>
          @foreach($mediaImages as $m)
            <option value="{{ $m->id }}" @selected((string) old('og_image', $meta['og_image'] ?? '') === (string) $m->id)>{{ basename($m->path) }}</option>
          @endforeach
        </select>
        @if(!empty($meta['og_image']))
          <label class="checkline mt-1"><input type="checkbox" name="og_image_clear" value="1"> 清除自定义 OG 图</label>
        @endif
        @error('og_image')<div class="field-err">{{ $message }}</div>@enderror
      </div>
    </div>

    @if($entity->exists && $entity->published_at)
      <p class="hint mt-2">首次发布时间：{{ $entity->published_at->format('Y-m-d H:i') }}（由状态自动维护）</p>
    @endif
    @endif

    <div class="form-actions">
      <button class="btn btn-primary">保存</button>
      <a class="btn" href="{{ route('admin.entities.index', $type) }}">返回列表</a>
    </div>
  </form>
</div>
@endsection
