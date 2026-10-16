@foreach($itemFields as $sf)
  @php
    $sk = $sf['key'];
    $sv = is_array($val) ? ($val[$sk] ?? '') : '';
    $iname = "field[{$key}][{$ri}][{$sk}]";
  @endphp
  <td>
    @switch($sf['type'])
      @case('checkbox')
        <input type="hidden" name="{{ $iname }}" value="0">
        <input type="checkbox" name="{{ $iname }}" value="1" @checked(! empty($sv))>
        @break

      @case('media')
      @case('number')
        <input type="number" name="{{ $iname }}" class="select w-90" value="{{ $sv }}">
        @break

      @case('select')
        <select name="{{ $iname }}" class="select">
          @foreach($sf['options'] as $optionValue => $optionLabel)
            {{-- 索引数组时元素值即提交值；关联数组时 key 为受控英文提交值、值为中文显示（同 fields/select）。 --}}
            @php
              if (is_int($optionValue)) {
                  $optionValue = $optionLabel;
              }
            @endphp
            <option value="{{ $optionValue }}" @selected((string) $sv === (string) $optionValue)>{{ $optionLabel }}</option>
          @endforeach
        </select>
        @break

      @case('textarea')
        <textarea name="{{ $iname }}" class="textarea" rows="2">{{ $sv }}</textarea>
        @break

      @default
        <input type="text" name="{{ $iname }}" class="select" value="{{ $sv }}">
    @endswitch
  </td>
@endforeach
