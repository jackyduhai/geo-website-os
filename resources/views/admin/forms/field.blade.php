@extends('admin.layout')
@section('title', $field->exists ? '编辑字段' : '添加字段')

@section('content')
@php
  $action = $field->exists
      ? route('admin.forms.fields.update', [$form, $field])
      : route('admin.forms.fields.store', $form);
  $val = function (string $loc, string $key) use ($rows) {
      $r = $rows[$loc] ?? null;
      return $r?->{$key};
  };
@endphp

<div class="card">
  <form method="post" action="{{ $action }}">
    @csrf
    @if($field->exists) @method('PUT') @endif

    <h2>结构（跨语言一致，修改会同步到所有语言版本）</h2>
    <div class="form-grid">
      <div class="form-row">
        <label>字段类型 <span class="req">*</span></label>
        <select name="type" id="fieldType">
          @foreach($fieldTypes as $tk => $tc)
            <option value="{{ $tk }}" @selected(old('type', $field->type)===$tk)>{{ $tc['label'] }}（{{ $tk }}）</option>
          @endforeach
        </select>
      </div>
      <div class="form-row">
        <label>字段 name（英文标识，提交键） <span class="req">*</span></label>
        <input type="text" name="name" value="{{ old('name', $field->name) }}" maxlength="80" required>
      </div>
      <div class="form-row">
        <label>附加校验规则（可选，如 max:100，多个用 | 分隔）</label>
        <input type="text" name="validation" value="{{ old('validation', $field->validation) }}" maxlength="160">
      </div>
      <label class="form-check">
        <input type="checkbox" name="required" value="1" @checked(old('required', $field->required))>
        必填（单个确认框必填即必须勾选）
      </label>
    </div>

    <h2 class="mt-2">各语言文案</h2>
    @foreach($locales as $loc)
      <div class="card mt-1" style="box-shadow:none;border:1px solid var(--border);">
        <h3>{{ $loc === 'zh-CN' ? '中文（zh-CN）' : 'English（en）' }}</h3>
        <div class="form-grid">
          <div class="form-row">
            <label>label</label>
            <input type="text" name="translations[{{ $loc }}][label]"
                   value="{{ old('translations.'.$loc.'.label', $val($loc,'label')) }}" maxlength="160">
          </div>
          <div class="form-row">
            <label>placeholder</label>
            <input type="text" name="translations[{{ $loc }}][placeholder]"
                   value="{{ old('translations.'.$loc.'.placeholder', $val($loc,'placeholder')) }}" maxlength="160">
          </div>
          <div class="form-row">
            <label>帮助说明（help text）</label>
            <input type="text" name="translations[{{ $loc }}][help_text]"
                   value="{{ old('translations.'.$loc.'.help_text', $val($loc,'help_text')) }}" maxlength="255">
          </div>
          <div class="form-row optionsRow">
            <label>选项（仅下拉 / 单选 / 多勾选需要，每行一个）</label>
            <textarea name="translations[{{ $loc }}][options]" rows="3">{{ old('translations.'.$loc.'.options', $val($loc,'options')) }}</textarea>
          </div>
        </div>
      </div>
    @endforeach

    <div class="btn-row mt-2">
      <button class="btn btn-primary" type="submit">{{ $field->exists ? '保存字段' : '添加字段' }}</button>
      <a class="btn" href="{{ route('admin.forms.edit',$form) }}">返回表单</a>
    </div>
  </form>
</div>

<script nonce="{{ $cspNonce ?? '' }}">
// 非选项类字段隐藏「选项」输入（仅视觉，后端仍按类型裁决）。
(function(){
  var sel = document.getElementById('fieldType');
  var optionTypes = @json(array_keys(array_filter($fieldTypes, function($t){ return ! empty($t['options']); })));
  function refresh(){
    var show = optionTypes.indexOf(sel.value) !== -1;
    document.querySelectorAll('.optionsRow').forEach(function(r){ r.style.display = show ? '' : 'none'; });
  }
  sel.addEventListener('change', refresh); refresh();
})();
</script>
@endsection
