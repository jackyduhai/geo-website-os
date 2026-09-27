@extends('admin.layout')
@section('title','GEO 健康')
@section('page-desc','当前站点公开内容 / 实体 / SEO·GEO 输出的语义健康只读概览（OG、落地页、JSON-LD、noindex、图谱边）。')

@php
  $statusBadge = fn(string $s) => match($s) {
    'PASS'    => '<span class="badge ok">PASS</span>',
    'WARNING' => '<span class="badge info">WARNING</span>',
    'FAIL'    => '<span class="badge err">FAIL</span>',
    default   => '<span class="badge archived">N/A</span>',
  };
  $overallBadge = match($report['overall']) {
    'PASS'    => '<span class="badge ok">整体 PASS</span>',
    'WARNING' => '<span class="badge info">整体 WARNING</span>',
    'FAIL'    => '<span class="badge err">整体 FAIL</span>',
    default   => '<span class="badge archived">整体 N/A（空站）</span>',
  };
@endphp

@section('content')
<div class="grid g2">
  <div class="card">
    <div class="card-head">
      <h2>GEO 语义健康 {!! $overallBadge !!}</h2>
      <a class="btn btn-sm" href="{{ route('admin.geo.tools') }}">抓取产出 / Sitemap</a>
    </div>
    <p class="muted mb-0">
      站点：<b>{{ $report['site']['name'] }}</b> · 语言：<b>{{ $report['site']['locale'] }}</b>。
      本页为只读聚合，不修改任何数据；问题项提供到对应编辑页的链接。
    </p>
  </div>

  <div class="card">
    <div class="card-head"><h2>概览</h2></div>
    <div class="grid g4">
      @foreach($report['checks'] as $c)
        <div class="stat">
          <div class="n">{!! $statusBadge($c['status']) !!}</div>
          <div class="l">{{ $c['label'] }}</div>
        </div>
      @endforeach
    </div>
  </div>
</div>

@if($report['blank'])
  <div class="alert alert-warn mt-3">
    当前站点还没有公开内容或实体（0 公开实体 / 0 公开内容）。这是合法的空站状态，
    不计为故障；完成首次运行向导或录入内容后，这里会显示真实的语义健康检查结果。
  </div>
@endif

<div class="grid g2 mt-3">
  @foreach($report['checks'] as $c)
    <div class="card">
      <div class="card-head">
        <h2>{{ $c['label'] }} {!! $statusBadge($c['status']) !!}</h2>
      </div>

      @if($c['key'] === 'missing_og')
        <table class="tbl">
          <tbody>
            <tr><td>公开资源总数</td><td class="mono">{{ $c['counts']['public_total'] }}</td></tr>
            <tr><td>已显式 SEO 覆盖</td><td class="mono">{{ $c['counts']['explicit'] }}</td></tr>
            <tr><td>OG 图回退站点 logo</td><td class="mono">{{ $c['counts']['image_fallback'] }}</td></tr>
            <tr><td>缺少 OG 描述</td><td class="mono">{{ $c['counts']['missing_description'] }}</td></tr>
          </tbody>
        </table>
      @elseif($c['key'] === 'public_url')
        <table class="tbl">
          <tbody>
            <tr><td>公开产品实体</td><td class="mono">{{ $c['counts']['public_products'] }}</td></tr>
            <tr><td>非核心产品（无独立页，符合预期）</td><td class="mono">{{ $c['counts']['non_core_no_page'] }}</td></tr>
            <tr><td class="text-err">应有落地页却未解析到 URL</td><td class="mono text-err">{{ $c['counts']['missing_public_url'] }}</td></tr>
          </tbody>
        </table>
      @elseif($c['key'] === 'jsonld')
        <table class="tbl">
          <thead><tr><th>页型</th><th>应产出</th><th>已产出</th></tr></thead>
          <tbody>
            @foreach($c['counts'] as $pageType => $t)
              <tr>
                <td>{{ $pageType }}</td>
                <td class="mono">{{ $t['expected'] }}</td>
                <td class="mono">{{ $t['emitted'] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @elseif($c['key'] === 'noindex')
        <table class="tbl">
          <tbody>
            <tr><td class="text-err">已发布却被标记 noindex（需确认是否有意）</td><td class="mono text-err">{{ $c['counts']['published_but_noindex'] }}</td></tr>
          </tbody>
        </table>
      @elseif($c['key'] === 'edges')
        <table class="tbl">
          <tbody>
            <tr><td>Entity → Entity 边</td><td class="mono">{{ $c['counts']['entity_to_entity'] }}</td></tr>
            <tr><td>Content → Entity 边</td><td class="mono">{{ $c['counts']['content_to_entity'] }}</td></tr>
            <tr><td>孤立公开实体（未挂任何边）</td><td class="mono">{{ $c['counts']['orphan_entities'] }}</td></tr>
            @if(!empty($c['counts']['by_type']))
              <tr><td>按关系类型</td><td class="mono">@foreach($c['counts']['by_type'] as $rt => $n){{ $rt }}:{{ $n }} @endforeach</td></tr>
            @endif
          </tbody>
        </table>
      @endif

      @if(!empty($c['affected']))
        <div class="line-list mt-2">
          @foreach($c['affected'] as $a)
            <div class="line-item small">
              <b>{{ $a['label'] }}</b>
              <span class="muted">（{{ $a['type'] }}）</span>
              <div class="text-err">{{ $a['reason'] }}</div>
              @if(!empty($a['url']))<a href="{{ $a['url'] }}" class="small">去编辑 →</a>@endif
            </div>
          @endforeach
        </div>
      @elseif($c['status'] !== 'N/A')
        <p class="muted mb-0 mt-2">未发现问题。</p>
      @endif
    </div>
  @endforeach
</div>
@endsection
