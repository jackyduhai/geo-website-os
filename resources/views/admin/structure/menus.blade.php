@extends('admin.layout')
@section('title','顶部导航与页脚')
@section('page-desc','顶部导航与页脚栏目全部可运营：改名、改链接、新窗、显隐、排序、恢复默认；外链与活动页用「新增自定义菜单项」追加，主导航支持两级。')

@section('content')
<div class="scope-bar">
  <x-admin-tip type="warning" place="bottom" :width="320" text="数据来源：知识中心子项由「内容中心 → 栏目与知识分组」驱动；联系列（电话 / 手机 / 地址 / 二维码）由「站点设置 → 联系方式」驱动，这两类请到对应数据源修改。"/>
  <div class="scope-links">
    <a href="{{ route('admin.groups.index') }}">内容结构 · 知识分组 →</a>
    <a href="{{ route('admin.settings.index','contact') }}">联系方式设置 →</a>
    <a href="{{ route('admin.redirects.index') }}">301 跳转 →</a>
  </div>
</div>

@php
  $posLabel = ['main' => '主导航', 'footer' => '页脚', 'mobile' => '主导航'];
  // 挂接到固定栏目 / 页脚列的自定义项，按父栏目 key 分组，内联展示在所属栏目行下
  $anchoredByParent = $anchored->groupBy('parent_key');
  // 编辑表单复用的上级菜单选项（$current 为当前编辑项，避免自引用）
  $parentOptions = function ($current = null) use ($blueprint, $footerBlueprint, $menus) {
      $opts = ['<option value="">无（作为一级菜单）</option>'];
      $fixed = [];
      foreach ($blueprint as $t) {
          $fixed[] = '<option value="key:' . e($t['key']) . '">' . e($t['name']) . '（主导航固定一级）</option>';
      }
      $opts[] = '<optgroup label="主导航：挂到固定一级栏目下（成为其二级）">' . implode('', $fixed) . '</optgroup>';
      $ft = [];
      foreach ($footerBlueprint as $c) {
          $ft[] = '<option value="key:' . e($c['key']) . '">' . e($c['title']) . '（页脚固定列）</option>';
      }
      $opts[] = '<optgroup label="页脚：挂到固定页脚列下">' . implode('', $ft) . '</optgroup>';
      $custom = [];
      foreach ($menus->whereIn('position', ['main', 'mobile']) as $r) {
          if ($current && $r->id === $current->id) { continue; }
          $custom[] = '<option value="id:' . $r->id . '">' . e($r->label) . '（自定义一级）</option>';
      }
      if ($custom) {
          $opts[] = '<optgroup label="主导航：挂到自定义一级菜单下（成为其二级）">' . implode('', $custom) . '</optgroup>';
      }
      return implode('', $opts);
  };
  $currentRef = function ($m) {
      if ($m->parent_id) { return 'id:' . $m->parent_id; }
      if ($m->parent_key) { return 'key:' . $m->parent_key; }
      return '';
  };
  $anchoredPos = function ($m) {
      return $m->position === 'footer' ? '页脚 · 列内链接' : '主导航 · 二级';
  };
@endphp

<div class="card">
  <h2><span class="label-with-tip">固定主导航栏目 <x-admin-tip type="help" text="展开每行可：改名、改链接、新窗口打开、显隐、排序、恢复默认；点「+ 二级」可在该栏目下追加自定义子菜单，追加后会直接显示在该栏目行下，并同步到前台下拉与页面内二级 Tab。"/></span></h2>
  <table class="tbl">
    <thead><tr><th class="w-260">栏目</th><th>当前指向</th><th>状态</th><th class="w-80">排序</th><th class="actions w-130">操作</th></tr></thead>
    <tbody>
    @foreach($blueprint as $top)
      <tr class="row-head">
        <td><details><summary class="summary-strong">{{ $top['name'] }}
              @if($top['overridden'])<span class="badge draft ml-2">已自定义</span>@endif
            </summary>
            <form method="post" action="{{ route('admin.menus.override') }}" class="detail-form">
              @csrf
              <input type="hidden" name="position" value="main">
              <input type="hidden" name="key" value="{{ $top['key'] }}">
              <div class="form-row"><label>显示文字（留空=默认）</label>
                <input type="text" name="label" value="{{ $top['overridden'] ? $top['name'] : '' }}" placeholder="默认：{{ $top['default_name'] }}"></div>
              <div class="form-row"><label><span class="label-with-tip">链接地址（留空=默认）<x-admin-tip type="warning" place="left" :width="280" text="站内路径以 / 开头（如 /products/seasoning/），外链以 https:// 开头，纯展开下拉的父级可填 #。改链接只改变导航指向，不会改变页面本身地址；需要原地址跳转请配合「301 跳转」。"/></span></label>
                <input type="text" name="url" value="{{ $top['overridden'] && $top['href'] !== $top['default_href'] ? $top['href'] : '' }}" placeholder="默认：{{ $top['default_href'] }}">
              </div>
              <label class="checkline mb-2"><input type="checkbox" name="target" value="1" @checked($top['external'] && $top['href'] !== $top['default_href'])> 新窗口打开</label>
              <div class="form-row"><label>排序（0=默认，数字越小越靠前；可与自定义菜单混排）</label>
                <input type="number" name="sort" min="0" value="{{ $top['sort_value'] ?? '' }}" placeholder="默认"></div>
              <label class="checkline mb-3"><input type="checkbox" name="is_active" value="1" @checked($top['visible'])> 显示该栏目</label>
              <div class="btn-row">
                <button class="btn btn-sm btn-primary">保存</button>
                @if($top['overridden'])
                  <button class="btn btn-sm" type="submit" form="reset-{{ $top['key'] }}">恢复默认</button>
                @endif
              </div>
            </form>
            @if($top['overridden'])
              <form id="reset-{{ $top['key'] }}" class="form-inline" method="post" action="{{ route('admin.menus.override.reset', $top['key']) }}"
                    onsubmit="return confirm('恢复该栏目为默认名称/链接/显示/排序？')">@csrf @method('DELETE')</form>
            @endif
          </details></td>
        <td class="small">{{ $top['href'] }}@if($top['external'])<span class="badge info ml-1">新窗</span>@endif</td>
        <td class="nowrap">@if($top['visible'])<span class="badge ok">显示</span>@else<span class="badge draft">已隐藏</span>@endif</td>
        <td class="small">{{ $top['sort'] }}</td>
        <td class="actions small"><a href="#new-custom-card" class="btn btn-sm js-add-child" data-ref="key:{{ $top['key'] }}">+ 二级</a></td>
      </tr>
      @foreach($top['children'] as $ch)
        <tr>
          <td class="indent"><details><summary>{{ $ch['name'] }}
                @if($ch['overridden'])<span class="badge draft ml-2">已自定义</span>@endif
                @if($ch['dynamic'])<span class="badge info ml-2">内容分组驱动</span>
                  <x-admin-tip place="right" :width="280" text="该子项由「内容中心 → 栏目与知识分组」自动生成：启用几个分组显示几个。改名、显隐、链接请到知识分组中维护。"/>@endif
              </summary>
              @if($ch['dynamic'])
              @else
              <form method="post" action="{{ route('admin.menus.override') }}" class="detail-form">
                @csrf
                <input type="hidden" name="position" value="main">
                <input type="hidden" name="key" value="{{ $ch['key'] }}">
                <div class="form-row"><label>显示文字（留空=默认）</label>
                  <input type="text" name="label" value="{{ $ch['overridden'] ? $ch['name'] : '' }}" placeholder="默认：{{ $ch['default_name'] }}"></div>
                <div class="form-row"><label><span class="label-with-tip">链接地址（留空=默认）<x-admin-tip place="left" :width="280" text="站内路径以 / 开头（含锚点如 /factory/#workshops），外链以 https:// 开头。"/></span></label>
                  <input type="text" name="url" value="{{ $ch['overridden'] && $ch['href'] !== $ch['default_href'] ? $ch['href'] : '' }}" placeholder="默认：{{ $ch['default_href'] }}">
                </div>
                <label class="checkline mb-2"><input type="checkbox" name="target" value="1" @checked($ch['external'] && $ch['href'] !== $ch['default_href'])> 新窗口打开</label>
                <div class="form-row"><label>排序（0=默认）</label>
                  <input type="number" name="sort" min="0" value="{{ $ch['sort_value'] ?? '' }}" placeholder="默认"></div>
                <label class="checkline mb-3"><input type="checkbox" name="is_active" value="1" @checked($ch['visible'])> 显示该子项</label>
                <div class="btn-row">
                  <button class="btn btn-sm btn-primary">保存</button>
                  @if($ch['overridden'])
                    <button class="btn btn-sm" type="submit" form="reset-{{ $ch['key'] }}">恢复默认</button>
                  @endif
                </div>
              </form>
              @endif
              @if($ch['overridden'])
                <form id="reset-{{ $ch['key'] }}" class="form-inline" method="post" action="{{ route('admin.menus.override.reset', $ch['key']) }}"
                      onsubmit="return confirm('恢复该子项为默认名称/链接/显示/排序？')">@csrf @method('DELETE')</form>
              @endif
            </details></td>
          <td class="small muted indent">{{ $ch['href'] }}@if($ch['external'] && ! $ch['dynamic'])<span class="badge info ml-1">新窗</span>@endif</td>
          <td class="nowrap">@if($ch['visible'])<span class="badge ok">显示</span>@else<span class="badge draft">已隐藏</span>@endif</td>
          <td class="small">{{ $ch['sort'] }}</td>
          <td class="actions small muted">子项</td>
        </tr>
      @endforeach
      @foreach($anchoredByParent->get($top['key'], []) as $ch)
        @include('admin.partials.menu-anchored-row', ['ch' => $ch])
      @endforeach
    @endforeach
    </tbody>
  </table>
</div>

<div class="card">
  <h2><span class="label-with-tip">固定页脚栏目 <x-admin-tip type="help" text="可维护列标题、链接文字 / 地址、显隐与排序；联系列（电话 / 手机 / 地址 / 二维码）由「站点设置 → 联系方式」驱动。"/></span></h2>
  <table class="tbl">
    <thead><tr><th class="w-260">页脚项</th><th>当前指向</th><th>状态</th><th class="w-80">排序</th><th class="actions w-130">操作</th></tr></thead>
    <tbody>
    @foreach($footerBlueprint as $col)
      <tr class="row-head">
        <td><details><summary class="summary-strong">列：{{ $col['title'] }}
              @if($col['overridden'])<span class="badge draft ml-2">已自定义</span>@endif
            </summary>
            <form method="post" action="{{ route('admin.menus.override') }}" class="detail-form">
              @csrf
              <input type="hidden" name="position" value="footer">
              <input type="hidden" name="key" value="{{ $col['key'] }}">
              <div class="form-row"><label>列标题（留空=默认）</label>
                <input type="text" name="label" value="{{ $col['overridden'] ? $col['title'] : '' }}" placeholder="默认：{{ $col['default_title'] }}"></div>
              <div class="form-row"><label>排序（0=默认）</label>
                <input type="number" name="sort" min="0" value="{{ $col['sort_value'] ?? '' }}" placeholder="默认"></div>
              <label class="checkline mb-3"><input type="checkbox" name="is_active" value="1" @checked($col['visible'])> 显示该列</label>
              <div class="btn-row">
                <button class="btn btn-sm btn-primary">保存</button>
                @if($col['overridden'])
                  <button class="btn btn-sm" type="submit" form="reset-{{ $col['key'] }}">恢复默认</button>
                @endif
              </div>
            </form>
            @if($col['overridden'])
              <form id="reset-{{ $col['key'] }}" class="form-inline" method="post" action="{{ route('admin.menus.override.reset', $col['key']) }}"
                    onsubmit="return confirm('恢复该列为默认标题/显示/排序？')">@csrf @method('DELETE')</form>
            @endif
          </details></td>
        <td class="small muted">页脚列</td>
        <td class="nowrap">@if($col['visible'])<span class="badge ok">显示</span>@else<span class="badge draft">已隐藏</span>@endif</td>
        <td class="small">{{ $col['sort'] }}</td>
        <td class="actions small"><a href="#new-custom-card" class="btn btn-sm js-add-child" data-ref="key:{{ $col['key'] }}">+ 链接</a></td>
      </tr>
      @foreach($col['items'] as $item)
        @continue($item['forced_hidden'])
        <tr>
          <td class="indent"><details><summary>{{ $item['name'] }}
                @if($item['overridden'])<span class="badge draft ml-2">已自定义</span>@endif
                @if($item['locked'])<span class="badge info ml-2">站点设置驱动</span>
                  <x-admin-tip place="left" :width="280" text="电话 / 手机 / 地址 / 二维码统一在「站点设置 → 联系方式」维护，此处仅可改显示名称或隐藏。"/>@endif
              </summary>
              <form method="post" action="{{ route('admin.menus.override') }}" class="detail-form">
                @csrf
                <input type="hidden" name="position" value="footer">
                <input type="hidden" name="key" value="{{ $item['key'] }}">
                <div class="form-row"><label>显示文字（留空=默认）</label>
                  <input type="text" name="label" value="{{ $item['overridden'] ? $item['name'] : '' }}" placeholder="默认：{{ $item['default_name'] }}"></div>
                @if(! $item['locked'])
                <div class="form-row"><label>链接地址（留空=默认）</label>
                  <input type="text" name="url" value="{{ $item['overridden'] && $item['href'] !== $item['default_href'] ? $item['href'] : '' }}" placeholder="默认：{{ $item['default_href'] }}">
                </div>
                <label class="checkline mb-2"><input type="checkbox" name="target" value="1" @checked($item['external'] && $item['href'] !== $item['default_href'])> 新窗口打开</label>
                @endif
                <div class="form-row"><label>排序（0=默认）</label>
                  <input type="number" name="sort" min="0" value="{{ $item['sort_value'] ?? '' }}" placeholder="默认"></div>
                <label class="checkline mb-3"><input type="checkbox" name="is_active" value="1" @checked($item['visible'])> 显示该项</label>
                <div class="btn-row">
                  <button class="btn btn-sm btn-primary">保存</button>
                  @if($item['overridden'])
                    <button class="btn btn-sm" type="submit" form="reset-{{ $item['key'] }}">恢复默认</button>
                  @endif
                </div>
              </form>
              @if($item['overridden'])
                <form id="reset-{{ $item['key'] }}" class="form-inline" method="post" action="{{ route('admin.menus.override.reset', $item['key']) }}"
                      onsubmit="return confirm('恢复该项为默认名称/链接/显示/排序？')">@csrf @method('DELETE')</form>
              @endif
            </details></td>
          <td class="small muted indent">
            @if($item['type'] === 'qr')二维码
            @elseif($item['type'] === 'text'){{ $item['value'] ?: '设置驱动' }}
            @else{{ $item['href'] }}@endif
            @if($item['external'] && ! $item['locked'])<span class="badge info ml-1">新窗</span>@endif
          </td>
          <td class="nowrap">@if($item['visible'])<span class="badge ok">显示</span>@else<span class="badge draft">已隐藏</span>@endif</td>
          <td class="small">{{ $item['sort'] }}</td>
          <td class="actions small muted">{{ $item['locked'] ? '设置项' : '链接' }}</td>
        </tr>
      @endforeach
      @foreach($anchoredByParent->get($col['key'], []) as $ch)
        @include('admin.partials.menu-anchored-row', ['ch' => $ch])
      @endforeach
    @endforeach
    </tbody>
  </table>
</div>

<div class="grid g2">
  <div class="card" id="new-custom-card">
    <h2><span class="label-with-tip">新增自定义菜单项 <x-admin-tip type="help" text="可作为主导航一级，也可挂为某栏目的二级，或放入页脚列；外链、活动页都用这里追加。"/></span></h2>
    <form method="post" action="{{ route('admin.menus.store') }}">
      @csrf
      <div class="form-grid">
        <div class="form-row"><label><span class="label-with-tip">上级菜单 <x-admin-tip text="选主导航一级 → 成为其二级下拉；选页脚列 → 出现在该列；选「无」→ 作为一级（主导航右侧或页脚「快捷入口」）。"/></span></label>
          <select name="parent_ref" id="new-parent-ref" onchange="document.getElementById('new-position-row').style.display=this.value?'none':''">
            {!! $parentOptions() !!}
          </select>
        </div>
        <div class="form-row" id="new-position-row"><label>位置（无上级时生效）</label>
          <select name="position">
            <option value="main">主导航（桌面/移动）</option>
            <option value="footer">页脚（快捷入口列）</option>
          </select></div>
        <div class="form-row"><label>显示文字 <span class="req">*</span></label>
          <input type="text" name="label" required></div>
        <div class="form-row"><label>关联栏目（与自定义 URL 二选一）</label>
          <select name="category_id">
            <option value="">— 不关联 —</option>
            @foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
          </select></div>
        <div class="form-row"><label>自定义 URL</label><input type="text" name="url" placeholder="https:// 或 /path；纯父级菜单可留空"></div>
        <div class="form-row"><label>排序</label><input type="number" name="sort" value="0"></div>
        <div class="form-row"><label>打开方式</label>
          <select name="target"><option value="0">当前窗口</option><option value="1">新窗口</option></select></div>
      </div>
      <label class="checkline mb-4"><input type="checkbox" name="is_active" value="1" checked> 启用</label>
      <button class="btn btn-primary">创建</button>
    </form>
  </div>

  <div class="card">
    <h2><span class="label-with-tip">自定义一级菜单 <x-admin-tip type="help" text="此处仅维护自定义的一级菜单及其二级；挂接到固定栏目 / 页脚列下的自定义链接，直接显示在上方对应栏目行下。"/></span></h2>
    <table class="tbl">
      <thead><tr><th class="w-220">菜单</th><th>位置</th><th>指向</th><th>状态</th><th class="actions"></th></tr></thead>
      <tbody>
      @forelse($menus as $m)
        {{-- 自定义一级 --}}
        <tr class="row-head">
          <td><details><summary class="summary-strong">{{ $m->label }}
                @if($m->children->isNotEmpty())<span class="badge draft ml-2">{{ $m->children->count() }} 个二级</span>@endif
              </summary>
            <form method="post" action="{{ route('admin.menus.update',$m) }}" class="detail-form">
              @csrf @method('PUT')
              <input type="hidden" name="position" value="{{ $m->position }}">
              <div class="form-row"><label>上级菜单</label>
                <select name="parent_ref">{!! $parentOptions($m) !!}</select>
                <script>document.currentScript.previousElementSibling.value=@json($currentRef($m));</script>
              </div>
              <div class="form-row"><label>文字</label><input type="text" name="label" value="{{ $m->label }}"></div>
              <div class="form-row"><label>关联栏目</label>
                <select name="category_id">
                  <option value="">— 不关联 —</option>
                  @foreach($categories as $c)<option value="{{ $c->id }}" @selected($m->category_id==$c->id)>{{ $c->name }}</option>@endforeach
                </select></div>
              <div class="form-row"><label>URL</label><input type="text" name="url" value="{{ $m->url }}"></div>
              <div class="form-row"><label>排序</label><input type="number" name="sort" value="{{ $m->sort }}"></div>
              <div class="form-row"><label>打开方式</label>
                <select name="target"><option value="0" @selected($m->target==0)>当前窗口</option><option value="1" @selected($m->target==1)>新窗口</option></select></div>
              <label class="checkline mb-3"><input type="checkbox" name="is_active" value="1" @checked($m->is_active)> 启用</label>
              <button class="btn btn-sm btn-primary">保存</button>
            </form>
          </details></td>
          <td class="small">{{ $posLabel[$m->position] ?? $m->position }} · 一级</td>
          <td class="small">{{ $m->category ? '栏目：'.$m->category->name : ($m->url ?: '纯父级') }}</td>
          <td>@if($m->is_active)<span class="badge ok">启用</span>@else<span class="badge draft">停用</span>@endif</td>
          <td class="actions">
            <form class="form-inline" method="post" action="{{ route('admin.menus.destroy',$m) }}"
                  onsubmit="return confirm('{{ $m->children->isNotEmpty() ? '该菜单下还有二级子菜单，需先删除子菜单。\\n仍要尝试删除吗？' : '删除该自定义菜单项？' }}')">@csrf @method('DELETE')
              <button class="btn btn-sm btn-danger">删</button></form>
          </td>
        </tr>
        {{-- 自定义一级下的二级 --}}
        @foreach($m->children as $ch)
          <tr>
            <td class="indent"><details><summary>└ {{ $ch->label }}</summary>
              <form method="post" action="{{ route('admin.menus.update',$ch) }}" class="detail-form">
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
                <button class="btn btn-sm btn-primary">保存</button>
              </form>
            </details></td>
            <td class="small muted indent">主导航 · 二级</td>
            <td class="small muted">{{ $ch->category ? '栏目：'.$ch->category->name : ($ch->url ?: '—') }}</td>
            <td>@if($ch->is_active)<span class="badge ok">启用</span>@else<span class="badge draft">停用</span>@endif</td>
            <td class="actions">
              <form class="form-inline" method="post" action="{{ route('admin.menus.destroy',$ch) }}"
                    onsubmit="return confirm('删除该二级菜单项？')">@csrf @method('DELETE')
                <button class="btn btn-sm btn-danger">删</button></form>
            </td>
          </tr>
        @endforeach
      @empty
        <tr><td colspan="5" class="empty-cell">暂无自定义一级菜单</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  document.querySelectorAll('.js-add-child').forEach(function(a){
    a.addEventListener('click', function(){
      var sel = document.getElementById('new-parent-ref');
      var row = document.getElementById('new-position-row');
      if (!sel) return;
      sel.value = a.getAttribute('data-ref');
      if (row) row.style.display = 'none';
      var card = document.getElementById('new-custom-card');
      if (card) card.scrollIntoView({behavior:'smooth', block:'center'});
      var input = card && card.querySelector('input[name="label"]');
      if (input) setTimeout(function(){ input.focus(); }, 300);
    });
  });
})();
</script>
@endsection
