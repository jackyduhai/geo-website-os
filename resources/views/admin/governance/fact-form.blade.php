@extends('admin.layout')
@section('title', $fact->exists ? '编辑事实' : '新增事实')
@section('page-desc','事实用于 GEO 知识图谱（geo.json / AI 理解）；未核实的内容请留空并取消「对外公开」。')

@section('content')
<div class="alert alert-warn">
  Fact 用于 <strong>GEO 知识图谱（geo.json / AI 理解）</strong>，修改 Fact 不会直接改变网站前台页面显示的公司名称等内容；前台公司名、联系方式等请在「站点设置」中修改。
</div>

@if($fact->exists)
<div class="alert alert-info">
  <strong>多语言</strong>：当前编辑的是
  <code>{{ $fact->locale }}</code> 行。语义身份 <code>{{ $fact->key }}</code> 跨语言一致，
  各语言的「展示名 / 值」各自独立；下方「共享属性」改一次即对所有语言生效。
  <br>
  <strong>英文站点不会回退到中文</strong> —— 缺 <code>en</code> 翻译时，该事实不会出现在 <code>/en/geo.json</code>。
</div>
@endif

<div class="card narrow">
  <h2>{{ $fact->exists ? '编辑事实：'.$fact->label : '新增事实' }}</h2>
  <form method="post"
        action="{{ $fact->exists ? route('admin.facts.update',$fact) : route('admin.facts.store') }}">
    @csrf @if($fact->exists)@method('PUT')@endif

    @if($fact->exists)
      <div class="form-row"><label>当前语言行</label>
        <input type="text" value="{{ $fact->locale }}" disabled>
        <div class="small muted">
          翻译组 <code>{{ $fact->translation_group }}</code>（由 key 自动派生，运营无需维护）
        </div></div>
    @else
      <div class="form-row"><label>语言</label>
        <select name="locale">
          @foreach($locales as $lc)
            <option value="{{ $lc }}" @selected(old('locale', 'zh-CN') === $lc)>{{ $lc }}</option>
          @endforeach
        </select></div>
    @endif

    <div class="form-grid">
      <div class="form-row"><label><span class="label-with-tip">分组 <span class="req">*</span><x-admin-tip text="事实分类标识，常用 company / capability / product / contact / compliance，可从下拉选或自填。"/></span></label>
        <input type="text" name="group" value="{{ old('group',$fact->group) }}" list="factGroups" placeholder="company / capability / product / contact / compliance">
        <datalist id="factGroups">
          @foreach($groups ?? [] as $g)<option value="{{ $g }}">@endforeach
        </datalist></div>
      <div class="form-row"><label><span class="label-with-tip">键 key <span class="req">*</span><x-admin-tip text="事实的语义身份，跨语言恒定（如 FACT-COMPANY-NAME）。程序引用、内容 fact_refs、GEO 节点都只认它。翻译组由它自动派生。"/></span></label>
        <input type="text" name="key" value="{{ old('key',$fact->key) }}" placeholder="如 FACT-COMPANY-NAME"></div>
    </div>

    <div class="form-row"><label>展示名 <span class="req">*</span>
      <span class="small muted">（当前语言：{{ $fact->exists ? $fact->locale : 'zh-CN' }}）</span></label>
      <input type="text" name="label" value="{{ old('label',$fact->label) }}" placeholder="如：成立时间"></div>
    <div class="form-row"><label><span class="label-with-tip">值 <x-admin-tip type="warning" text="未核实的内容留空并取消下方「对外公开」，保持待补，不要编造。"/></span></label>
      <textarea name="value" rows="3">{{ old('value',$fact->value) }}</textarea></div>

    <h3 class="mt-6 mb-2">共享属性<small class="muted">（对所有语言生效）</small></h3>
    <div class="form-grid">
      <div class="form-row"><label>单位</label>
        <input type="text" name="unit" value="{{ old('unit',$fact->unit) }}"></div>
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

@if($fact->exists)
  @php
    $have = $siblings->pluck('locale')->all();
    $missingLocales = array_values(array_diff($locales, $have));
  @endphp
  <div class="card narrow">
    <h2>各语言现状</h2>
    <table class="tbl">
      <thead><tr><th>语言</th><th>展示名</th><th>值</th><th class="actions">操作</th></tr></thead>
      <tbody>
      @foreach($siblings as $row)
        <tr>
          <td class="mono">{{ $row->locale }}</td>
          <td class="small">{{ $row->label }}</td>
          <td class="small">{{ mb_substr((string)$row->value, 0, 60) }}</td>
          <td class="actions">
            <a class="btn btn-sm" href="{{ route('admin.facts.edit',$row) }}">编辑该语言</a>
          </td>
        </tr>
      @endforeach
      @if($missingLocales !== [])
        <tr>
          <td colspan="4" class="small">
            <form method="post" action="{{ route('admin.facts.translations.store',$fact) }}" class="form-inline">
              @csrf
              <span class="muted">未翻译：</span>
              <select name="locale">
                @foreach($missingLocales as $lc)<option value="{{ $lc }}">{{ $lc }}</option>@endforeach
              </select>
              <input type="text" name="label" placeholder="展示名（该语言）" required>
              <input type="text" name="value" placeholder="值（该语言）">
              <button class="btn btn-sm btn-primary">补录翻译</button>
            </form>
          </td>
        </tr>
      @endif
      </tbody>
    </table>
    <p class="small muted">
      删除本条会<strong>连带删除所有语言行</strong>（避免「中文已删、英文仍在」的口径泄漏）。
    </p>
  </div>
@endif
@endsection
