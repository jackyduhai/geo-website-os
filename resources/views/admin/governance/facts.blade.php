@extends('admin.layout')
@section('title','事实库')
@section('page-desc','GEO 知识图谱事实源：公开事实用于 geo.json 与 AI 理解；未核实内容请保持「待补」，不要编造。')
@section('page-actions')
  <a class="btn btn-sm" href="{{ route('admin.facts.index') }}">全部</a>
  <a class="btn btn-sm" href="{{ route('admin.facts.index',['gap'=>1]) }}">只看待补</a>
  <a class="btn btn-primary btn-sm" href="{{ route('admin.facts.create') }}">+ 新增事实</a>
@endsection

@section('content')
<div class="alert alert-warn">
  Fact 用于 <strong>GEO 知识图谱（geo.json / AI 理解）</strong>，修改 Fact 不会直接改变网站前台页面显示的公司名称等内容；前台公司名、联系方式等请在「站点设置」中修改。
</div>

<div class="alert alert-info">
  <strong>多语言</strong>：一行 = 一条事实（按 <code>key</code> 聚合），下表列出各语言的当前值。
  缺翻译的语言会标「未翻译」——<strong>英文站点不会回退到中文</strong>，
  所以未翻译的事实在 <code>/en/geo.json</code> 里不会出现。请在「编辑」里补录。
</div>

<div class="card">
  <table class="tbl">
    <thead>
      <tr>
        <th>分组</th>
        <th>条目（语义 key）</th>
        @foreach($locales as $lc)<th>{{ $lc }}</th>@endforeach
        <th>来源</th>
        <th>公开</th>
        <th>复核到期</th>
        <th class="actions">操作</th>
      </tr>
    </thead>
    <tbody>
    @forelse($facts as $g)
      @php($a = $g['anchor'])
      <tr>
        <td class="small">{{ $a->group }}</td>
        <td>
          <strong>{{ $a->label }}</strong>
          <div class="small muted mono">{{ $a->key }}</div>
        </td>
        @foreach($locales as $lc)
          @php($row = $g['rows'][$lc] ?? null)
          <td class="small">
            @if($row)
              {{ mb_substr((string)$row->value, 0, 48) }}
              <div class="small muted">{{ mb_substr((string)$row->label, 0, 24) }}</div>
            @else
              <span class="badge gap">未翻译</span>
            @endif
          </td>
        @endforeach
        <td class="small muted">{{ $a->source }}</td>
        <td>@if($a->is_public)<span class="badge ok">公开</span>@else<span class="badge gap">待补</span>@endif</td>
        <td class="small mono nowrap">{{ optional($a->review_due)->format('Y-m-d') ?: '—' }}</td>
        <td class="actions">
          <a class="btn btn-sm" href="{{ route('admin.facts.edit',$a) }}">编辑</a>
          <form class="form-inline" method="post" action="{{ route('admin.facts.destroy',$a) }}"
                onsubmit="return confirm('删除该事实？将同时删除各语言行。')">@csrf @method('DELETE')
            <button class="btn btn-sm btn-danger">删</button></form>
        </td>
      </tr>
    @empty
      <tr><td colspan="10" class="muted small">暂无事实。点击右上角「+ 新增事实」开始。</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
