@extends('admin.layout')
@section('title','对接同步日志')
@section('page-desc','每条 GEOFlow 推送都留痕：新建 / 更新 / 跳过 / 冲突 / 拒绝，含原始报文。')

@section('content')
<div class="card">
  <h2>GEOFlow 同步日志</h2>
  <table class="tbl">
    <thead><tr><th>时间</th><th>方向</th><th>动作</th><th>外部 ID</th><th>状态</th><th>信息</th></tr></thead>
    <tbody>
    @forelse($logs as $log)
      <tr>
        <td class="mono small nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
        <td class="small">{{ $log->direction==='in' ? '接收' : '回写' }}</td>
        <td class="small">{{ $log->action }}</td>
        <td class="mono small">{{ $log->external_id }}</td>
        <td>
          @if($log->status==='ok')<span class="badge ok">正常</span>
          @elseif($log->status==='warn')<span class="badge gap">警告</span>
          @else<span class="badge err">错误</span>@endif
        </td>
        <td class="small muted">
          <details><summary>{{ mb_substr((string)$log->message,0,60) ?: '查看报文' }}</summary>
            <pre class="preview mh-300 mt-2">{{ json_encode($log->payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>
          </details>
        </td>
      </tr>
    @empty
      <tr><td colspan="6" class="empty-cell">暂无同步记录</td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="pager">{{ $logs->links() }}</div>
</div>
@endsection
