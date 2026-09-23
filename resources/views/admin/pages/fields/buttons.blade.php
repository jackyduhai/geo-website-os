@php
  $buttonsVal = (array) ($cfg[$key] ?? []);
@endphp
<table class="tbl">
  <thead>
    <tr>
      <th>按钮文案</th>
      <th>链接</th>
      <th class="w-120">样式</th>
      <th class="w-60"></th>
    </tr>
  </thead>
  <tbody id="repeat-{{ $key }}">
    @foreach($buttonsVal as $bi => $btn)
      <tr class="repeat-row">
        <td><input type="text" class="select"
                   name="field[{{ $key }}][{{ $bi }}][label]"
                   value="{{ $btn['label'] ?? '' }}" placeholder="按钮文案"></td>
        <td><input type="text" class="select"
                   name="field[{{ $key }}][{{ $bi }}][url]"
                   value="{{ $btn['url'] ?? '' }}" placeholder="链接，可留空"></td>
        <td>
          <select class="select" name="field[{{ $key }}][{{ $bi }}][style]">
            @foreach(['primary' => '主要', 'secondary' => '次要', 'ghost' => '描边'] as $stk => $stl)
              <option value="{{ $stk }}"
                @selected(($btn['style'] ?? 'primary') === $stk)>{{ $stl }}</option>
            @endforeach
          </select>
        </td>
        <td>
          <button type="button" class="btn btn-sm btn-danger"
                  onclick="this.closest('tr').remove()">删</button>
        </td>
      </tr>
    @endforeach
  </tbody>
</table>
<template id="proto-{{ $key }}">
  <tr class="repeat-row">
    <td><input type="text" class="select"
               name="field[{{ $key }}][__IDX__][label]" placeholder="按钮文案"></td>
    <td><input type="text" class="select"
               name="field[{{ $key }}][__IDX__][url]" placeholder="链接，可留空"></td>
    <td>
      <select class="select" name="field[{{ $key }}][__IDX__][style]">
        <option value="primary">主要</option>
        <option value="secondary">次要</option>
        <option value="ghost">描边</option>
      </select>
    </td>
    <td>
      <button type="button" class="btn btn-sm btn-danger"
              onclick="this.closest('tr').remove()">删</button>
    </td>
  </tr>
</template>
<div class="mt-1">
  <button type="button" class="btn btn-sm" onclick="addRepeat('{{ $key }}')">
    ＋ 添加按钮
  </button>
</div>
