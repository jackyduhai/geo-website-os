@extends('admin.layout')
@section('title','组合内容 · '.($page->title ?: $page->slug))
@section('page-desc','在模板槽位内添加、排序、显隐区块，并查看区块的 GEO 语义；Hero 可快速切换布局。区块内容为结构化数据，由系统渲染。')

@section('page-actions')
  <a class="btn btn-sm" href="{{ route('admin.pages.index') }}">← 返回列表</a>
  <a class="btn btn-sm" target="_blank"
     href="{{ route('admin.pages.preview', $page) }}">预览 ↗</a>
  @if($page->status === 'published')
    <a class="btn btn-sm" target="_blank"
       href="{{ \App\Support\PublicUrl::url('/'.ltrim((string) $page->slug, '/')) }}">查看前台 ↗</a>
  @endif
@endsection

@section('content')
<style>
  .sem-row{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
  .sem-chip{font-size:11px;line-height:1.4;padding:1px 7px;border-radius:999px;border:1px solid var(--line,#e2e8f0);color:#475569;background:#f8fafc;font-family:ui-monospace,Menlo,Consolas,monospace}
  .sem-chip.sec{border-color:#c7d2fe;color:#3730a3;background:#eef2ff}
  .sem-chip.ent{border-color:#bbf7d0;color:#166534;background:#f0fdf4}
  .sem-chip.conv{border-color:#fed7aa;color:#9a3412;background:#fff7ed}
  .variant-select{font-size:12px;padding:3px 6px;border-radius:6px;border:1px solid var(--line,#cbd5e1)}
</style>

<div class="card">
  <div class="card-head">
    <h2>{{ $page->title ?: '（未命名）' }}
      <span class="badge info ml-1">{{ $page->locale }}</span>
      <span class="badge ml-1 {{ $page->status === 'published' ? 'published' : 'archived' }}">
        {{ $page->status === 'published' ? '已发布' : '草稿' }}
      </span>
    </h2>
  </div>
  <div class="actions">
    <a class="btn btn-sm" href="{{ route('admin.pages.edit', $page) }}">页面设置</a>
    @if($page->status === 'published')
      <form class="form-inline" method="post"
            action="{{ route('admin.pages.publish', [$page, 'unpublish']) }}">
        @csrf
        <button class="btn btn-sm">下架</button>
      </form>
    @else
      <form class="form-inline" method="post"
            action="{{ route('admin.pages.publish', [$page, 'publish']) }}">
        @csrf
        <button class="btn btn-sm btn-primary">发布页面</button>
      </form>
    @endif
  </div>
</div>

@foreach($template->slotNames() as $slotName)
  @php
    $slotLabel = $template->slot($slotName)['label'] ?? $slotName;
    $slotBlocks = $page->blocks->where('slot', $slotName)->sortBy('sort');
  @endphp
  <div class="card mt-2">
    <div class="card-head">
      <h2>{{ $slotLabel }}
        <span class="hint">槽位 {{ $slotName }} · {{ $slotBlocks->count() }} 个区块</span>
      </h2>
    </div>
    <table class="tbl">
      <thead>
        <tr>
          <th class="w-60">排序</th>
          <th>区块 / GEO 语义</th>
          <th class="w-90">状态</th>
          <th class="actions w-380">操作</th>
        </tr>
      </thead>
      <tbody>
      @forelse($slotBlocks as $block)
        @php
          $bt = $blockTypes[$block->type] ?? null;
          $sem = $bt
              ? \App\Support\Blocks\SectionSemantic::for($bt, [])
              : ['section' => null, 'purpose' => null, 'entity' => null, 'conversion' => null];
          $variants = \App\Support\Blocks\CompositionPolicy::allowedVariants($block->type);
          $currentVariant = $block->cfg()['variant'] ?? null;
        @endphp
        <tr>
          <td class="mono small">{{ $block->sort }}</td>
          <td>
            <strong>{{ $bt->label ?? $block->type }}</strong>
            <span class="hint mono">{{ $block->type }}</span>
            <div class="sem-row">
              @if($sem['section'])<span class="sem-chip sec" title="Section 叙事角色">§ {{ $sem['section'] }}</span>@endif
              @if($sem['purpose'])<span class="sem-chip" title="Purpose 沟通目的">{{ $sem['purpose'] }}</span>@endif
              @if($sem['entity'])<span class="sem-chip ent" title="Entity 关联实体">{{ $sem['entity'] }}</span>@endif
              @if($sem['conversion'])<span class="sem-chip conv" title="Conversion 转化行为">↯ {{ $sem['conversion'] }}</span>@endif
            </div>
          </td>
          <td>
            @if($block->is_active)
              <span class="badge published">显示</span>
            @else
              <span class="badge archived">已隐藏</span>
            @endif
          </td>
          <td class="actions">
            @if(! empty($variants))
              <form class="form-inline" method="post"
                    action="{{ route('admin.pages.setVariant', [$page, $block]) }}">
                @csrf
                <label class="sr-only" for="variant-{{ $block->id }}">布局变体</label>
                <select class="variant-select" name="variant" id="variant-{{ $block->id }}"
                        onchange="this.form.submit()" aria-label="切换区块布局变体">
                  @foreach($variants as $v)
                    <option value="{{ $v }}" @selected($currentVariant === $v)>{{ $v }}</option>
                  @endforeach
                </select>
                <noscript><button class="btn btn-sm">应用</button></noscript>
              </form>
            @endif
            <a class="btn btn-sm btn-primary"
               href="{{ route('admin.pages.editBlock', [$page, $block]) }}">编辑内容</a>
            <form class="form-inline" method="post"
                  action="{{ route('admin.pages.moveBlock', [$page, $block, 'up']) }}">
              @csrf
              <button class="btn btn-sm" aria-label="上移">↑</button>
            </form>
            <form class="form-inline" method="post"
                  action="{{ route('admin.pages.moveBlock', [$page, $block, 'down']) }}">
              @csrf
              <button class="btn btn-sm" aria-label="下移">↓</button>
            </form>
            <form class="form-inline" method="post"
                  action="{{ route('admin.pages.toggleBlock', [$page, $block]) }}">
              @csrf
              <button class="btn btn-sm">{{ $block->is_active ? '隐藏' : '显示' }}</button>
            </form>
            <form class="form-inline" method="post"
                  action="{{ route('admin.pages.duplicateBlock', [$page, $block]) }}">
              @csrf
              <button class="btn btn-sm">复制</button>
            </form>
            <form class="form-inline" method="post"
                  action="{{ route('admin.pages.destroyBlock', [$page, $block]) }}"
                  onsubmit="return confirm('删除该区块？区块引用的业务内容不会被删除。')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-danger">删</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="4" class="empty-cell">该槽位还没有区块。</td></tr>
      @endforelse
      </tbody>
    </table>
    <div class="form-actions">
      <a class="btn btn-sm"
         href="{{ route('admin.pages.addBlock', ['page' => $page, 'slot' => $slotName]) }}">
        ＋ 添加区块到「{{ $slotLabel }}」
      </a>
    </div>
  </div>
@endforeach
@endsection
