@extends('admin.layout')
@section('title','编辑区块 · '.$type->label)
@section('page-desc','按字段编辑区块内容；内容以结构化数据保存，由系统注册渲染器生成页面，不在数据库存储任意 HTML。')

@section('content')
@php
  $cfg = array_merge($type->defaultContent, $block->cfg());
@endphp

<form method="post" class="card"
      action="{{ route('admin.pages.updateBlock', [$page, $block]) }}">
  @csrf
  @method('PUT')

  <div class="form-grid">
    @foreach($type->fields as $f)
      @php
        $key = $f['key'];
      @endphp
      <div class="form-row">
        <label class="{{ ! empty($f['required']) ? 'req' : '' }}">{{ $f['label'] }}</label>

        @include('admin.pages.fields.'.$f['type'], [
          'f' => $f, 'cfg' => $cfg, 'key' => $key,
          'page' => $page, 'block' => $block,
        ])

        @error('field.'.$key)<div class="field-err">{{ $message }}</div>@enderror
      </div>
    @endforeach
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-sm btn-primary">保存区块</button>
    <a class="btn btn-sm" href="{{ route('admin.pages.composer', $page) }}">取消</a>
  </div>
</form>

<script>
  const repeatCounters = {};
  function addRepeat(key) {
    const tpl = document.getElementById('proto-' + key);
    const tbody = document.getElementById('repeat-' + key);
    if (repeatCounters[key] === undefined) {
      repeatCounters[key] = tbody.querySelectorAll('.repeat-row').length;
    }
    const idx = repeatCounters[key]++;
    const tr = tpl.content.firstElementChild.cloneNode(true);
    tr.innerHTML = tr.innerHTML.split('__IDX__').join(idx);
    tbody.appendChild(tr);
  }
  function pickSource(key, mode, btn) {
    document.getElementById('src-mode-' + key).value = mode;
    btn.parentElement.querySelectorAll('button').forEach(b => b.classList.remove('btn-primary'));
    btn.classList.add('btn-primary');
    document.querySelector('.source-line-' + key).style.display = mode === 'line' ? '' : 'none';
    document.querySelector('.source-picked-' + key).style.display = mode === 'picked' ? '' : 'none';
  }
</script>
@endsection
