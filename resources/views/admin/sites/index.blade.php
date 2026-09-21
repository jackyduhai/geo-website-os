@extends('admin.layout')
@section('title','站点管理')
@section('page-desc','站点是多站系统的租户根：每个站点拥有独立的内容、实体、SEO、主题、插件与设置。一个域名对应一个站点；仅超级管理员可在此新建、停用或删除站点。')

@section('page-actions')
  <a class="btn btn-sm btn-primary" href="{{ route('admin.sites.create') }}">＋ 新建站点</a>
@endsection

@section('content')
@php
  $statusMeta = [
    'active'      => ['运营中', 'ok'],
    'inactive'    => ['已停用', 'archived'],
    'maintenance' => ['维护中', 'gap'],
  ];
  $currentSlug = optional(\App\Support\SiteContext::currentSite())->slug;
@endphp

<div class="card">
  <div class="card-head">
    <h2>全部站点 <span class="hint">共 {{ $sites->count() }} 个</span></h2>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th class="w-200">站点</th>
        <th class="w-180">域名</th>
        <th class="w-120">标识 slug</th>
        <th class="w-90">状态</th>
        <th class="w-80">默认站</th>
        <th class="w-120">内容 / 实体</th>
        <th class="actions w-260">操作</th>
      </tr>
    </thead>
    <tbody>
    @forelse($sites as $site)
      @php [$statusLabel, $statusClass] = $statusMeta[$site->status] ?? [$site->status, 'archived']; @endphp
      <tr>
        <td>
          <strong>{{ $site->name }}</strong>
          @if($site->slug === $currentSlug)<span class="badge info ml-1">当前管理</span>@endif
        </td>
        <td class="mono small">{{ $site->domain ?: '—' }}</td>
        <td class="mono small">{{ $site->slug }}</td>
        <td><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
        <td>
          @if($site->is_default)<span class="badge published">默认</span>@else<span class="small">—</span>@endif
        </td>
        <td class="small">{{ $site->content_count }} / {{ $site->entity_count }}</td>
        <td class="actions">
          @if($site->slug !== $currentSlug)
            <form class="form-inline" method="post" action="{{ route('admin.sites.switch') }}">
              @csrf
              <input type="hidden" name="site_id" value="{{ $site->id }}">
              <button class="btn btn-sm">在此站管理</button>
            </form>
          @endif
          <a class="btn btn-sm" href="{{ route('admin.sites.edit', $site) }}">编辑</a>
          @if(!$site->is_default)
            <form class="form-inline" method="post" action="{{ route('admin.sites.default', $site) }}"
                  onsubmit="return confirm('将「{{ $site->name }}」设为默认站点？同一时间仅一个默认站，原默认站将被取消。')">
              @csrf
              <button class="btn btn-sm">设为默认</button>
            </form>
            <form class="form-inline" method="post" action="{{ route('admin.sites.destroy', $site) }}"
                  onsubmit="return confirm('删除站点「{{ $site->name }}」？该站仍有数据时会被系统阻止；此操作不可恢复。')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-danger">删</button>
            </form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="7" class="empty-cell">
        还没有站点。<a href="{{ route('admin.sites.create') }}">创建你的第一个站点 →</a>
      </td></tr>
    @endforelse
    </tbody>
  </table>
</div>

<div class="card mt-2">
  <h2>说明</h2>
  <ul class="small" style="line-height:1.9;margin:0;padding-left:18px;">
    <li><strong>默认站点</strong>：未匹配到域名（单站 / 开发模式）时的兜底站点，系统必须保留一个默认站，因此默认站不可删除、不可取消默认。</li>
    <li><strong>域名</strong>：生产环境按访问域名解析站点；停用（inactive）或维护（maintenance）状态的域名不会在前台被解析到。</li>
    <li><strong>删除保护</strong>：站点下仍有内容、实体、设置等任何业务数据时禁止删除，需先清空或迁移。</li>
    <li><strong>当前管理站点</strong>：用顶部切换器或「在此站管理」选择后，后台新建的内容 / 实体 / 设置都会归属该站点。</li>
  </ul>
</div>
@endsection
