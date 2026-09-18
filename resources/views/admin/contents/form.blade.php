@extends('admin.layout')
@section('title', $content->exists ? '编辑内容' : '新建内容')
@section('page-desc','正文支持 Markdown 工具栏与 Word / 网页粘贴转换；发布前自动运行 GEO 内容门禁。')

@php
  // 旧输入回填（校验失败返回时），否则取模型已存数据
  $evLabels = old('ev_label');
  if (is_array($evLabels)) {
      $evRows = [];
      foreach ($evLabels as $i => $lab) {
          $evRows[] = ['label'=>$lab,'value'=>old("ev_value.$i"),'source'=>old("ev_source.$i"),'url'=>old("ev_url.$i")];
      }
  } else {
      $evRows = $content->evidenceList();
  }
  if (! $evRows) { $evRows = [['label'=>'','value'=>'','source'=>'','url'=>'']]; }

  $faqQs = old('faq_q');
  if (is_array($faqQs)) {
      $faqRows = [];
      foreach ($faqQs as $i => $q) { $faqRows[] = ['q'=>$q,'a'=>old("faq_a.$i")]; }
  } else { $faqRows = $content->faqList(); }
  if (! $faqRows) { $faqRows = [['q'=>'','a'=>'']]; }

  $kfKeys = old('kf_key');
  if (is_array($kfKeys)) {
      $kfRows = [];
      foreach ($kfKeys as $i => $k) { $kfRows[] = ['key'=>$k,'value'=>old("kf_value.$i")]; }
  } else { $kfRows = $content->keyFactList(); }
  if (! $kfRows) { $kfRows = [['key'=>'','value'=>'']]; }

  $refKeys = old('fact_refs', $content->fact_refs ?: []);
@endphp

@section('content')
<form id="contentForm" method="post"
      action="{{ $content->exists ? route('admin.contents.update',$content) : route('admin.contents.store') }}"
      enctype="multipart/form-data">
@csrf @if($content->exists) @method('PUT') @endif

<div class="editor-layout">
  {{-- ============ 主栏 ============ --}}
  <div class="editor-main">
    <div class="card">
      <h2>基础信息</h2>
      <div class="form-row">
        <label>标题 <span class="req">*</span></label>
        <input type="text" name="title" value="{{ old('title',$content->title) }}" required>
      </div>
      <div class="form-grid">
        <div class="form-row"><label><span class="label-with-tip">类型 <span class="req">*</span><x-admin-tip text="文章 / 新闻、单页、产品三类；保存后类型不可修改。"/></span></label>
          <select name="type" @if($content->exists) disabled @endif>
            @foreach(['article'=>'文章 / 新闻','page'=>'单页','product'=>'产品'] as $vk=>$vn)
              <option value="{{ $vk }}" @selected(old('type',$content->type)===$vk)>{{ $vn }}</option>
            @endforeach
          </select>
          @if($content->exists)<input type="hidden" name="type" value="{{ $content->type }}">@endif
        </div>
        <div class="form-row"><label><span class="label-with-tip">slug <x-admin-tip text="URL 标识，如 /knowledge/{slug}/；仅允许小写字母、数字、连字符，留空按标题自动生成。"/></span></label>
          <input type="text" name="slug" value="{{ old('slug',$content->slug) }}"></div>
      </div>
      <div class="form-row"><label><span class="label-with-tip">摘要 / 页头导语 <x-admin-tip text="同时用于 SEO meta description 与 AI 摘要，建议 1～2 句话概括全文结论。"/></span></label>
        <textarea name="summary" rows="2">{{ old('summary',$content->summary) }}</textarea></div>
      <div class="form-row mb-0"><label><span class="label-with-tip">正文 <x-admin-tip text="支持 Markdown；工具栏可插入图片 / 表格，也可直接粘贴 Word 或网页内容。"/></span></label>
        @include('admin.partials.md-editor', ['mdUid'=>'bodyMd','mdName'=>'body','mdValue'=>old('body',$content->body)])
      </div>
    </div>

    {{-- ============ GEO 四层 ============ --}}
    <div class="card">
      <h2><span class="label-with-tip">GEO 四层答案结构 <x-admin-tip type="help" text="让 AI 能准确复述：结论 → 解释 → 证据 → 边界，并配 FAQ。四层为发布门禁必填项。"/></span></h2>

      <div class="geo-layer">
        <h3><span class="label-with-tip">① 结论层 <x-admin-tip text="一段话直接回答“是什么 / 给谁 / 解决什么”，作为答案块首段。"/></span></h3>
        <textarea name="geo_conclusion" rows="2">{{ old('geo_conclusion',$content->geo_conclusion) }}</textarea>
      </div>

      <div class="geo-layer">
        <h3><span class="label-with-tip">② 解释层 <x-admin-tip text="说明为什么、怎么做，包含关键实体与参数（工艺 / 规格 / 口味 / 产能 / 交付）。"/></span></h3>
        <textarea name="geo_explanation" rows="4">{{ old('geo_explanation',$content->geo_explanation) }}</textarea>
      </div>

      <div class="geo-layer">
        <h3><span class="label-with-tip">③ 证据层 <x-admin-tip type="warning" text="参数 / 事实至少 2 条，且每条必须填写来源；也可直接勾选下方事实库条目。"/></span></h3>
        <div id="evidenceWrap">
          @foreach($evRows as $ev)
            <div class="repeat-row">
              <div class="repeat-item">
                <input type="text" name="ev_label[]" value="{{ $ev['label'] ?? '' }}" placeholder="参数名（如 年产能）">
                <input type="text" name="ev_value[]" value="{{ $ev['value'] ?? '' }}" placeholder="值（如 约 8000 吨）">
                <input type="text" name="ev_source[]" value="{{ $ev['source'] ?? '' }}" placeholder="来源（必填，如 事实库）">
                <input type="text" name="ev_url[]" value="{{ $ev['url'] ?? '' }}" placeholder="来源 URL（可空）">
                <button type="button" class="btn btn-sm btn-danger del-repeat">删</button>
              </div>
            </div>
          @endforeach
        </div>
        <button type="button" class="btn btn-sm mt-2" id="addEvidence">＋ 添加证据</button>

        @if($facts->isNotEmpty())
          <div class="form-row mt-3 mb-0">
            <label class="small muted"><span class="label-with-tip">引用事实库条目 <x-admin-tip text="勾选即纳入证据口径，对外值以事实库为准，避免同一数字多处不一致。"/></span></label>
            <div class="check-box">
              @foreach($facts as $f)
                <label class="checkline">
                  <input type="checkbox" name="fact_refs[]" value="{{ $f->key }}"
                    @checked(in_array($f->key, (array)$refKeys, true))>
                  {{ $f->label }}：{{ mb_substr((string)$f->value,0,40) }} <span class="small muted">[{{ $f->key }}]</span>
                </label>
              @endforeach
            </div>
          </div>
        @endif
      </div>

      <div class="geo-layer">
        <h3><span class="label-with-tip">④ 边界层 <x-admin-tip text="写明不适用场景、限制条件或客观边界，避免 AI 过度承诺。"/></span></h3>
        <textarea name="geo_boundary" rows="3">{{ old('geo_boundary',$content->geo_boundary) }}</textarea>
      </div>

      <div class="geo-layer plain mb-0">
        <h3><span class="label-with-tip">FAQ <x-admin-tip text="问题 + 直接答案，发布后同时生成 FAQPage 结构化数据。"/></span></h3>
        <div id="faqWrap">
          @foreach($faqRows as $fq)
            <div class="repeat-row">
              <div class="repeat-item faq">
                <input type="text" name="faq_q[]" value="{{ $fq['q'] ?? '' }}" placeholder="客户高频问题">
                <input type="text" name="faq_a[]" value="{{ $fq['a'] ?? '' }}" placeholder="直接答案">
                <button type="button" class="btn btn-sm btn-danger del-repeat">删</button>
              </div>
            </div>
          @endforeach
        </div>
        <button type="button" class="btn btn-sm mt-2" id="addFaq">＋ 添加 FAQ</button>

        <hr class="hr-soft">
        <h3><span class="label-with-tip">关键事实句 <x-admin-tip text="供 AI 与摘要引用，须与事实库口径一致；填写“事实名 + 事实值”。"/></span></h3>
        <div id="kfWrap">
          @foreach($kfRows as $kf)
            <div class="repeat-row">
              <div class="repeat-item kf">
                <input type="text" name="kf_key[]" value="{{ $kf['key'] ?? '' }}" placeholder="事实名（如 年产能）">
                <input type="text" name="kf_value[]" value="{{ $kf['value'] ?? '' }}" placeholder="事实值（如 约 8,000 吨）">
                <button type="button" class="btn btn-sm btn-danger del-repeat">删</button>
              </div>
            </div>
          @endforeach
        </div>
        <button type="button" class="btn btn-sm mt-2" id="addKf">＋ 添加关键事实</button>
      </div>
    </div>

    {{-- ============ SEO ============ --}}
    <div class="card">
      <h2><span class="label-with-tip">SEO 设置 <x-admin-tip text="两项均为可选项，留空则按标题、摘要规则自动生成。"/></span></h2>
      <div class="form-row"><label><span class="label-with-tip">SEO Title <x-admin-tip text="浏览器标签与搜索结果标题，建议不超过 60 字。"/></span></label>
        <input type="text" name="seo_title" value="{{ old('seo_title',$content->seo_title) }}"></div>
      <div class="form-row mb-0"><label><span class="label-with-tip">Meta Description <x-admin-tip text="搜索结果摘要，建议不超过 150 字；留空取正文摘要。"/></span></label>
        <textarea name="seo_desc" rows="2">{{ old('seo_desc',$content->seo_desc) }}</textarea></div>
    </div>
  </div>

  {{-- ============ 侧栏 ============ --}}
  <div class="editor-side">
    <div class="card">
      <h2>归属</h2>
      <div class="form-row"><label>栏目 <span class="req">*</span></label>
        <select name="category_id" form="contentForm" required>
          <option value="">请选择</option>
          @foreach($categories as $c)
            <option value="{{ $c->id }}" @selected(old('category_id',$content->category_id)==$c->id)>
              {{ str_repeat('—', $c->depth()) }} {{ $c->name }}
            </option>
            @foreach($c->children as $ch)
              <option value="{{ $ch->id }}" @selected(old('category_id',$content->category_id)==$ch->id)>
                — {{ $ch->name }}
              </option>
            @endforeach
          @endforeach
        </select></div>
      <div class="form-row mb-0"><label>知识分组（可空）</label>
        <select name="group_id" form="contentForm">
          <option value="">— 无分组 —</option>
          @foreach($groups as $g)
            <option value="{{ $g->id }}" @selected(old('group_id',$content->group_id)==$g->id)>
              {{ optional($g->category)->name }} / {{ $g->name }}
            </option>
          @endforeach
        </select></div>
    </div>

    <div class="card">
      <h2>封面图</h2>
      <div class="cover-preview">
        @if($url = optional($content->cover)->url())
          <img id="coverPreview" src="{{ $url }}" alt="封面预览">
        @else
          <img id="coverPreview" alt="" hidden>
        @endif
        <input type="file" name="cover_file" id="coverFile" class="file-input" accept="image/*">
        @if($url)
          <label class="checkline mt-2"><input type="checkbox" name="cover_remove" value="1"> 移除当前封面</label>
        @endif
        <div class="help">文章封面建议 1280×720（16:9），≤6MB，jpg/png/webp；上传自动入媒体库。</div>
      </div>
    </div>

    <div class="card">
      <h2>发布纪律</h2>
      <div class="form-row"><label><span class="label-with-tip">责任人 <span class="req">*</span><x-admin-tip text="发布门禁必填，填写对本页内容真实性负责的部门或姓名。"/></span></label>
        <input type="text" name="owner" value="{{ old('owner',$content->owner) }}" placeholder="如：市场部 / 姓名"></div>
      <div class="form-row"><label>发布时间</label>
        <input type="datetime-local" name="published_at"
               value="{{ old('published_at', optional($content->published_at)->format('Y-m-d\TH:i')) }}"></div>
      <div class="form-row"><label><span class="label-with-tip">最后复核日期 <span class="req">*</span><x-admin-tip text="发布门禁必填，表示内容事实最近一次核对的日期。"/></span></label>
        <input type="date" name="reviewed_at" value="{{ old('reviewed_at', optional($content->reviewed_at)->format('Y-m-d')) }}"></div>
      <div class="form-row"><label><span class="label-with-tip">下次复核日期 <x-admin-tip text="建议每年至少复核一次；到期后会在仪表盘提醒。"/></span></label>
        <input type="date" name="review_due" value="{{ old('review_due', optional($content->review_due)->format('Y-m-d')) }}"></div>
      <div class="form-row"><label>来源 / 备注</label>
        <input type="text" name="source_note" value="{{ old('source_note',$content->source_note) }}" placeholder="如：企业内部资料 / 官网"></div>
      <label class="checkline"><input type="checkbox" name="lock_manual" value="1" @checked(old('lock_manual',$content->lock_manual))>
        🔒 人工锁定（GEOFlow 等外部推送不得覆盖本页）</label>
      <label class="checkline mb-0"><input type="checkbox" name="noindex" value="1" @checked(old('noindex',$content->noindex))>
        noindex（不被搜索引擎收录，仅特殊页面使用）</label>
    </div>

    <div class="save-bar">
      <button class="btn btn-primary" type="submit">保存</button>
      <button type="button" class="btn" id="btnCheck">门禁预检</button>
      <div class="save-spacer"></div>
      @if($content->exists)
        @if($content->status==='published')
          <form class="form-inline" method="post" action="{{ route('admin.contents.unpublish',$content) }}">@csrf
            <button class="btn">下架为草稿</button></form>
        @else
          <form class="form-inline" method="post" action="{{ route('admin.contents.publish',$content) }}">@csrf
            <button class="btn btn-ok">发布</button></form>
        @endif
        <a class="btn btn-sm" href="{{ route('admin.contents.revisions',$content) }}">版本记录</a>
      @endif
      <span id="checkResult"></span>
      <div>
        @if($content->status==='published')<span class="badge published">已发布</span>
        @elseif($content->status==='archived')<span class="badge archived">已归档</span>
        @else<span class="badge draft">草稿</span>@endif
      </div>
    </div>
    <p class="small muted mt-2 mb-0"><span class="label-with-tip">发布说明 <x-admin-tip type="warning" text="发布 / 下架针对已保存的内容：请先点「保存」再「发布」；GEO 门禁不通过会拒绝发布。"/></span></p>
  </div>
</div>
</form>
@endsection

@push('scripts')
<script>
function addRepeat(wrapId, html){
  var wrap=document.getElementById(wrapId); if(!wrap) return;
  var row=document.createElement('div'); row.className='repeat-row';
  row.innerHTML='<div class="repeat-item '+(wrapId==='faqWrap'?'faq':wrapId==='kfWrap'?'kf':'')+'">'+html+'</div>';
  wrap.appendChild(row);
}
document.getElementById('addEvidence')?.addEventListener('click',function(){
  addRepeat('evidenceWrap',
    '<input type="text" name="ev_label[]" placeholder="参数名">'+
    '<input type="text" name="ev_value[]" placeholder="值">'+
    '<input type="text" name="ev_source[]" placeholder="来源（必填）">'+
    '<input type="text" name="ev_url[]" placeholder="来源 URL（可空）">'+
    '<button type="button" class="btn btn-sm btn-danger del-repeat">删</button>');
});
document.getElementById('addFaq')?.addEventListener('click',function(){
  addRepeat('faqWrap','<input type="text" name="faq_q[]" placeholder="问题"><input type="text" name="faq_a[]" placeholder="直接答案"><button type="button" class="btn btn-sm btn-danger del-repeat">删</button>');
});
document.getElementById('addKf')?.addEventListener('click',function(){
  addRepeat('kfWrap','<input type="text" name="kf_key[]" placeholder="事实名"><input type="text" name="kf_value[]" placeholder="事实值"><button type="button" class="btn btn-sm btn-danger del-repeat">删</button>');
});
document.addEventListener('click',function(e){
  if(e.target.classList.contains('del-repeat')){ e.target.closest('.repeat-row').remove(); }
});
document.getElementById('coverFile')?.addEventListener('change',function(e){
  var f=e.target.files[0]; if(!f) return;
  var img=document.getElementById('coverPreview');
  img.src=URL.createObjectURL(f); img.hidden=false;
});
document.getElementById('btnCheck')?.addEventListener('click',function(){
  var form=document.getElementById('contentForm');
  var data=new FormData(form);
  var out=document.getElementById('checkResult');
  out.innerHTML='<span class="small muted">检查中…</span>';
  fetch('{{ route('admin.contents.check') }}',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:data})
    .then(function(r){return r.json();})
    .then(function(j){
      if(j.passed){
        out.innerHTML='<span class="badge ok">通过，可以发布</span>'+(j.warnings&&j.warnings.length?'<div class="small text-warn mt-1">'+j.warnings.map(function(x){return '· '+x;}).join('<br>')+'</div>':'');
      } else {
        out.innerHTML='<span class="badge err">未通过</span><div class="small text-err mt-1">'+(j.errors||[]).map(function(x){return '· '+x;}).join('<br>')+'</div>';
      }
    }).catch(function(){ out.innerHTML='<span class="badge err">检查失败</span>'; });
});
</script>
@endpush
