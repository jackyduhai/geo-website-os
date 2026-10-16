@php
  $src = (array) ($cfg[$key] ?? []);
  $srcMode = $src['mode'] ?? 'all';
  // 模式中文显示（提交值 / hidden input 始终为受控英文枚举，不改数据源解析）：
  //   all 全部 | line 按产品线/分组 | picked 手动指定 | current 当前栏目 |
  //   related 关联推荐 | latest 最新内容。
  $sourceModeLabels = [
      'all'     => '全部',
      'line'    => '按产品线/分组',
      'picked'  => '手动指定',
      'current' => '当前栏目',
      'related' => '关联推荐',
      'latest'  => '最新内容',
  ];
@endphp
<input type="hidden" name="field[{{ $key }}][mode]" id="src-mode-{{ $key }}"
       value="{{ $srcMode }}">
<div class="actions">
  @foreach($f['modes'] as $m)
    <button type="button"
            class="btn btn-sm {{ $srcMode === $m ? 'btn-primary' : '' }}"
            onclick="pickSource('{{ $key }}', '{{ $m }}', this)">{{ $sourceModeLabels[$m] ?? $m }}</button>
  @endforeach
</div>
<div class="source-line-{{ $key }} mt-1"
     style="{{ $srcMode === 'line' ? '' : 'display:none' }}">
  <input type="text" class="select" name="field[{{ $key }}][line]"
         value="{{ $src['line'] ?? '' }}" placeholder="产品线 / 分组 slug">
</div>
<div class="source-picked-{{ $key }} mt-1"
     style="{{ $srcMode === 'picked' ? '' : 'display:none' }}">
  <textarea name="field[{{ $key }}][ids]" class="textarea mono" rows="2"
            placeholder="ID 列表，逗号分隔">{{ implode(',', (array) ($src['ids'] ?? [])) }}</textarea>
</div>
