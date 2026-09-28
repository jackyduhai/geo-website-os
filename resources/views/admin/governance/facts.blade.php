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
<div class="card">
  <table class="tbl">
    <thead><tr><th>分组</th><th>条目</th><th>当前值</th><th>来源</th><th>公开</th><th>复核到期</th><th class="actions">操作</th></tr></thead>
    <tbody>
    @foreach($facts as $f)
      <tr>
        <td class="small">{{ $f->group }}</td>
        <td><strong>{{ $f->label }}</strong><div class="small muted mono">{{ $f->key }}</div></td>
        <td class="small">{{ mb_substr((string)$f->value,0,60) }}</td>
        <td class="small muted">{{ $f->source }}</td>
        <td>@if($f->is_public)<span class="badge ok">公开</span>@else<span class="badge gap">待补</span>@endif</td>
        <td class="small mono nowrap">{{ optional($f->review_due)->format('Y-m-d') ?: '—' }}</td>
        <td class="actions">
          <a class="btn btn-sm" href="{{ route('admin.facts.edit',$f) }}">编辑</a>
          <form class="form-inline" method="post" action="{{ route('admin.facts.destroy',$f) }}"
                onsubmit="return confirm('删除该事实？')">@csrf @method('DELETE')
            <button class="btn btn-sm btn-danger">删</button></form>
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
</div>
@endsection
