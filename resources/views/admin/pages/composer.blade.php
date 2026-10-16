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
  .sem-legend-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-top:6px}
  .sem-legend-title{font-weight:600;font-size:12px;color:#334155;margin-bottom:4px}
  .sem-legend-item{display:flex;align-items:center;gap:6px;font-size:12px;line-height:1.5;margin:3px 0}
  .sem-legend-item .zh{color:#64748b}
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

@php
  // GEO 语义图例：chip 上的英文是受控词（原样输出到前台 data-section/data-purpose/
  // data-entity/data-conversion，属机器可读契约，不可改）；此处只做常驻中文解释。
  // key 直接取自 SectionSemantic 受控词表，避免图例与词表漂移。
  $semLegend = [
      ['cls' => 'sec', 'prefix' => '§', 'title' => 'Section 叙事角色（区块在商业叙事中的角色）',
       'keys' => \App\Support\Blocks\SectionSemantic::SECTIONS, 'zh' => [
           'hero' => '首屏价值主张', 'about' => '品牌 / 主体介绍', 'trust' => '可信背书 / 实力证明',
           'product' => '产品体系 / 产品事实', 'solution' => '解决方案 / 服务场景', 'case' => '客户案例 / 评价',
           'certificate' => '认证 / 资质', 'faq' => '常见问题', 'contact' => '联系方式 / 表单',
           'conversion' => '转化引导带', 'navigation' => '站点导航',
       ]],
      ['cls' => '', 'prefix' => '', 'title' => 'Purpose 沟通目的（区块服务的沟通目的）',
       'keys' => \App\Support\Blocks\SectionSemantic::PURPOSES, 'zh' => [
           'brand' => '建立品牌认知 / 第一印象', 'trust' => '建立信任 / 降低风险顾虑',
           'education' => '解释概念 / 能力 / 事实', 'comparison' => '帮助比较 / 选择方案',
           'conversion' => '直接推动一次转化动作',
       ]],
      ['cls' => 'ent', 'prefix' => '', 'title' => 'Entity 关联实体（主要描述的实体类型）',
       'keys' => \App\Support\Blocks\SectionSemantic::ENTITIES, 'zh' => [
           'Organization' => '组织', 'Product' => '产品', 'Service' => '服务', 'Factory' => '工厂',
           'Content' => '内容', 'Person' => '人物', 'Location' => '地点', 'Case' => '客户案例',
       ]],
      ['cls' => 'conv', 'prefix' => '↯', 'title' => 'Conversion 转化行为（引导的转化动作）',
       'keys' => \App\Support\Blocks\SectionSemantic::CONVERSIONS, 'zh' => [
           'contact' => '联系', 'download' => '下载', 'consult' => '咨询', 'purchase' => '购买',
           'demo' => '演示', 'appointment' => '预约', 'signup' => '注册',
       ]],
  ];
@endphp
<div class="card mt-2">
  <div class="card-head">
    <h2>GEO 语义图例</h2>
    <span class="hint">下方每个区块 chip 上的英文是机器可读受控词，会原样输出到前台 data-* 属性，不随界面翻译改变；中文仅用于说明含义。</span>
  </div>
  <div class="sem-legend-grid">
    @foreach($semLegend as $legend)
      <div>
        <div class="sem-legend-title">{{ $legend['title'] }}</div>
        @foreach($legend['keys'] as $legendKey)
          <div class="sem-legend-item">
            <span class="sem-chip {{ $legend['cls'] }}">{{ $legend['prefix'] !== '' ? $legend['prefix'].' ' : '' }}{{ $legendKey }}</span>
            <span class="zh">{{ $legend['zh'][$legendKey] ?? '' }}</span>
          </div>
        @endforeach
      </div>
    @endforeach
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
          // 变体下拉的中文显示取自区块字段定义（config/blocks.php 的 variant select options）；
          // 提交值始终是白名单英文枚举（split/center），中文缺失时回退英文。
          $variantField = null;
          foreach ($bt->fields ?? [] as $bf) {
              if (($bf['key'] ?? '') === 'variant' && ($bf['type'] ?? '') === 'select') {
                  $variantField = $bf;
                  break;
              }
          }
          $variantOptions = $variantField['options'] ?? [];
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
                    <option value="{{ $v }}" @selected($currentVariant === $v)>{{ $variantOptions[$v] ?? $v }}</option>
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
                  onsubmit="return confirm('删除该区块？区块引用的业务内容不会被删除，此操作不可恢复。')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-danger">删除区块</button>
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
