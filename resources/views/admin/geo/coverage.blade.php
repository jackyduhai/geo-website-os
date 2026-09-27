@extends('admin.layout')
@section('title','实体覆盖')
@section('page-desc','当前站点每个公开实体按其类型应覆盖的关系 / 必备字段填全程度（知识资产齐备度，只读）。应覆盖项唯一来自 EntityCapabilityRegistry，本页不修改任何数据。')

@php
  $overallBadge = match($report['overall']) {
    'PASS'    => '<span class="badge ok">整体 PASS</span>',
    'WARNING' => '<span class="badge info">整体 WARNING</span>',
    'FAIL'    => '<span class="badge err">整体 FAIL</span>',
    default   => '<span class="badge archived">整体 N/A（空站 / 无应覆盖项）</span>',
  };
  $pct = fn($r) => $r === null ? 'N/A' : (round(($r ?? 0) * 100) . '%');
@endphp

@section('content')
<div class="grid g2">
  <div class="card">
    <div class="card-head">
      <h2>实体知识覆盖 {!! $overallBadge !!}</h2>
      <a class="btn btn-sm" href="{{ route('admin.geo.health') }}">GEO 健康</a>
    </div>
    <p class="muted mb-0">
      站点：<b>{{ $report['site']['name'] }}</b> · 语言：<b>{{ $report['site']['locale'] }}</b>。
      口径 = 已覆盖必备项 / 应覆盖必备项（齐备比率，非综合健康分）；建议项不计入分母。
    </p>
  </div>

  <div class="card">
    <div class="card-head"><h2>总览</h2></div>
    <div class="grid g3">
      <div class="stat"><div class="n mono">{{ $report['totals']['entities'] }}</div><div class="l">公开实体</div></div>
      <div class="stat"><div class="n mono">{{ $report['totals']['covered'] }}/{{ $report['totals']['required'] }}</div><div class="l">必备已覆盖 / 应覆盖</div></div>
      <div class="stat"><div class="n">{!! $overallBadge !!}</div><div class="l">齐备率 {{ $pct($report['totals']['ratio']) }}</div></div>
    </div>
  </div>
</div>

@if($report['blank'])
  <div class="alert alert-warn mt-3">
    当前站点还没有公开实体。这是合法的空站状态，不计为故障；录入实体并发布后，这里会显示逐类型、逐实体的覆盖齐备度。
  </div>
@else
  <div class="card mt-3">
    <div class="card-head"><h2>按类型</h2></div>
    <table class="tbl">
      <thead><tr><th>类型</th><th>公开实体</th><th>必备 已覆盖/应覆盖</th><th>齐备率</th><th>未填全实体</th></tr></thead>
      <tbody>
        @foreach($report['by_type'] as $t)
          <tr>
            <td>{{ $t['label'] }}</td>
            <td class="mono">{{ $t['entities'] }}</td>
            <td class="mono">{{ $t['covered'] }}/{{ $t['required'] }}</td>
            <td class="mono">{{ $pct($t['ratio']) }}</td>
            <td class="mono">{{ $t['incomplete'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="card mt-3">
    <div class="card-head"><h2>逐实体缺失项</h2></div>
    @php $incomplete = array_values(array_filter($report['entities'], fn($e) => count($e['gaps']) > 0)); @endphp
    @if($incomplete === [])
      <p class="muted mb-0">所有公开实体的必备关系与必备字段均已填全。</p>
    @else
      <div class="line-list">
        @foreach($incomplete as $e)
          <div class="line-item small">
            <b>{{ $e['name'] }}</b>
            <span class="muted">（{{ $e['type_label'] }} · 齐备 {{ $e['covered'] }}/{{ $e['required'] }}）</span>
            <div>
              @foreach($e['gaps'] as $g)
                <span class="badge archived">{{ $g['label'] }}</span>
              @endforeach
            </div>
            <a href="{{ $e['edit_url'] }}" class="small">去编辑 →</a>
          </div>
        @endforeach
      </div>
    @endif
  </div>
@endif
@endsection
