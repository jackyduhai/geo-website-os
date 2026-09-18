{{-- 挂接到固定栏目的自定义菜单行（内联展示在所属固定栏目 / 页脚列行下）。
     变量：$ch（Menu 模型）；依赖视图内闭包 $parentOptions / $currentRef / $anchoredPos。 --}}
<tr>
  <td class="indent"><details><summary>└ {{ $ch->label }}
        <span class="badge info ml-2">自定义追加</span>
        @if(!$ch->is_active)<span class="badge draft ml-1">已停用</span>@endif
      </summary>
    <form method="post" action="{{ route('admin.menus.update', $ch) }}" class="detail-form">
      @csrf @method('PUT')
      <input type="hidden" name="position" value="{{ $ch->position }}">
      <div class="form-row"><label>上级菜单</label>
        <select name="parent_ref">{!! $parentOptions($ch) !!}</select>
        <script>document.currentScript.previousElementSibling.value=@json($currentRef($ch));</script>
      </div>
      <div class="form-row"><label>文字</label><input type="text" name="label" value="{{ $ch->label }}"></div>
      <div class="form-row"><label>关联栏目</label>
        <select name="category_id">
          <option value="">— 不关联 —</option>
          @foreach($categories as $c)<option value="{{ $c->id }}" @selected($ch->category_id==$c->id)>{{ $c->name }}</option>@endforeach
        </select></div>
      <div class="form-row"><label>URL</label><input type="text" name="url" value="{{ $ch->url }}"></div>
      <div class="form-row"><label>排序</label><input type="number" name="sort" value="{{ $ch->sort }}"></div>
      <div class="form-row"><label>打开方式</label>
        <select name="target"><option value="0" @selected($ch->target==0)>当前窗口</option><option value="1" @selected($ch->target==1)>新窗口</option></select></div>
      <label class="checkline mb-3"><input type="checkbox" name="is_active" value="1" @checked($ch->is_active)> 启用</label>
      <div class="btn-row">
        <button class="btn btn-sm btn-primary">保存</button>
      </div>
    </form>
  </details></td>
  <td class="small muted indent">{{ $ch->category ? '栏目：'.$ch->category->name : ($ch->url ?: '纯父级') }}</td>
  <td class="nowrap">@if($ch->is_active)<span class="badge ok">显示</span>@else<span class="badge draft">已隐藏</span>@endif</td>
  <td class="small">{{ (int) $ch->sort }}</td>
  <td class="actions small">
    <form class="form-inline" method="post" action="{{ route('admin.menus.destroy', $ch) }}"
          onsubmit="return confirm('删除该自定义菜单项？')">@csrf @method('DELETE')
      <button class="btn btn-sm btn-danger">删</button></form>
  </td>
</tr>
