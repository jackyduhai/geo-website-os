<select name="field[{{ $key }}]" class="select">
  @foreach($f['options'] as $optionValue => $optionLabel)
    {{-- options 支持两种形态：索引数组（['a','b']）时数字下标，元素值既是提交值也是显示；
         关联数组（['split'=>'左右分栏']）时 key 为提交值（受控英文枚举）、值为中文显示。
         必须保证提交值始终是英文 key，不能把中文 label 存进区块配置。 --}}
    @php
      if (is_int($optionValue)) {
          $optionValue = $optionLabel;
      }
    @endphp
    <option value="{{ $optionValue }}"
      @selected((string) ($cfg[$key] ?? '') === (string) $optionValue)>{{ $optionLabel }}</option>
  @endforeach
</select>
