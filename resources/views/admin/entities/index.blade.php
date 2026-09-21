@extends('admin.layout')
@section('title','实体与图谱')
@section('page-desc','实体是 GEO 知识图谱与前台目录的正式资源：组织、产品、服务、人物、地点、主题。对外的产品 / 服务在此生产并发布后，由前台 /products、/solutions 及 Schema / GEO / Sitemap 统一呈现；内容（文章 / 单页）与实体相互独立。')

@section('page-actions')
  <div class="btn-row">
    <div class="dropdown">
      <button type="button" class="btn btn-sm btn-primary">＋ 新建实体 ▾</button>
      <div class="dropdown-menu">
        @foreach($typeNames as $tk=>$tn)
          <a href="{{ route('admin.entities.create',$tk) }}">{{ $tn }}</a>
        @endforeach
      </div>
    </div>
    <form class="form-inline" method="post" action="{{ route('admin.entities.seed') }}"
          onsubmit="return confirm('从内置 Example 示例种子生成 / 同步组织、产品、服务实体及其关系？该操作幂等，不会重复创建。')">
      @csrf
      <button class="btn btn-sm">载入示例实体</button>
    </form>
  </div>
@endsection

@section('content')
@php
  $statusMeta = [
    'published' => ['已发布', 'published'],
    'draft'     => ['草稿', 'draft'],
    'archived'  => ['已归档', 'archived'],
  ];
@endphp

<div class="filter-bar">
  <a class="tab {{ $tab==='all' ? 'on' : '' }}" href="{{ route('admin.entities.index','all') }}">
    全部 <span class="hint">({{ $typeCounts->sum() }})</span>
  </a>
  @foreach($typeNames as $tk=>$tn)
    <a class="tab {{ $tab===$tk ? 'on' : '' }}" href="{{ route('admin.entities.index',$tk) }}">
      {{ $tn }} <span class="hint">({{ (int) ($typeCounts[$tk] ?? 0) }})</span>
    </a>
  @endforeach
</div>

<div class="card">
  <form method="get" class="filter-bar" style="border:none;padding:0;margin-bottom:12px;">
    <input type="text" name="q" value="{{ $q }}" placeholder="搜索实体名称 / slug" style="max-width:260px;">
    <select name="status" class="select" style="max-width:140px;">
      <option value="">全部状态</option>
      @foreach($statusMeta as $sk=>[$sn])
        <option value="{{ $sk }}" @selected($fStatus===$sk)>{{ $sn }}</option>
      @endforeach
    </select>
    <button class="btn btn-sm">筛选</button>
  </form>

  <table class="tbl">
    <thead>
      <tr>
        <th class="w-220">名称</th>
        <th class="w-90">类型</th>
        <th class="w-190">slug</th>
        <th class="w-90">状态</th>
        <th class="w-80">关系数</th>
        <th class="w-74">排序</th>
        <th class="w-150">更新时间</th>
        <th class="actions w-300">操作</th>
      </tr>
    </thead>
    <tbody>
    @forelse($items as $e)
      @php [$statusLabel,$statusClass] = $statusMeta[$e->status] ?? [$e->status,'archived']; @endphp
      <tr>
        <td>
          <strong>{{ $e->name }}</strong>
          @if($e->type==='product' && !empty($e->metadata['core']))<span class="badge info ml-1">核心</span>@endif
        </td>
        <td><span class="badge archived">{{ $typeNames[$e->type] ?? $e->type }}</span></td>
        <td class="mono small">{{ $e->slug }}</td>
        <td><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
        <td class="small">{{ (int) ($relationCounts[$e->id] ?? 0) }}</td>
        <td class="small mono">{{ $e->sort_order }}</td>
        <td class="small mono nowrap">{{ optional($e->updated_at)->format('Y-m-d H:i') }}</td>
        <td class="actions">
          <a class="btn btn-sm" href="{{ route('admin.entities.edit',$e) }}">编辑</a>
          <a class="btn btn-sm" href="{{ route('admin.relations.index',['entity'=>$e->id]) }}">关系</a>
          @if($e->status==='published')
            <form class="form-inline" method="post" action="{{ route('admin.entities.unpublish',$e) }}">
              @csrf
              <button class="btn btn-sm">下架</button>
            </form>
          @else
            <form class="form-inline" method="post" action="{{ route('admin.entities.publish',$e) }}">
              @csrf
              <button class="btn btn-sm btn-primary">发布</button>
            </form>
          @endif
          @if($e->type==='product')
            <a class="btn btn-sm" target="_blank" rel="noopener"
               href="{{ url('/products/'.(!empty($e->metadata['core']) ? $e->slug : '')) }}">前台</a>
          @elseif($e->type==='service')
            <a class="btn btn-sm" target="_blank" rel="noopener"
               href="{{ url('/solutions/'.$e->slug.'/') }}">前台</a>
          @endif
          <form class="form-inline" method="post" action="{{ route('admin.entities.destroy',$e) }}"
                onsubmit="return confirm('删除实体「{{ $e->name }}」？其作为源 / 目标的关系会一并删除，此操作不可恢复。')">
            @csrf @method('DELETE')
            <button class="btn btn-sm btn-danger">删</button>
          </form>
        </td>
      </tr>
    @empty
      <tr><td colspan="8" class="empty-cell">
        当前类型还没有实体。可「新建实体」，或点右上角「载入示例实体」快速体验。
      </td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="pager">{{ $items->appends(request()->query())->links() }}</div>
</div>

<div class="card mt-2">
  <h2>说明</h2>
  <ul class="small" style="line-height:1.9;margin:0;padding-left:18px;">
    <li><strong>产品 / 服务上线</strong>：需先有一个「组织」实体（承载公司信息），再把产品 / 服务发布；标记为「核心」的产品拥有独立详情页并进入 sitemap / llms.txt。</li>
    <li><strong>实体与内容</strong>：实体驱动目录与知识图谱（/products、/solutions、geo.json）；文章 / 单页用于知识内容，二者不互相 fallback。</li>
    <li><strong>关系</strong>：组织 produces 产品、offers 服务、服务 uses 产品等有向关系在 <a href="{{ route('admin.relations.index') }}">实体关系</a> 中维护；两端实体均发布后该关系才进入 geo.json。</li>
  </ul>
</div>
@endsection
