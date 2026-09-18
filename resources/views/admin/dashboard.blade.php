@extends('admin.layout')
@section('title','总览')
@section('page-desc','内容、留言、事实缺口与发布门禁的一屏总览。')

@section('content')
@php($newInquiries = \App\Models\Inquiry::where('status','new')->count())
<div class="grid g4">
  <div class="stat"><div class="n">{{ $stats['published'] }}</div><div class="l">已发布内容</div></div>
  <div class="stat"><div class="n">{{ $stats['draft'] }}</div><div class="l">草稿</div></div>
  <div class="stat"><div class="n">{{ $stats['product'] }}</div><div class="l">产品</div></div>
  <div class="stat"><div class="n">{{ $stats['article'] }}</div><div class="l">文章 / 新闻</div></div>
  <div class="stat"><div class="n">{{ $stats['category'] }}</div><div class="l">栏目</div></div>
  <a class="stat stat-link" href="{{ route('admin.inquiries.index',['status'=>'new']) }}">
    <div class="n">{{ $newInquiries }}</div><div class="l">待跟进留言</div></a>
  <a class="stat stat-link" href="{{ route('admin.facts.index',['gap'=>1]) }}">
    <div class="n">{{ $stats['factGap'] }}</div><div class="l">待补事实</div></a>
  <div class="stat"><div class="n">{{ $reviewDue->count() }}</div><div class="l">30 天内待复核</div></div>
</div>

@if($settingGaps)
  <div class="alert alert-warn">
    <b>站点信息待补全：</b>
    @foreach($settingGaps as $g)<span class="mr-2">· {{ $g }}</span>@endforeach
    <a href="{{ route('admin.settings.index','general') }}">去补全 →</a>
  </div>
@endif

<div class="grid g2">
  <div class="card">
    <div class="card-head"><h2>待跟进留言</h2>
      <a class="btn btn-sm" href="{{ route('admin.inquiries.index') }}">全部留言</a></div>
    @if($newInquiries > 0)
      <p class="mb-0">有 <b class="text-err">{{ $newInquiries }}</b> 条新留言待跟进，
        <a href="{{ route('admin.inquiries.index',['status'=>'new']) }}">立即处理 →</a></p>
    @else
      <p class="muted mb-0">暂无新留言。</p>
    @endif
  </div>

  <div class="card">
    <div class="card-head"><h2>复核到期（30 天内）</h2>
      <a class="btn btn-sm" href="{{ route('admin.contents.index','all') }}">内容管理</a></div>
    @forelse($reviewDue as $c)
      <div class="line-list">
        <a href="{{ route('admin.contents.edit',$c) }}">{{ $c->title }}</a>
        <span class="small muted @if($c->review_due->isPast()) text-err @endif">
          {{ $c->review_due->format('Y-m-d') }}
        </span>
      </div>
    @empty
      <p class="muted mb-0">近期没有到期内容。</p>
    @endforelse
  </div>
</div>

@if($gateFailures)
  <div class="card">
    <div class="card-head"><h2><span class="label-with-tip">GEO 门禁历史遗留问题 <x-admin-tip text="这些内容发布于门禁规则上线前，当前仍在线但不满足现行发布标准，建议逐条补齐后重新发布。"/></span></h2></div>
    <table class="tbl">
      <thead><tr><th>内容</th><th>问题</th></tr></thead>
      <tbody>
      @foreach($gateFailures as $gf)
        <tr>
          <td class="nowrap"><a href="{{ route('admin.contents.edit',$gf['content']) }}">{{ $gf['content']->title }}</a></td>
          <td class="small text-err">{{ implode('；', $gf['errors']) }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
@endif

<div class="card">
  <div class="card-head"><h2>最近操作日志</h2></div>
  <table class="tbl">
    <thead><tr><th class="w-160">时间</th><th class="w-120">操作人</th><th>动作</th></tr></thead>
    <tbody>
    @forelse($recentLogs as $l)
      <tr>
        <td class="small mono">{{ $l->created_at->format('m-d H:i') }}</td>
        <td class="small">{{ optional($l->user)->name ?? '系统' }}</td>
        <td class="small">{{ $l->summary ?: $l->action }}</td>
      </tr>
    @empty
      <tr><td colspan="3" class="empty-cell">暂无日志</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
