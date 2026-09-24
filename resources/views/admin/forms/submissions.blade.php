@extends('admin.layout')
@section('title','表单提交记录')
@section('page-desc','完整表单提交事实（Submission）：保存全部业务字段原始结构与来源归因。「客户留言」是其向后兼容的业务投影；此处可查看每条提交的完整数据。')

@section('page-actions')
  <div class="btn-row">
    <a class="btn btn-sm" href="{{ route('admin.forms.index') }}">返回表单</a>
  </div>
@endsection

@section('content')
@if($scopeForm)
  <p class="small">当前仅显示表单「<strong>{{ $scopeForm->name }}</strong>」的提交。
    <a href="{{ route('admin.forms.submissions') }}">查看全部表单提交</a></p>
@endif

<div class="card">
  <table class="tbl">
    <thead>
      <tr>
        <th class="w-160">表单</th>
        <th class="w-80">语言</th>
        <th class="w-160">提交人</th>
        <th>联系方式</th>
        <th class="w-150">提交时间</th>
        <th class="w-90">详情</th>
      </tr>
    </thead>
    <tbody>
    @forelse($submissions as $s)
      @php
        $p = $s->payloadArray();
        $person = $p['name'] ?? $p['full_name'] ?? '—';
        $contact = collect([$p['phone'] ?? null, $p['email'] ?? null])->filter()->implode(' / ');
      @endphp
      <tr>
        <td class="small">{{ $s->form?->name ?? '—' }}</td>
        <td class="small">{{ $s->locale }}</td>
        <td class="small">{{ $person }}</td>
        <td class="small">{{ $contact ?: '—' }}</td>
        <td class="small mono nowrap">{{ optional($s->created_at)->format('Y-m-d H:i') }}</td>
        <td>
          <details>
            <summary class="btn btn-sm">查看</summary>
            <div class="card mt-1" style="min-width:320px;">
              <h3>完整字段</h3>
              <table class="tbl">
                <tbody>
                @foreach($p as $k => $v)
                  <tr>
                    <th class="w-140">{{ $k }}</th>
                    <td class="small">@if(is_array($v)){{ implode(', ', array_map('strval',$v)) }}@else{{ $v }}@endif</td>
                  </tr>
                @endforeach
                </tbody>
              </table>
              <h3 class="mt-1">来源归因</h3>
              <p class="small" style="line-height:1.8;">
                来源页：{{ $s->source_page ?: '—' }}<br>
                落地页：{{ $s->landing_url ?: '—' }}<br>
                外部来源：{{ $s->referer ?: '—' }}<br>
                UTM：{{ collect([$s->utm_source,$s->utm_medium,$s->utm_campaign])->filter()->implode(' / ') ?: '—' }}<br>
                设备：{{ $s->device_type }}
              </p>
            </div>
          </details>
        </td>
      </tr>
    @empty
      <tr><td colspan="6" class="empty-cell">还没有提交记录。</td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="pager">{{ $submissions->links() }}</div>
</div>
@endsection
