{{--
  通用 Markdown 编辑器局部（内容管理 / 页面文案共用）
  参数：
    $mdUid         页面内唯一标识（默认 content）
    $mdName        textarea 的 name（默认 body）
    $mdValue       当前 Markdown 正文
    $mdRows        行数（默认 16）
    $mdPlaceholder 占位提示
    $mdHint        底部小提示
    $mdUploadUrl   正文插图上传端点（默认媒体库 inline）
    $mdPreviewUrl  Markdown 预览端点（默认内容 md-preview）
--}}
@php
  $mdUid = $mdUid ?? 'content';
  $mdName = $mdName ?? 'body';
  $mdRows = $mdRows ?? 16;
  $mdPlaceholder = $mdPlaceholder ?? '支持 Markdown 标题、列表、表格、图片；从 Word/网页直接粘贴会自动转成 Markdown；事实表述须与事实库一致';
  $mdHint = $mdHint ?? '小提示：工具条只是帮你快速生成 Markdown，底层始终是纯文本，便于后期 GEOFlow/Workflow 自动推送与 AI 理解；图片建议正文宽图 1280px 以内、jpg/webp。';
  $mdUploadUrl = $mdUploadUrl ?? route('admin.media.inline');
  $mdPreviewUrl = $mdPreviewUrl ?? route('admin.contents.md-preview');
@endphp
<div class="md-editor"
     data-upload-url="{{ $mdUploadUrl }}"
     data-preview-url="{{ $mdPreviewUrl }}">
  <div class="md-toolbar" role="toolbar" aria-label="正文排版工具">
    <button type="button" data-cmd="h2" title="二级标题">H2</button>
    <button type="button" data-cmd="h3" title="三级标题">H3</button>
    <span class="mdt-sep"></span>
    <button type="button" data-cmd="bold" title="加粗"><b>B</b></button>
    <button type="button" data-cmd="italic" title="斜体"><i>I</i></button>
    <button type="button" data-cmd="quote" title="引用">“”</button>
    <span class="mdt-sep"></span>
    <button type="button" data-cmd="ul" title="无序列表">• 列表</button>
    <button type="button" data-cmd="ol" title="有序列表">1. 列表</button>
    <button type="button" data-cmd="table" title="插入表格">表格</button>
    <span class="mdt-sep"></span>
    <button type="button" data-cmd="link" title="插入链接">链接</button>
    <button type="button" data-cmd="image" title="上传并插入图片">上传图片</button>
    <input type="file" accept="image/*" hidden>
    <span class="mdt-spacer"></span>
    <button type="button" data-cmd="preview" class="mdt-preview">预览</button>
    <button type="button" data-cmd="edit" class="mdt-edit" hidden>继续编辑</button>
    <x-admin-tip type="help" place="bottom" :width="300" text="{{ $mdHint }}"/>
  </div>
  <textarea name="{{ $mdName }}" rows="{{ $mdRows }}" placeholder="{{ $mdPlaceholder }}">{{ $mdValue ?? '' }}</textarea>
  <div class="md-preview" hidden></div>
</div>

@once
@push('scripts')
<script>
/* 通用 Markdown 编辑器：页面内每个 .md-editor 独立初始化（工具条/插图/表格/预览/粘贴转换） */
(function(){
  function initEditor(box){
    const ta=box.querySelector('textarea');
    const pv=box.querySelector('.md-preview');
    const fileInput=box.querySelector('input[type=file]');
    if(!ta) return;
    const csrf=document.querySelector('meta[name="csrf-token"]')?.content || '';
    const uploadUrl=box.dataset.uploadUrl, previewUrl=box.dataset.previewUrl;

    function lineBounds(){
      const v=ta.value, s=ta.selectionStart, e=ta.selectionEnd;
      const ls=v.lastIndexOf('\n',s-1)+1;
      let le=v.indexOf('\n',e); if(le===-1) le=v.length;
      return {ls,le,s,e};
    }
    function prefixLines(prefix, ordered){
      const b=lineBounds(); const v=ta.value;
      const block=v.slice(b.ls,b.le);
      const lines=block.split('\n');
      let n=1;
      const out=lines.map((ln)=>{
        if(ordered){ return /^\s*\d+\.\s/.test(ln)?ln:(n++ +'. '+ln.replace(/^\s+/,'')); }
        return ln.startsWith(prefix)?ln:prefix+ln;
      });
      ta.setSelectionRange(b.ls,b.le);
      insertText(out.join('\n'));
    }
    function wrap(open,close,placeholder){
      const s=ta.selectionStart,e=ta.selectionEnd,v=ta.value;
      const sel=v.slice(s,e)||placeholder;
      let text;
      if(open==='['){ text='['+sel+'](https://)'; }
      else { text=open+sel+(close||open); }
      ta.focus();
      document.execCommand('insertText',false,text);
      if(!v.slice(s,e)){
        if(open==='['){ ta.setSelectionRange(s+text.length-8,s+text.length-1); }
        else { ta.setSelectionRange(s+open.length,s+open.length+(sel.length)); }
      }
    }
    function insertText(text){ ta.focus(); document.execCommand('insertText',false,text); }

    const TABLE_TPL='\n| 列1 | 列2 | 列3 |\n| --- | --- | --- |\n| 内容 | 内容 | 内容 |\n| 内容 | 内容 | 内容 |\n\n';

    async function uploadImage(file){
      const fd=new FormData(); fd.append('file',file); fd.append('alt','');
      const r=await fetch(uploadUrl,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},body:fd});
      if(!r.ok){ let msg='图片上传失败'; try{const j=await r.json(); msg=(j.message||msg);}catch(e){} alert(msg); return; }
      const j=await r.json();
      insertText('\n!['+(j.alt||file.name.replace(/\.[^.]+$/,''))+']('+j.url+')\n');
    }

    async function togglePreview(){
      const btnP=box.querySelector('[data-cmd=preview]'), btnE=box.querySelector('[data-cmd=edit]');
      if(pv.hidden){
        btnP.hidden=true; btnE.hidden=false;
        pv.innerHTML='<span class="small muted">渲染中…</span>';
        const fd=new FormData(); fd.append('text',ta.value);
        const r=await fetch(previewUrl,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},body:fd});
        const j=await r.json();
        pv.innerHTML=j.html||'<p class="muted">（暂无内容）</p>';
        ta.hidden=true; pv.hidden=false;
      }
    }
    function backToEdit(){
      const btnP=box.querySelector('[data-cmd=preview]'), btnE=box.querySelector('[data-cmd=edit]');
      btnP.hidden=false; btnE.hidden=true; pv.hidden=true; ta.hidden=false; ta.focus();
    }

    box.querySelector('.md-toolbar').addEventListener('click',function(e){
      const btn=e.target.closest('button[data-cmd]'); if(!btn) return;
      const cmd=btn.dataset.cmd; ta.focus();
      switch(cmd){
        case 'h2': prefixLines('## '); break;
        case 'h3': prefixLines('### '); break;
        case 'quote': prefixLines('> '); break;
        case 'ul': prefixLines('- '); break;
        case 'ol': prefixLines('',true); break;
        case 'bold': wrap('**'); break;
        case 'italic': wrap('*'); break;
        case 'link': wrap('['); break;
        case 'table': insertText(TABLE_TPL); break;
        case 'image': fileInput.click(); break;
        case 'preview': togglePreview(); break;
        case 'edit': backToEdit(); break;
      }
    });
    fileInput.addEventListener('change',function(){ if(fileInput.files&&fileInput.files[0]) uploadImage(fileInput.files[0]); fileInput.value=''; });

    function htmlToMd(html){
      const tpl=document.createElement('div'); tpl.innerHTML=html;
      function esc(s){ return (s||'').replace(/\s+\n/g,'\n').replace(/[ \t]+/g,' ').trim(); }
      function node(nodeEl){
        let out='';
        nodeEl.childNodes.forEach(function(ch){
          if(ch.nodeType===3){ out+=ch.textContent; return; }
          if(ch.nodeType!==1) return;
          const tag=ch.tagName.toLowerCase(), inner=node(ch);
          switch(tag){
            case 'h1': case 'h2': out+='\n\n## '+esc(inner)+'\n\n'; break;
            case 'h3': case 'h4': out+='\n\n### '+esc(inner)+'\n\n'; break;
            case 'p': case 'div': out+='\n\n'+inner+'\n\n'; break;
            case 'br': out+='\n'; break;
            case 'strong': case 'b': out+='**'+inner+'**'; break;
            case 'em': case 'i': out+='*'+inner+'*'; break;
            case 'a': out+='['+inner+']('+(ch.getAttribute('href')||'')+')'; break;
            case 'img': out+='!['+(ch.getAttribute('alt')||'')+']('+(ch.getAttribute('src')||'')+')'; break;
            case 'blockquote': out+='\n\n'+inner.split('\n').map(l=>'> '+l.trim()).join('\n')+'\n\n'; break;
            case 'ul': out+='\n'+[...ch.querySelectorAll(':scope > li')].map(li=>'- '+node(li).trim()).join('\n')+'\n'; break;
            case 'ol': let i=1; out+='\n'+[...ch.querySelectorAll(':scope > li')].map(li=>(i++)+'. '+node(li).trim()).join('\n')+'\n'; break;
            case 'table':
              const rows=[...ch.querySelectorAll('tr')].map(tr=>[...tr.querySelectorAll('th,td')].map(td=>esc(node(td)).replace(/\|/g,'/')));
              if(rows.length){ out+='\n\n| '+rows[0].join(' | ')+' |\n| '+rows[0].map(()=>'---').join(' | ')+' |\n'+
                rows.slice(1).map(r=>'| '+r.join(' | ')+' |').join('\n')+'\n\n'; }
              break;
            default: out+=inner;
          }
        });
        return out;
      }
      return node(tpl).replace(/\n{3,}/g,'\n\n').replace(/[ \t]+\n/g,'\n').trim();
    }
    ta.addEventListener('paste',function(e){
      const html=e.clipboardData&&e.clipboardData.getData('text/html');
      if(!html) return;
      const md=htmlToMd(html);
      if(!md) return;
      e.preventDefault();
      insertText(md);
    });
  }
  document.querySelectorAll('.md-editor').forEach(initEditor);
})();
</script>
@endpush
@endonce
