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
          'mediaPicker' => $mediaPicker ?? collect(),
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

{{-- C026：区块编辑页共享的媒体选择弹窗（本页所有顶层 media 字段共用一个）。
     数据由 PageController::editBlock 注入（当前站点最近 24 个图片 + 已选兜底），
     不新增路由 / AJAX；点击媒体仅把数字 ID 回填到既有 name="field[<key>]" 输入。 --}}
<style>
  .media-picker-field { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
  .media-picker-preview { flex-basis:100%; }
  .media-picker-preview-card { display:inline-flex; gap:10px; align-items:center; margin-top:8px;
    padding:8px; border:1px solid var(--line,#e2e8f0); border-radius:8px; background:#f8fafc; }
  .media-picker-thumb { width:64px; height:64px; display:inline-flex; align-items:center; justify-content:center;
    background:#fff; border:1px solid var(--line,#e2e8f0); border-radius:6px; overflow:hidden; }
  .media-picker-thumb img { width:100%; height:100%; object-fit:cover; }
  .media-picker-meta { display:flex; flex-direction:column; gap:2px; }
  .media-picker-dialog { width:min(760px,92vw); max-height:84vh; border:1px solid var(--line,#e2e8f0);
    border-radius:12px; padding:16px; }
  .media-picker-dialog::backdrop { background:rgba(15,23,42,.45); }
  .media-picker-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
  .media-picker-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px;
    overflow:auto; max-height:60vh; }
  .media-picker-item { display:flex; flex-direction:column; gap:4px; align-items:flex-start; text-align:left;
    padding:8px; border:1px solid var(--line,#e2e8f0); border-radius:8px; background:#fff; cursor:pointer; }
  .media-picker-item:hover { border-color:var(--primary,#2563eb); box-shadow:0 0 0 1px var(--primary,#2563eb); }
  .media-picker-item .media-picker-thumb { width:100%; height:96px; }
  .media-picker-item .media-picker-name { font-size:12px; word-break:break-all; }
  /* C026b：重复行（logo_cloud items[].media_id）紧凑媒体单元格 */
  .media-picker-cell { display:flex; flex-direction:column; gap:6px; }
  .media-picker-cell-row { display:flex; flex-wrap:nowrap; gap:4px; align-items:center; }
  .media-picker-cell-row .media-picker-input { width:72px; }
  .media-picker-cell-preview { display:block; }
  .media-picker-cell-preview .media-picker-preview-card { margin-top:0; padding:4px; gap:6px; }
  .media-picker-thumb-sm { width:40px; height:40px; flex:0 0 auto; }
</style>

<dialog id="media-picker-dialog" class="media-picker-dialog" aria-label="选择媒体">
  <div class="media-picker-head">
    <h3 style="margin:0">选择媒体</h3>
    <button type="button" class="btn btn-sm" data-media-close>关闭</button>
  </div>
  @if(($mediaPicker ?? collect())->isNotEmpty())
    <div class="media-picker-grid" role="list">
      @foreach($mediaPicker as $m)
        <button type="button" class="media-picker-item" role="listitem"
                data-media-id="{{ $m->id }}"
                data-media-name="{{ $m->original_name }}"
                data-media-url="{{ $m->isImage() ? $m->url() : '' }}"
                data-media-mime="{{ $m->mime }}"
                data-media-alt="{{ $m->alt ?? '' }}">
          @if($m->isImage())
            <span class="media-picker-thumb"><img src="{{ $m->url() }}" alt="{{ $m->alt ?? '' }}"></span>
          @else
            <span class="media-picker-thumb mono small">{{ $m->mime }}</span>
          @endif
          <span class="media-picker-name">{{ $m->original_name }}</span>
          <span class="small muted">ID {{ $m->id }}</span>
        </button>
      @endforeach
    </div>
    <p class="small muted" style="margin:10px 0 0">仅显示最近 24 个图片及当前已选；更多请点「在媒体库管理 / 上传更多（新窗口）」。</p>
  @else
    <p class="muted small">当前站点还没有可选的图片媒体。请先在新窗口打开
      <a href="{{ route('admin.media.index') }}" target="_blank" rel="noopener">媒体库</a> 上传后再选择。
    </p>
  @endif
</dialog>

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

  // ===== C026 / C026b 媒体选择器（纯前端：只把所选媒体的数字 ID 回填进既有数字输入，
  // 不发起任何请求、不改变字段名或提交值；清除即置空，后端空值转 null）。
  // 同时支持顶层字段（.media-picker-field，#media-input-<key>）与 C026b 重复行
  // （.media-picker-cell，每个 logo_cloud 行一个）。点击按钮时用最近的绑定容器
  // （closest）定位目标输入与预览，因此 addRepeat 动态克隆出来的新行无需单独绑定，
  // 每行操作只影响所属行，不会串值。 =====
  (function () {
    const dialog = document.getElementById('media-picker-dialog');
    let currentHolder = null;

    function holderInput(holder) {
      return holder ? holder.querySelector('input.media-picker-input') : null;
    }
    function holderPreview(holder) {
      return holder ? holder.querySelector('[data-media-preview]') : null;
    }

    function openPicker(holder) {
      currentHolder = holder;
      if (!dialog || !holder) return;
      if (typeof dialog.showModal === 'function') dialog.showModal();
      else dialog.setAttribute('open', '');
    }
    function closePicker() { if (dialog && dialog.open) dialog.close(); }

    // 用 DOM API 构建预览卡片（文件名等经 textContent 写入，避免 innerHTML 拼接带来的 XSS）。
    function buildCard(item, id) {
      const card = document.createElement('span');
      card.className = 'media-picker-preview-card';
      const thumb = document.createElement('span');
      thumb.className = 'media-picker-thumb';
      const url = item ? item.getAttribute('data-media-url') : '';
      if (url) {
        const img = document.createElement('img');
        img.src = url;
        img.alt = item ? item.getAttribute('data-media-alt') : '';
        thumb.appendChild(img);
      } else {
        thumb.classList.add('mono', 'small');
        thumb.textContent = item ? item.getAttribute('data-media-mime') : '';
      }
      card.appendChild(thumb);
      const meta = document.createElement('span');
      meta.className = 'media-picker-meta';
      const name = document.createElement('span');
      name.className = 'media-picker-name';
      name.textContent = item ? item.getAttribute('data-media-name') : '';
      const idLine = document.createElement('span');
      idLine.className = 'small muted';
      idLine.textContent = 'ID ' + id;
      meta.appendChild(name);
      meta.appendChild(idLine);
      card.appendChild(meta);
      return card;
    }
    function setPreview(holder, item) {
      const box = holderPreview(holder);
      if (!box) return;
      box.innerHTML = '';
      const input = holderInput(holder);
      const id = item ? item.getAttribute('data-media-id') : (input ? input.value : '');
      if (!id) return;
      box.appendChild(buildCard(item, id));
    }
    function notifyInput(input) {
      if (!input) return;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function clearHolder(holder) {
      const input = holderInput(holder);
      if (input) { input.value = ''; notifyInput(input); }
      const box = holderPreview(holder);
      if (box) box.innerHTML = '';
    }

    document.addEventListener('click', function (e) {
      const openBtn = e.target.closest('[data-media-open]');
      if (openBtn) {
        e.preventDefault();
        openPicker(openBtn.closest('.media-picker-field, .media-picker-cell'));
        return;
      }
      const clearBtn = e.target.closest('[data-media-clear]');
      if (clearBtn) {
        e.preventDefault();
        clearHolder(clearBtn.closest('.media-picker-field, .media-picker-cell'));
        return;
      }
      const closeBtn = e.target.closest('[data-media-close]');
      if (closeBtn) { e.preventDefault(); closePicker(); return; }
      if (dialog && dialog.contains(e.target)) {
        const item = e.target.closest('[data-media-id]');
        if (item && currentHolder) {
          e.preventDefault();
          const input = holderInput(currentHolder);
          if (input) {
            input.value = item.getAttribute('data-media-id');
            notifyInput(input);
            setPreview(currentHolder, item);
          }
          closePicker();
        }
      }
    });
  })();
</script>
@endsection
