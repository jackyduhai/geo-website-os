@extends('admin.layout')
@section('title','页面文案')
@section('page-desc','规范页（解决方案 / 产品 / 关于 / 合作等）固定位置的导语、段落、话术插槽在此维护，改完即时生效，可一键恢复默认。')

@section('content')
@foreach($groups as $g)
  <div class="card">
    <h2>{{ $g['group'] }}</h2>
    <table class="tbl">
      <thead><tr><th class="wp-32">页面位置</th><th class="wp-26">对应前台区域</th><th class="wp-10">状态</th><th class="actions wp-20">编辑文案</th></tr></thead>
      <tbody>
      @foreach($g['items'] as $slot)
        <tr>
          <td><strong>{{ $slot['label'] }}</strong><div class="small muted mono">{{ $slot['key'] }}</div></td>
          <td class="small">
            @if($slot['location'])<div class="muted">{{ $slot['location'] }}</div>@endif
            <a href="{{ $slot['url'] }}" target="_blank">{{ $slot['url'] }} ↗</a>
          </td>
          <td>
            @if($slot['customized'])<span class="badge ok">已自定义</span>
            @else<span class="badge draft">默认</span>@endif
          </td>
          <td class="actions">
            <a class="btn btn-sm btn-primary" href="{{ route('admin.narrative.edit',$slot['key']) }}">编辑</a>
            @if($slot['customized'])
              <form class="form-inline" method="post" action="{{ route('admin.narrative.reset',$slot['key']) }}"
                    onsubmit="return confirm('恢复为默认文案？当前自定义内容将删除。')">@csrf @method('DELETE')
                <button class="btn btn-sm">恢复默认</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
@endforeach
@endsection
