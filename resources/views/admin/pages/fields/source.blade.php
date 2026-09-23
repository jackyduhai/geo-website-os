@php
  $src = (array) ($cfg[$key] ?? []);
  $srcMode = $src['mode'] ?? 'all';
@endphp
<input type="hidden" name="field[{{ $key }}][mode]" id="src-mode-{{ $key }}"
       value="{{ $srcMode }}">
<div class="actions">
  @foreach($f['modes'] as $m)
    <button type="button"
            class="btn btn-sm {{ $srcMode === $m ? 'btn-primary' : '' }}"
            onclick="pickSource('{{ $key }}', '{{ $m }}', this)">{{ $m }}</button>
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
