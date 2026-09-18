@extends('admin.layout')
@section('title','客户留言')
@section('page-desc','前台统一咨询表单提交的询盘都在这里，自动记录来源页、落地页、UTM 与设备，仅做轻量跟进状态管理。')

@section('content')
@php
  $tabs = ['new' => '待跟进', 'handled' => '已跟进', 'archived' => '已归档', '' => '全部'];
@endphp
<div class="filter-bar">
  @foreach($tabs as $key => $label)
    @php $cnt = $key === '' ? array_sum($counts) : ($counts[$key] ?? 0); @endphp
    <a class="tab {{ $status === $key ? 'on' : '' }}"
       href="{{ route('admin.inquiries.index', $key === '' ? ['status' => ''] : ['status' => $key]) }}">
      {{ $label }}（{{ $cnt }}）
    </a>
  @endforeach
</div>

<div class="card">
  <table class="tbl">
    <thead>
      <tr>
        <th>时间</th><th>称呼</th><th>电话</th><th>类型</th>
        <th>需求摘要</th><th>状态</th><th class="actions">跟进 / 操作</th>
      </tr>
    </thead>
    <tbody>
    @forelse($items as $inq)
      <tr>
        <td class="small mono nowrap">{{ $inq->created_at?->format('m-d H:i') }}</td>
        <td>
          {{ $inq->name }}
          @if($inq->company)<div class="small muted">{{ $inq->company }}</div>@endif
        </td>
        <td class="mono">{{ $inq->phone }}</td>
        <td>
          {{ $inq->demand_type }}
          @if($inq->monthly_use)<div class="small muted">月用量：{{ $inq->monthly_use }}</div>@endif
        </td>
        <td class="small w-300">{{ $inq->message }}
          <div class="small muted mt-1">
            来源页：{{ $inq->source_page ?: '—' }}
          </div>
          @php
            $utm = collect(['utm_source'=>'来源','utm_medium'=>'媒介','utm_campaign'=>'活动'])
              ->map(fn($lab,$k)=> $inq->$k ? $lab.'：'.$inq->$k : null)->filter()->implode(' / ');
          @endphp
          <div class="small muted">
            设备：{{ $inq->deviceLabel() }}
            @if($inq->landing_url) ｜ 落地页：{{ $inq->landing_url }} @endif
            @if($inq->referer) ｜ 上一站：{{ $inq->referer }} @endif
            @if($utm) ｜ {{ $utm }} @endif
          </div>
        </td>
        <td>
          @if($inq->status === 'new')
            <span class="badge gap">{{ $inq->statusLabel() }}</span>
          @else
            <span class="badge draft">{{ $inq->statusLabel() }}</span>
          @endif
          @if($inq->handle_note)<div class="small muted">备注：{{ $inq->handle_note }}</div>@endif
        </td>
        <td class="actions">
          <details>
            <summary class="btn btn-sm">跟进</summary>
            <form method="post" action="{{ route('admin.inquiries.handle',$inq) }}" class="mt-2 narrow">
              @csrf @method('PUT')
              <div class="form-row">
                <label>状态</label>
                <select name="status">
                  @foreach(\App\Models\Inquiry::STATUS_LABEL as $val => $lab)
                    <option value="{{ $val }}" @selected($inq->status === $val)>{{ $lab }}</option>
                  @endforeach
                </select>
              </div>
              <div class="form-row">
                <label>跟进备注</label>
                <textarea name="handle_note" rows="2" placeholder="沟通记录（选填）">{{ $inq->handle_note }}</textarea>
              </div>
              <button class="btn btn-sm btn-primary">保存</button>
            </form>
          </details>
          <form class="form-inline" method="post" action="{{ route('admin.inquiries.destroy',$inq) }}"
                onsubmit="return confirm('确定删除这条留言？')">@csrf @method('DELETE')
            <button class="btn btn-sm btn-danger">删</button>
          </form>
        </td>
      </tr>
    @empty
      <tr><td colspan="7" class="empty-cell">当前没有留言</td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="pager">{{ $items->links() }}</div>
</div>
@endsection
