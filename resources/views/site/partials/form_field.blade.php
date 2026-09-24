{{-- 单个表单字段渲染（P-STEP 18H-2）。接收 $field（FormField）、$formId。
     结构由 FormField 配置驱动；所有用户回填值经 {{ }} 转义。 --}}
@php
  $fieldId = $formId . '-' . $field->name;
  $opts = $field->optionsArray();
  $isMultiCheckbox = $field->type === 'checkbox' && $opts;
  $inputName = $isMultiCheckbox ? $field->name . '[]' : $field->name;
  $wide = in_array($field->type, ['textarea','select'], true) || $isMultiCheckbox;
@endphp

<div class="lf-field {{ $wide ? 'lf-full' : '' }}">
@if($field->type === 'hidden')
  <input type="hidden" name="{{ $field->name }}" value="{{ old($field->name) }}">

@elseif($field->type === 'checkbox' && ! $opts)
  {{-- 单个确认 / consent 框 --}}
  <label class="lf-check">
    <input type="checkbox" name="{{ $field->name }}" value="1"
           @checked(old($field->name))
           @if($field->required) required aria-required="true" @endif>
    <span>{{ $field->label }}@if($field->required)<span class="req">*</span>@endif</span>
  </label>

@else
  <label for="{{ $fieldId }}">{{ $field->label }}@if($field->required)<span class="req">*</span>@endif</label>

  @if($field->type === 'textarea')
    <textarea id="{{ $fieldId }}" name="{{ $field->name }}" rows="3"
              placeholder="{{ $field->placeholder }}"
              @if($field->required) required aria-required="true" @endif>{{ old($field->name) }}</textarea>

  @elseif($field->type === 'select')
    <select id="{{ $fieldId }}" name="{{ $field->name }}"
            @if($field->required) required aria-required="true" @endif>
      <option value="" disabled @selected(! old($field->name))>{{ $field->placeholder ?: __('ui.form_type_placeholder') }}</option>
      @foreach($opts as $opt)
        <option value="{{ $opt }}" @selected(old($field->name) === $opt)>{{ $opt }}</option>
      @endforeach
    </select>

  @elseif($field->type === 'radio')
    <div class="lf-options" role="radiogroup" aria-label="{{ $field->label }}">
      @foreach($opts as $opt)
        <label class="lf-option">
          <input type="radio" name="{{ $field->name }}" value="{{ $opt }}"
                 @checked(old($field->name) === $opt)
                 @if($field->required) required @endif>
          <span>{{ $opt }}</span>
        </label>
      @endforeach
    </div>

  @elseif($isMultiCheckbox)
    <div class="lf-options">
      @php $checked = (array) old($field->name, []); @endphp
      @foreach($opts as $opt)
        <label class="lf-option">
          <input type="checkbox" name="{{ $inputName }}" value="{{ $opt }}"
                 @checked(in_array($opt, $checked, true))>
          <span>{{ $opt }}</span>
        </label>
      @endforeach
    </div>

  @else
    {{-- text / email / tel / number / date / url --}}
    <input id="{{ $fieldId }}" type="{{ \App\Support\Forms\FieldTypeRegistry::input($field->type) }}"
           name="{{ $field->name }}" value="{{ old($field->name) }}"
           placeholder="{{ $field->placeholder }}"
           @if($field->type === 'tel') inputmode="tel" @endif
           @if($field->required) required aria-required="true" @endif
           @if($field->help_text) aria-describedby="{{ $fieldId }}-help" @endif>
  @endif

  @if($field->help_text)<p class="lf-help" id="{{ $fieldId }}-help">{{ $field->help_text }}</p>@endif
  @error($field->name)<p class="lf-err">{{ $message }}</p>@enderror
@endif
</div>
