@extends('admin.layout')
@section('title','首页装修')
@section('page-desc','首页全部区块在本页一处装修：首屏模式、幻灯、中部横幅、能力点 / 场景 / 流程、信任数字与内容流；排序数字越小越靠前，关闭后前台不渲染。')

@section('content')

@foreach($blocks as $block)
  @php
    $kind = $block->kind();
    // 条目型区块：未自定义时用统一默认内容预填，管理员打开即可看到并修改具体文案/图片
    $items = $block->items();
    if (in_array($kind, ['items', 'steps'], true) && empty($items)) {
        $items = \App\Support\HomeBlockDefaults::for($block->type);
    }
    $picked = $block->pickedIds();
    $bcfg = $block->cfg();
    $tcfg = config('home_blocks.types.' . $block->type, []);
    $hasHeading = $tcfg['heading'] ?? true;   // 该区块前台是否渲染标题
    $hasSubtitle = $hasHeading && ($tcfg['subtitle'] ?? true); // 是否渲染副标题
  @endphp
  <form method="post" action="{{ route('admin.blocks.update',$block) }}" class="card block-card" enctype="multipart/form-data">
    @csrf @method('PUT')
    <div class="bc-head">
      <div class="bc-title">
        <div class="bc-title-name">
          <strong>{{ $block->typeLabel() }}</strong>
          <span class="mono small muted">（{{ $block->type }}）</span>
          @if(!empty($tcfg['front']))<x-admin-tip type="info" place="bottom" :width="300" text="前台对应位置：{{ $tcfg['front'] }}" />@endif
          @if($block->type === 'facts')
            <x-admin-tip type="warning" place="bottom" :width="300" text="每条事实来自单一事实源：增删改、是否公开、排序都在「信任与事实 → 事实库」操作，此处只能改区块标题，避免两处编辑导致口径不一致。前台首页精选 8 条公开事实，全量见关于页。" />
            <a class="small bc-link" href="{{ route('admin.facts.index') }}" target="_blank">事实库管理 →</a>
          @endif
          @if($block->type === 'data')
            <x-admin-tip type="info" place="bottom" :width="280" text="核定数据展示区块：数字来自单一事实源，避免前后台不一致；此处仅控制排序与显示开关，无需也无法填写标题。" />
          @endif
        </div>
      </div>
      <div class="bc-tools">
        <label class="small">排序 <input type="number" name="sort" value="{{ $block->sort }}" class="w-74"></label>
        <label class="checkline"><input type="checkbox" name="is_active" value="1" @checked($block->is_active)> 显示</label>
        <button class="btn btn-sm btn-primary">保存</button>
      </div>
    </div>

    @if($kind === 'hero')
      {{-- 首屏：点选模式卡（A/B/C）+ 全局眉题 + A 文案 + B/C 内嵌幻灯（每图独立主题文案，直接传图，不跳转） --}}
      @php $curMode = $bcfg['mode'] ?? 'A'; @endphp
      <div class="hero-edit" data-current="{{ $curMode }}">
        <div class="hmode-grid" role="radiogroup" aria-label="首屏样式">
          @foreach([
            'A' => ['价值主张 + 参数卡', '无需配图，信息型，右侧产品参数卡（默认）'],
            'B' => ['全幅图片轮播', '整张大图轮播，适合视觉海报，每张独立主题文案'],
            'C' => ['一体化主视觉（推荐）', '图为整块背景，图文按钮融合，多张图文一起交叉淡入'],
          ] as $mk => $m)
            <label class="hmode-card @if($curMode === $mk) sel @endif" data-mode="{{ $mk }}">
              <input type="radio" name="hero_mode" value="{{ $mk }}" @checked($curMode === $mk)>
              <span class="hmc-tag">{{ $mk }}</span>
              <span class="hmc-txt"><strong>{{ $m[0] }}</strong><em>{{ $m[1] }}</em></span>
              <svg class="hmc-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"
                   stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            </label>
          @endforeach
        </div>

        <label class="fl mb-3"><span class="label-with-tip">眉题 / 定位行
          <x-admin-tip text="主标题上方一行小字，三种模式通用；留空使用默认文案。"/>
        </span>
          <input type="text" name="subtitle" value="{{ $block->subtitle }}" maxlength="200"
                 placeholder="企业名称 · 深耕行业多年">
        </label>

        <label class="checkline mb-4">
          <input type="checkbox" name="hero_autoplay" value="1" @checked(!empty($bcfg['autoplay']))>
          <span class="label-with-tip">自动播放幻灯 <x-admin-tip text="对 B 全幅轮播 / C 图文交叉淡入生效；不勾选则由访客点圆点手动切换。"/></span>
        </label>

        {{-- A 模式文案（无需配图） --}}
        <div class="hpanel @if($curMode==='A') on @endif" data-hpanel="A">
          <b class="block mb-2">A · 价值主张文案（右侧参数卡数据来自核定事实，无需配图）</b>
          <label class="fl mb-2">主标题 H1（留空用默认）
            <input type="text" name="title" value="{{ $block->title }}" maxlength="120"
                   placeholder="一句话主标题">
          </label>
          <label class="fl">说明正文（留空用默认口径）
            <textarea name="hero_lead" rows="3" maxlength="500"
                      placeholder="一句话说明你的产品与服务优势……">{{ trim((string) ($bcfg['lead'] ?? '')) }}</textarea>
          </label>
        </div>

        {{-- B/C 共用：内嵌幻灯编辑器（一张图=一个主题，直接在此传图/改文案/排序/删除） --}}
        <div class="hpanel slides-panel @if($curMode==='B'||$curMode==='C') on @endif" data-slides>
          <div class="hp-top">
            <b><span class="label-with-tip">首屏幻灯 <x-admin-tip type="help" place="bottom" :width="300" text="每张图 = 一个独立主题（图片 + 主标题 + 正文 + 按钮），直接在此传图改文案，无需跳转。某张主标题 / 正文留空则用默认口径；多张按排序轮播，切换时图片与文字一起变化。jpg/webp，单张 ≤8MB。B/C 无可用图时前台自动回退 A 模式，不会空白。"/></span></b>
            <button type="button" class="btn btn-sm add-slide">＋ 添加一张幻灯</button>
          </div>
          <div class="slide-list">
            @forelse($heroBanners as $hb)
              <div class="slide-row">
                <div class="sr-img">
                  @if($hb->imageUrl())<img src="{{ $hb->imageUrl() }}" alt="">@endif
                  <input type="hidden" name="slides[{{ $hb->id }}][id]" value="{{ $hb->id }}">
                  <input type="file" name="slides[{{ $hb->id }}][image_file]" accept="image/*" class="row-file">
                  <span class="size-tag">B 全幅 1920×640（3:1）；C 背景 2048×900，暗调、主体在右</span>
                </div>
                <div class="sr-fields">
                  <div class="grid2">
                    <label class="fl">这张的主标题
                      <input type="text" name="slides[{{ $hb->id }}][title]" value="{{ $hb->title }}" maxlength="120" placeholder="留空用默认主标题">
                    </label>
                    <label class="fl">按钮文字
                      <input type="text" name="slides[{{ $hb->id }}][link_text]" value="{{ $hb->link_text }}" maxlength="40" placeholder="联系我们">
                    </label>
                  </div>
                  <label class="fl">这张的正文段落
                    <textarea name="slides[{{ $hb->id }}][subtitle]" rows="2" maxlength="300" placeholder="留空用默认说明">{{ $hb->subtitle }}</textarea>
                  </label>
                  <div class="grid2">
                    <label class="fl">按钮跳转链接
                      <input type="text" name="slides[{{ $hb->id }}][link]" value="{{ $hb->link }}" placeholder="/contact/ 或 http(s)://，留空默认到联系区">
                    </label>
                    <label class="fl">排序
                      <input type="number" name="slides[{{ $hb->id }}][sort]" value="{{ $hb->sort }}">
                    </label>
                  </div>
                </div>
                <div class="sr-side">
                  <label class="checkline small"><input type="checkbox" name="slides[{{ $hb->id }}][is_active]" value="1" @checked($hb->is_active)> 启用</label>
                  <label class="checkline small danger"><input type="checkbox" name="slides[{{ $hb->id }}][remove]" value="1"> 删除</label>
                </div>
              </div>
            @empty
              <p class="hp-empty">还没有首屏幻灯，点右上「＋ 添加一张幻灯」上传。</p>
            @endforelse
            <div class="slide-new"></div>
          </div>
        </div>
      </div>
    @elseif($kind === 'midbanner')
      {{-- 中部横幅：就地传图/改文案，不跳 Banner 模块 --}}
      <div class="hpanel on">
        <div class="hp-top">
          <b><span class="label-with-tip">首页中部横幅 <x-admin-tip type="help" place="bottom" :width="300" text="单张时左右通栏铺满；多张时为卡片横滑。可填图上标题 / 副标题与按钮跳转，留空则只出干净大图。jpg/webp，单张 ≤8MB，推荐尺寸见每行上传位旁标注。"/></span></b>
          <button type="button" class="btn btn-sm add-mid">＋ 添加一张横幅</button>
        </div>
        <div class="slide-list">
          @forelse($midBanners as $mb)
            <div class="slide-row">
              <div class="sr-img">
                @if($mb->imageUrl())<img src="{{ $mb->imageUrl() }}" alt="">@endif
                <input type="hidden" name="slides[{{ $mb->id }}][id]" value="{{ $mb->id }}">
                <input type="file" name="slides[{{ $mb->id }}][image_file]" accept="image/*" class="row-file">
                <span class="size-tag">通栏 4:1，建议 2400×600（移动端按 16:9 裁切）</span>
              </div>
              <div class="sr-fields">
                <div class="grid2">
                  <label class="fl">图上标题
                    <input type="text" name="slides[{{ $mb->id }}][title]" value="{{ $mb->title }}" maxlength="120" placeholder="留空则不压标题">
                  </label>
                  <label class="fl">按钮文字
                    <input type="text" name="slides[{{ $mb->id }}][link_text]" value="{{ $mb->link_text }}" maxlength="40" placeholder="了解详情">
                  </label>
                </div>
                <label class="fl">图上副标题
                  <textarea name="slides[{{ $mb->id }}][subtitle]" rows="2" maxlength="300">{{ $mb->subtitle }}</textarea>
                </label>
                <div class="grid2">
                  <label class="fl">跳转链接
                    <input type="text" name="slides[{{ $mb->id }}][link]" value="{{ $mb->link }}" placeholder="/cooperation/ 或 http(s)://">
                  </label>
                  <label class="fl">排序
                    <input type="number" name="slides[{{ $mb->id }}][sort]" value="{{ $mb->sort }}">
                  </label>
                </div>
              </div>
              <div class="sr-side">
                <label class="checkline small"><input type="checkbox" name="slides[{{ $mb->id }}][is_active]" value="1" @checked($mb->is_active)> 启用</label>
                <label class="checkline small danger"><input type="checkbox" name="slides[{{ $mb->id }}][remove]" value="1"> 删除</label>
              </div>
            </div>
          @empty
            <p class="hp-empty">还没有中部横幅，点「＋ 添加一张横幅」上传。</p>
          @endforelse
          <div class="mid-new"></div>
        </div>
      </div>
    @elseif(! $hasHeading)
      {{-- 纯数据/纯横幅区块：不渲染标题控件，说明已收进区块标题旁 Tooltip --}}
    @else
      @if($hasSubtitle)
        <div class="grid2">
          <label class="fl">区块标题
            <input type="text" name="title" value="{{ $block->title }}" placeholder="留空用默认标题">
          </label>
          <label class="fl">副标题 / 说明
            <input type="text" name="subtitle" value="{{ $block->subtitle }}" placeholder="可选">
          </label>
        </div>
      @else
        <label class="fl mb-3">区块标题
          <input type="text" name="title" value="{{ $block->title }}" placeholder="留空用默认标题">
        </label>
      @endif
    @endif

    {{-- 可增删条目：能力点 / 场景 / 流程 --}}
    @if(in_array($kind, ['items','steps']))
      @php
        $fields = config('home_blocks.types.'.$block->type.'.fields', []);
        $hasIcon = in_array('icon', $fields, true);
        $hasText = in_array('text', $fields, true);
        $hasLink = in_array('link', $fields, true);
        // 不同条目区块前台图片位比例不同，给出准确的推荐尺寸（object-fit:cover，等比即可，会自动裁切）
        $imgSizeHint = match($block->type){
          'scenes' => '宽幅横图约 3:1，建议 720×240',
          'cases'  => '宽幅横图约 5:2，建议 720×300',
          default  => '方形图标 1:1，建议 256×256',
        };
      @endphp
      <div class="items-editor" data-rowprefix="items">
        <table class="tbl item-tbl" data-icon="@json($hasIcon)" data-text="@json($hasText)" data-link="@json($hasLink)">
          <thead><tr>
            @if($hasIcon)<th class="w-150">内置图标</th><th class="w-230">自定义图片 / 图标（优先）<span class="size-tag">{{ $imgSizeHint }}</span></th>@endif
            <th class="w-190">标题</th>
            @if($hasText)<th>说明文案</th>@endif
            @if($hasLink)<th class="w-200">跳转链接（留空不可点）</th>@endif
            <th class="w-60"></th>
          </tr></thead>
          <tbody class="items-body">
          @foreach($items as $i => $it)
            <tr class="item-row">
              @if($hasIcon)
                <td>
                  <div class="icon-pick">
                    <span class="iprev">
                      @if(!empty($it['image']))
                        <img src="{{ $it['image'] }}" alt="" class="iprev-img">
                      @else
                        @include('site._icon', ['name' => $it['icon'] ?? 'default', 'size' => 20])
                      @endif
                    </span>
                    <select name="items[{{ $i }}][icon]" class="icon-select">
                      <option value="" @selected(($it['icon'] ?? '')==='')>（无图标）</option>
                      @foreach($iconOptions as $key => $zh)
                        <option value="{{ $key }}" @selected(($it['icon'] ?? '')===$key)>{{ $zh }}</option>
                      @endforeach
                    </select>
                  </div>
                </td>
                <td>
                  <div class="img-edit">
                    @if(!empty($it['image']))
                      <img src="{{ $it['image'] }}" alt="" class="row-thumb">
                      <label class="checkline small"><input type="checkbox" name="items[{{ $i }}][image_remove]" value="1"> 移除</label>
                    @endif
                    <input type="hidden" name="items[{{ $i }}][image]" value="{{ $it['image'] ?? '' }}">
                    <input type="file" name="items[{{ $i }}][image_file]" accept="image/*" class="row-file">
                  </div>
                </td>
              @endif
              <td>
                <input type="text" name="items[{{ $i }}][title]" value="{{ $it['title'] ?? '' }}">
                @foreach($it as $ek => $ev)
                  @if(!in_array($ek, ['icon','image','title','text','link'], true))
                    @if(is_array($ev))
                      @foreach($ev as $evv)
                        <input type="hidden" name="items[{{ $i }}][{{ $ek }}][]" value="{{ $evv }}">
                      @endforeach
                    @else
                      <input type="hidden" name="items[{{ $i }}][{{ $ek }}]" value="{{ $ev }}">
                    @endif
                  @endif
                @endforeach
              </td>
              @if($hasText)<td><input type="text" name="items[{{ $i }}][text]" value="{{ $it['text'] ?? '' }}"></td>@endif
              @if($hasLink)<td><input type="text" name="items[{{ $i }}][link]" value="{{ $it['link'] ?? '' }}" placeholder="/solutions/xxx/"></td>@endif
              <td><button type="button" class="btn btn-sm del-row">删除</button></td>
            </tr>
          @endforeach
          </tbody>
        </table>
        <button type="button" class="btn btn-sm add-row mt-2">+ 添加一项</button>
      </div>

    {{-- 数据来源型：栏目 + 条数 + 手动指定 --}}
    @elseif($kind === 'source')
      <div class="grid2">
        <label class="fl"><span class="label-with-tip">来源栏目 <x-admin-tip text="不手动指定内容时，自动取该栏目下最新发布的内容。"/></span>
          <select name="category_id">
            <option value="">—</option>
            @foreach($categories as $c)
              <option value="{{ $c->id }}" @selected($block->category_id==$c->id)>{{ str_repeat('—', $c->depth()) }} {{ $c->name }}</option>
            @endforeach
          </select>
        </label>
        <label class="fl">取最新条数
          <input type="number" name="limit" value="{{ $block->limit }}" min="1" max="50">
        </label>
      </div>
      <label class="fl"><span class="label-with-tip">手动指定展示内容 <x-admin-tip text="按住 Ctrl 多选；勾选后忽略「取最新条数」，按所选顺序展示；不选则自动取最新内容。"/></span>
        <select name="pick_ids[]" multiple size="6" class="pick-multi">
          @foreach($contents as $c)
            <option value="{{ $c->id }}" @selected(in_array($c->id, $picked, true))>
              [{{ $c->category->name ?? '-' }}] {{ $c->title }}
            </option>
          @endforeach
        </select>
      </label>
    @endif
  </form>
@endforeach

@if(session('success'))<div class="toast-ok">{{ session('success') }}</div>@endif

<script>
(function(){
  // 首屏模式点选卡：高亮当前卡 + 只显示对应模式的配图面板
  document.querySelectorAll('.hero-edit').forEach(function(box){
    var cards = box.querySelectorAll('.hmode-card'), panels = box.querySelectorAll('.hpanel');
    var slidesPanel = box.querySelector('[data-slides]');
    function sync(mode){
      cards.forEach(function(c){ c.classList.toggle('sel', c.dataset.mode === mode); });
      panels.forEach(function(p){
        if(p.hasAttribute('data-slides')){ p.classList.toggle('on', mode==='B' || mode==='C'); }
        else { p.classList.toggle('on', p.dataset.hpanel === mode); }
      });
    }
    cards.forEach(function(c){
      c.addEventListener('click', function(){ sync(c.dataset.mode); });
    });
    box.querySelectorAll('input[name="hero_mode"]').forEach(function(r){
      r.addEventListener('change', function(){ sync(r.value); }); // 键盘切换
    });
    sync(box.dataset.current || 'A');
  });

  // 图标预览联动
  document.addEventListener('change', function(e){
    var sel = e.target.closest('.icon-select');
    if(!sel) return;
    var box = sel.closest('.icon-pick');
    // 用中文名首字即时占位，保存刷新后显示真实线性图标
    box.querySelector('.iprev').textContent = sel.selectedOptions[0].text.slice(0,1);
  });

  document.querySelectorAll('.items-editor').forEach(function(editor){
    var body = editor.querySelector('.items-body');
    var tbl  = editor.querySelector('.item-tbl');
    var hasIcon = tbl.dataset.icon === 'true';
    var hasText = tbl.dataset.text === 'true';
    var hasLink = tbl.dataset.link === 'true';
    var iconOpts = @json($iconOptions);

    function renumber(){
      body.querySelectorAll('.item-row').forEach(function(row, idx){
        row.querySelectorAll('[name^="items["]').forEach(function(inp){
          inp.name = inp.name.replace(/items\[\d+\]/, 'items['+idx+']');
        });
      });
    }
    editor.querySelector('.add-row').addEventListener('click', function(){
      var tr = document.createElement('tr');
      tr.className = 'item-row';
      var html = '';
      if(hasIcon){
        var opts = Object.keys(iconOpts).map(function(k){
          return '<option value="'+k+'">'+iconOpts[k]+'</option>';
        }).join('');
        html += '<td><div class="icon-pick"><span class="iprev">•</span><select name="items[0][icon]">'+opts+'</select></div></td>';
        html += '<td><div class="img-edit">'+
                '<input type="hidden" name="items[0][image]" value="">'+
                '<input type="file" name="items[0][image_file]" accept="image/*" class="row-file"></div></td>';
      }
      html += '<td><input type="text" name="items[0][title]"></td>';
      if(hasText){ html += '<td><input type="text" name="items[0][text]"></td>'; }
      if(hasLink){ html += '<td><input type="text" name="items[0][link]" placeholder="/solutions/xxx/"></td>'; }
      html += '<td><button type="button" class="btn btn-sm del-row">删除</button></td>';
      tr.innerHTML = html;
      body.appendChild(tr);
      renumber();
    });
    body.addEventListener('click', function(e){
      var btn = e.target.closest('.del-row');
      if(!btn) return;
      btn.closest('.item-row').remove();
      renumber();
    });
  });

  // 首屏 / 中部横幅：新增一张（new_slides，必须选图才会真正创建）
  function bindAddNew(btnSel, wrapSel, prefix, btnLabel, sizeHint){
    var btn = document.querySelector(btnSel);
    var wrap = document.querySelector(wrapSel);
    if(!btn || !wrap) return;
    function renumber(){
      wrap.querySelectorAll('.slide-new-row').forEach(function(row, idx){
        row.querySelectorAll('[data-name]').forEach(function(inp){
          inp.name = prefix + '[' + idx + '][' + inp.dataset.name + ']';
        });
      });
    }
    btn.addEventListener('click', function(){
      var row = document.createElement('div');
      row.className = 'slide-row slide-new-row';
      row.innerHTML =
        '<div class="sr-img"><input type="file" data-name="image_file" accept="image/*" class="row-file" required><span class="size-tag">'+(sizeHint||'')+'</span></div>'+
        '<div class="sr-fields">'+
          '<div class="grid2">'+
            '<label class="fl">主标题<input type="text" data-name="title" maxlength="120" placeholder="这一张的主标题（可留空用默认）"></label>'+
            '<label class="fl">'+btnLabel+'<input type="text" data-name="link_text" maxlength="40" placeholder="按钮文字，可留空"></label>'+
          '</div>'+
          '<label class="fl">正文段落<textarea data-name="subtitle" rows="2" maxlength="300" placeholder="这一张的正文，可留空"></textarea></label>'+
          '<div class="grid2">'+
            '<label class="fl">跳转链接<input type="text" data-name="link" placeholder="/cooperation/ 或 http(s)://，可留空"></label>'+
            '<label class="fl align-end"><button type="button" class="btn btn-sm del-new">移除这张</button></label>'+
          '</div>'+
        '</div>';
      wrap.appendChild(row);
      renumber();
    });
    wrap.addEventListener('click', function(e){
      var d = e.target.closest('.del-new');
      if(!d) return;
      d.closest('.slide-new-row').remove();
      renumber();
    });
  }
  bindAddNew('.add-slide', '.slide-new', 'new_slides', '按钮文字', 'B 全幅 1920×640（3:1）；C 背景 2048×900，暗调、主体在右');
  bindAddNew('.add-mid', '.mid-new', 'new_slides', '按钮文字', '通栏 4:1，建议 2400×600（移动端按 16:9 裁切）');
})();
</script>
@endsection
