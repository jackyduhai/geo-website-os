@extends('admin.layout')
@section('title','实体关系')
@section('page-desc','关系是知识图谱的有向边：源实体 ──关系类型──▶ 目标实体。仅可在同一站点的实体之间建立；关系本身没有发布状态，只有当源、目标实体均已发布时，该关系才会出现在 /geo.json 机器可读图谱中。')

@section('page-actions')
  <div class="btn-row">
    <a class="btn btn-sm btn-primary" href="{{ route('admin.relations.create') }}">＋ 新建关系</a>
  </div>
@endsection

@section('content')
@php
  $grouped = $entities->groupBy('type');
@endphp

<div class="card">
  <form method="get" class="filter-bar" style="border:none;padding:0;margin-bottom:12px;">
    <select name="entity" class="select" style="max-width:260px;">
      <option value="0">全部实体</option>
      @foreach($entityTypeNames as $tk=>$tn)
        @foreach(($grouped[$tk] ?? collect()) as $opt)
          <option value="{{ $opt->id }}" @selected($fEntity===$opt->id)>{{ $tn }} · {{ $opt->name }}</option>
        @endforeach
      @endforeach
    </select>
    <select name="type" class="select" style="max-width:220px;">
      <option value="">全部关系类型</option>
      @foreach($typeLabels as $rk=>$rl)
        <option value="{{ $rk }}" @selected($fType===$rk)>{{ $rl }}</option>
      @endforeach
    </select>
    <button class="btn btn-sm">筛选</button>
  </form>

  <table class="tbl">
    <thead>
      <tr>
        <th>源实体（from）</th>
        <th class="w-150">关系类型</th>
        <th>目标实体（to）</th>
        <th class="w-74">排序</th>
        <th class="w-90">更新时间</th>
        <th class="actions w-150">操作</th>
      </tr>
    </thead>
    <tbody>
    @forelse($items as $r)
      @php $from=$r->fromEntity; $to=$r->toEntity; @endphp
      <tr>
        <td>
          @if($from)
            <strong>{{ $from->name }}</strong>
            <span class="badge archived ml-1">{{ $entityTypeNames[$from->type] ?? $from->type }}</span>
            <div class="mono small">{{ $from->slug }}</div>
          @else
            <span class="badge err">实体已删除</span>
          @endif
        </td>
        <td><span class="badge info">{{ $typeLabels[$r->relation_type] ?? $r->relation_type }}</span></td>
        <td>
          @if($to)
            <strong>{{ $to->name }}</strong>
            <span class="badge archived ml-1">{{ $entityTypeNames[$to->type] ?? $to->type }}</span>
            <div class="mono small">{{ $to->slug }}</div>
          @else
            <span class="badge err">实体已删除</span>
          @endif
        </td>
        <td class="small mono">{{ $r->sort_order }}</td>
        <td class="small mono nowrap">{{ optional($r->updated_at)->format('Y-m-d H:i') }}</td>
        <td class="actions">
          <a class="btn btn-sm" href="{{ route('admin.relations.edit',$r) }}">编辑</a>
          <form class="form-inline" method="post" action="{{ route('admin.relations.destroy',$r) }}"
                onsubmit="return confirm('删除该实体关系？仅删除关系本身，不会删除源 / 目标实体。')">
            @csrf @method('DELETE')
            <button class="btn btn-sm btn-danger">删</button>
          </form>
        </td>
      </tr>
    @empty
      <tr><td colspan="6" class="empty-cell">
        当前站点还没有实体关系。请先在「实体与图谱」创建实体，再点右上角「新建关系」连接它们。
      </td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="pager">{{ $items->appends(request()->query())->links() }}</div>
</div>

<div class="card mt-2">
  <h2>关系类型说明</h2>
  <ul class="small" style="line-height:1.9;margin:0;padding-left:18px;">
    <li><strong>produces</strong>：组织生产某产品；<strong>offers</strong>：组织提供某服务；<strong>uses</strong>：某服务 / 方案使用某产品；<strong>located_in</strong>：主体位于某地点；<strong>related_to</strong>：通用关联。</li>
    <li><strong>方向性</strong>：关系为有向边，源 → 目标；反向是另一条关系（如组织 produces 产品后，产品 related_to 组织仍可单独建立）。</li>
    <li><strong>自关系 / 反向关系允许</strong>；源、目标、类型完全相同的重复关系不允许。</li>
    <li><strong>多站点</strong>：只能连接当前站点内的实体；删除实体时，其相关关系由数据库外键级联自动清除。</li>
    <li><strong>可见性</strong>：关系不单独发布；源与目标实体都处于「已发布」时，该边才进入 /geo.json，草稿 / 归档实体会让关系暂时不对外输出。</li>
  </ul>
</div>
@endsection
