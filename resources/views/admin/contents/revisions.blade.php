@extends('admin.layout')
@section('title','版本记录')
@section('page-desc','每次发布自动生成快照，可查看当时的正文与 GEO 数据。')

@section('content')
<div class="card">
  <h2>版本记录：{{ $content->title }}</h2>
  <table class="tbl">
    <thead><tr><th class="w-200">时间</th><th class="w-150">操作人</th><th>摘要</th><th class="actions w-120">查看</th></tr></thead>
    <tbody>
    @forelse($revisions as $r)
      <tr>
        <td class="mono small">{{ $r->created_at->format('Y-m-d H:i') }}</td>
        <td class="small">{{ $r->user->name ?? '系统' }}</td>
        <td class="small muted">{{ mb_substr(strip_tags($r->snapshot['body_md'] ?? ''),0,80) }}</td>
        <td class="actions">
          <details>
            <summary class="btn btn-sm">展开</summary>
            <pre class="preview mh-400 mt-2">{{ json_encode($r->snapshot, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>
          </details>
        </td>
      </tr>
    @empty
      <tr><td colspan="4" class="empty-cell">暂无版本快照</td></tr>
    @endforelse
    </tbody>
  </table>
  <p class="mt-3 mb-0"><a class="btn" href="{{ route('admin.contents.edit',$content) }}">返回编辑</a></p>
</div>
@endsection
