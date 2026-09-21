@extends('admin.layout')
@section('title','SEO 覆盖')
@section('page-desc','SEO 覆盖（SeoMeta）是标题、描述、Canonical、社交分享图与抓取指令的显式覆盖层，分站点级 / 内容级 / 实体级三种作用域。未设置的字段一律由系统按继承链自动解析；这里不重复实现兜底逻辑，最终结果与前台、Schema、GEO 共用同一个解析器。')

@section('page-actions')
  <div class="btn-row">
    <div class="dropdown">
      <button type="button" class="btn btn-sm btn-primary">＋ 新建 SEO 覆盖 ▾</button>
      <div class="dropdown-menu">
        <a class="dropdown-item" href="{{ route('admin.seo-metas.create', ['scope'=>'site']) }}">站点级（整站默认）</a>
        <a class="dropdown-item" href="{{ route('admin.seo-metas.create', ['scope'=>'content']) }}">内容级（文章 / 单页）</a>
        <a class="dropdown-item" href="{{ route('admin.seo-metas.create', ['scope'=>'entity']) }}">实体级（产品 / 服务 / 组织…）</a>
      </div>
    </div>
  </div>
@endsection

@section('content')
<div class="card">
  <form method="get" class="filter-bar" style="border:none;padding:0;margin-bottom:12px;">
    <select name="scope" class="select" style="max-width:200px;" onchange="this.form.submit()">
      <option value="all" @selected($scope==='all')>全部作用域</option>
      @foreach($scopeLabels as $sk=>$sn)
        <option value="{{ $sk }}" @selected($scope===$sk)>{{ $sn }}</option>
      @endforeach
    </select>
    <input type="text" name="q" class="select" style="max-width:260px;" value="{{ $q }}" placeholder="搜索标题 / 描述">
    <button class="btn btn-sm">筛选</button>
  </form>

  <table class="tbl">
    <thead>
      <tr>
        <th class="w-120">作用域</th>
        <th>绑定对象</th>
        <th>显式标题 / 描述</th>
        <th class="w-90">抓取指令</th>
        <th class="w-130">更新时间</th>
        <th class="actions w-150">操作</th>
      </tr>
    </thead>
    <tbody>
    @forelse($items as $m)
      <tr>
        <td>
          @if($m->isSiteLevel())
            <span class="badge info">{{ $scopeLabels['site'] }}</span>
          @elseif($m->isContentLevel())
            <span class="badge published">{{ $scopeLabels['content'] }}</span>
          @else
            <span class="badge published">{{ $scopeLabels['entity'] }}</span>
          @endif
        </td>
        <td>
          @if($m->isSiteLevel())
            <strong>{{ optional($m->site)->name ?? '当前站点' }}</strong>
            <div class="small">整站默认（每站仅一条）</div>
          @elseif($m->isContentLevel())
            @if($m->content)
              <strong>{{ $m->content->title ?: '(无标题内容)' }}</strong>
              <span class="badge archived ml-1">{{ $m->content->type }}</span>
              <div class="mono small">{{ $m->content->slug }} · {{ $m->content->status }}</div>
            @else
              <span class="badge err">内容已删除</span>
            @endif
          @else
            @if($m->entity)
              <strong>{{ $m->entity->name }}</strong>
              <span class="badge archived ml-1">{{ $entityTypeLabels[$m->entity->type] ?? $m->entity->type }}</span>
              <div class="mono small">{{ $m->entity->slug }} · {{ $m->entity->status }}</div>
            @else
              <span class="badge err">实体已删除</span>
            @endif
          @endif
        </td>
        <td class="small">
          @if($m->title)
            <div><strong>{{ $m->title }}</strong></div>
          @else
            <div class="empty-cell">标题：继承</div>
          @endif
          @if($m->description)
            <div class="small">{{ \Illuminate\Support\Str::limit($m->description, 80) }}</div>
          @else
            <div class="empty-cell small">描述：继承</div>
          @endif
        </td>
        <td>
          @if($m->noindex)<span class="badge err">noindex</span>@endif
          @if($m->nofollow)<span class="badge err">nofollow</span>@endif
          @if(!$m->noindex && !$m->nofollow)<span class="badge published">可索引</span>@endif
        </td>
        <td class="small mono nowrap">{{ optional($m->updated_at)->format('Y-m-d H:i') }}</td>
        <td class="actions">
          <a class="btn btn-sm" href="{{ route('admin.seo-metas.edit',$m) }}">编辑</a>
          <form class="form-inline" method="post" action="{{ route('admin.seo-metas.destroy',$m) }}"
                onsubmit="return confirm('删除该 SEO 覆盖？删除后标题 / 描述 / Canonical / OG / 抓取指令将恢复为自动解析（继承），不会删除绑定的内容或实体。')">
            @csrf @method('DELETE')
            <button class="btn btn-sm btn-danger">删</button>
          </form>
        </td>
      </tr>
    @empty
      <tr><td colspan="6" class="empty-cell">
        当前站点还没有 SEO 覆盖。不创建任何覆盖时，系统会按「内容 / 实体字段 → 站点 → 系统」自动解析标题、描述、Canonical 与 OG；需要对某个页面单独定制时，点右上角「新建 SEO 覆盖」。
      </td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="pager">{{ $items->appends(request()->query())->links() }}</div>
</div>

<div class="card mt-2">
  <h2>三种作用域与继承</h2>
  <ul class="small" style="line-height:1.9;margin:0;padding-left:18px;">
    <li><strong>站点级</strong>：整站默认，每个站点仅一条；作为内容 / 实体未显式设置时的回退。</li>
    <li><strong>内容级</strong>：绑定一篇文章 / 单页；标题默认取内容标题、描述取摘要。</li>
    <li><strong>实体级</strong>：绑定一个产品 / 服务 / 组织 / 人物 / 地点 / 主题实体；标题默认取实体名、描述取摘要 / 正文。</li>
    <li><strong>内容与实体是平行资源</strong>（架构冻结）：一条覆盖只能绑定其中之一，不能同时绑定，内容 SEO 也不会回退读取实体数据。</li>
    <li><strong>留空 = 继承</strong>：单个字段留空即按解析链回退；<strong>Canonical 留空 = 自动生成</strong>，填写后以自定义为准。</li>
  </ul>
</div>
@endsection
