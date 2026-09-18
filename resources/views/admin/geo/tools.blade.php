@extends('admin.layout')
@section('title','抓取产出与对接')
@section('page-desc','sitemap.xml / llms.txt / robots.txt / feed.xml 动态生成，JSON-LD 统一产出；GEOFlow 为后期可选对接。')

@section('content')
<div class="grid g2">
  <div>
    <div class="card">
      <h2><span class="label-with-tip">GEO 产出 <x-admin-tip text="sitemap.xml / llms.txt / robots.txt / feed.xml 均为动态生成，内容变更即生效，无需手工同步。"/></span></h2>
      <table class="tbl">
        <thead><tr><th>文件</th><th>说明</th><th class="actions">操作</th></tr></thead>
        <tbody>
        @foreach($feeds as $f)
          <tr>
            <td class="mono">{{ $f['name'] }}</td>
            <td class="small muted">{{ $f['desc'] }}</td>
            <td class="actions">
              <a class="btn btn-sm" href="{{ route('admin.geo.preview',$f['kind']) }}">预览</a>
              <a class="btn btn-sm" href="{{ $f['url'] }}" target="_blank">新窗打开 ↗</a>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2><span class="label-with-tip">页面结构化数据 <x-admin-tip text="每个页面自动注入 JSON-LD（Organization / WebSite / Breadcrumb / Article / Product / FAQPage），由 SchemaBuilder 从内容与事实库统一生成，不可手写，避免口径冲突。发布内容后，可用 Google 富媒体结果测试与 Schema Markup Validator 校验。"/></span></h2>
      <p class="btn-row mb-0">
        <a class="btn btn-sm" href="https://search.google.com/test/rich-results" target="_blank">Google 富媒体测试 ↗</a>
        <a class="btn btn-sm" href="https://validator.schema.org/" target="_blank">Schema 校验器 ↗</a>
      </p>
    </div>
  </div>

  <div>
    <div class="card">
      <h2><span class="label-with-tip">GEOFlow 对接状态 <x-admin-tip type="help" text="后期可选对接：开关与 Token 在「搜索与 AI → GEOFlow 对接设置」中管理；官网不依赖 GEOFlow，关闭后一切照常。"/></span></h2>
      <p>推送接收：
        @if($syncEnabled)<span class="badge ok">已启用</span>@else<span class="badge draft">未启用（默认）</span>@endif
      </p>
      <p>主动拉取：
        @if($pullEnabled)<span class="badge ok">已启用</span>@else<span class="badge draft">未启用（预留）</span>@endif
      </p>
      <p class="btn-row">
        <a class="btn btn-sm" href="{{ route('admin.settings.index','sync') }}">前往对接设置</a>
        <a class="btn btn-sm" href="{{ route('admin.geo.sync-logs') }}">查看同步日志</a>
      </p>

      <hr class="hr-soft">
      <h2 class="subhead"><span class="label-with-tip">接口地址 <x-admin-tip text="供 GEOFlow 侧配置；请求头需带 Authorization: Bearer &lt;Token&gt;，Content-Type: application/json。"/></span></h2>
      <table class="tbl">
        <tbody>
          <tr><td class="small">健康检查</td><td class="mono small">GET {{ url('/api/v1/health') }}</td></tr>
          <tr><td class="small">推送/更新</td><td class="mono small">POST {{ url('/api/v1/geoflow/contents') }}</td></tr>
          <tr><td class="small">门禁预检</td><td class="mono small">POST {{ url('/api/v1/geoflow/check') }}</td></tr>
          <tr><td class="small">状态查询</td><td class="mono small">GET {{ url('/api/v1/geoflow/contents/{external_id}') }}</td></tr>
          <tr><td class="small">下架</td><td class="mono small">POST {{ url('/api/v1/geoflow/unpublish') }}</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
