@extends('admin.layout')
@section('title','编辑页面文案')
@section('page-desc','规范页固定位置的导语、段落、话术插槽；改完即时生效，留空保存可恢复默认文案。下方标注了该插槽对应的前台页面与位置。')

@section('content')
<div class="scope-bar">
  <div><b>{{ $def['label'] }}</b>（<span class="mono">{{ $key }}</span>）—
    <a href="{{ $def['url'] }}" target="_blank">{{ $def['url'] }} ↗</a>
    @if(!empty($def['location']))<span class="muted">｜{{ $def['location'] }}</span>@endif
  </div>
</div>

<div class="card">
  <form method="post" action="{{ route('admin.narrative.update',$key) }}">
    @csrf @method('PUT')

    <div class="form-row">
      <label><span class="label-with-tip">页头导语 / 摘要 <span class="req">*</span><x-admin-tip text="展示在页面标题下方，同时用于 meta description 与 AI 摘要；留空并保存即恢复默认文案。"/></span></label>
      <textarea name="summary" rows="3" required>{{ $summary }}</textarea>
      @if($defaultSummary)
        <details class="mt-2">
          <summary class="small muted">查看默认导语</summary>
          <pre class="preview preset mt-2">{{ $defaultSummary }}</pre>
        </details>
      @endif
    </div>

    @if(!empty($def['has_body']))
      <div class="form-row">
        <label><span class="label-with-tip">正文 <x-admin-tip text="支持 Markdown，可留空；留空时前台使用内置默认正文。"/></span></label>
        @include('admin.partials.md-editor', ['mdUid'=>'narrativeMd','mdName'=>'body','mdValue'=>$body,'mdRows'=>14])
        @if($defaultBody)
          <details class="mt-2">
            <summary class="small muted">查看默认正文</summary>
            <pre class="preview preset mt-2">{{ $defaultBody }}</pre>
          </details>
        @endif
      </div>
    @endif

    <div class="form-actions">
      <button class="btn btn-primary">保存文案</button>
      <a class="btn" href="{{ route('admin.narrative.index') }}">返回列表</a>
    </div>
  </form>
</div>

@if($row)
  <div class="card">
    <h2><span class="label-with-tip">恢复默认 <x-admin-tip type="warning" text="删除当前自定义内容，前台该插槽回到内置默认文案，操作不可撤销。"/></span></h2>
    <form class="form-inline" method="post" action="{{ route('admin.narrative.reset',$key) }}"
          onsubmit="return confirm('确认恢复默认文案？')">@csrf @method('DELETE')
      <button class="btn btn-danger">恢复默认文案</button>
    </form>
  </div>
@endif
@endsection
