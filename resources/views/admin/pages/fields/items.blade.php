@php
  $itemFields = $f['item_fields'] ?? [];
  $itemsVal = (array) ($cfg[$key] ?? []);
@endphp
<table class="tbl">
  <thead>
    <tr>
      @foreach($itemFields as $sf)<th>{{ $sf['label'] }}</th>@endforeach
      <th class="w-60"></th>
    </tr>
  </thead>
  <tbody id="repeat-{{ $key }}">
    @foreach($itemsVal as $ri => $rowItem)
      <tr class="repeat-row">
        @include('admin.pages._item_cells', [
          'key' => $key, 'ri' => $ri,
          'itemFields' => $itemFields, 'val' => $rowItem,
        ])
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
    @include('admin.pages._item_cells', [
      'key' => $key, 'ri' => '__IDX__',
      'itemFields' => $itemFields, 'val' => [],
    ])
    <td>
      <button type="button" class="btn btn-sm btn-danger"
              onclick="this.closest('tr').remove()">删</button>
    </td>
  </tr>
</template>
<div class="mt-1">
  <button type="button" class="btn btn-sm" onclick="addRepeat('{{ $key }}')">
    ＋ 添加一行
  </button>
</div>
