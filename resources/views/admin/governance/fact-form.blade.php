@extends('admin.layout')
@section('title', $fact->exists ? '编辑事实' : '新增事实')
@section('page-desc','事实用于 GEO 知识图谱（geo.json / AI 理解）；未核实的内容请留空并取消「对外公开」。')

@section('content')
<div class="alert alert-warn">
  Fact 用于 <strong>GEO 知识图谱（geo.json / AI 理解）</strong>，修改 Fact 不会直接改变网站前台页面显示的公司名称等内容；前台公司名、联系方式等请在「站点设置」中修改。
</div>
<div class="card narrow">
  <h2>{{ $fact->exists ? '编辑事实：'.$fact->label : '新增事实' }}</h2>
  <form method="post"
        action="{{ $fact->exists ? route('admin.facts.update',$fact) : route('admin.facts.store') }}">
    @csrf @if($fact->exists)@method('PUT')@endif
    <div class="form-grid">
      <div class="form-row"><label><span class="label-with-tip">分组 <span class="req">*</span><x-admin-tip text="事实分类标识，常用 company / capability / product / contact / compliance，可从下拉选或自填。"/></span></label>
        <input type="text" name="group" value="{{ old('group',$fact->group) }}" list="factGroups" placeholder="company / capability / product / contact / compliance">
        <datalist id="factGroups">
          @foreach($groups ?? [] as $g)<option value="{{ $g }}">@endforeach
        </datalist></div>
      <div class="form-row"><label><span class="label-with-tip">键 key <span class="req">*</span><x-admin-tip text="程序引用的英文标识，小写字母 / 下划线，全站唯一，如 established_year。"/></span></label>
        <input type="text" name="key" value="{{ old('key',$fact->key) }}" placeholder="如 established_year"></div>
      <div class="form-row"><label>展示名 <span class="req">*</span></label>
        <input type="text" name="label" value="{{ old('label',$fact->label) }}" placeholder="如：成立时间"></div>
      <div class="form-row"><label>单位</label>
        <input type="text" name="unit" value="{{ old('unit',$fact->unit) }}"></div>
    </div>
    <div class="form-row"><label><span class="label-with-tip">值 <x-admin-tip type="warning" text="未核实的内容留空并取消下方「对外公开」，保持待补，不要编造。"/></span></label>
      <textarea name="value" rows="3">{{ old('value',$fact->value) }}</textarea></div>
    <div class="form-grid">
      <div class="form-row"><label>来源</label>
        <input type="text" name="source" value="{{ old('source',$fact->source) }}"></div>
      <div class="form-row"><label>来源 URL</label>
        <input type="text" name="source_url" value="{{ old('source_url',$fact->source_url) }}"></div>
      <div class="form-row"><label>核验日期</label>
        <input type="date" name="reviewed_at" value="{{ old('reviewed_at', optional($fact->reviewed_at)->format('Y-m-d')) }}"></div>
      <div class="form-row"><label>下次复核</label>
        <input type="date" name="review_due" value="{{ old('review_due', optional($fact->review_due)->format('Y-m-d')) }}"></div>
      <div class="form-row"><label>排序</label>
        <input type="number" name="sort" value="{{ old('sort',$fact->sort ?? 0) }}"></div>
    </div>
    <label class="checkline mb-4">
      <input type="checkbox" name="is_public" value="1" @checked(old('is_public',$fact->is_public ?? true))>
      <span class="label-with-tip">对外公开 <x-admin-tip text="取消勾选表示待补 / 非公开，该事实不会输出到前台与 llms.txt。"/></span>
    </label>
    <button class="btn btn-primary">保存</button>
    <a class="btn" href="{{ route('admin.facts.index') }}">返回</a>
  </form>
</div>
@endsection
