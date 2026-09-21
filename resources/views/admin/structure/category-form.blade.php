@extends('admin.layout')
@section('title', $category->exists ? '编辑栏目' : '新建栏目')
@section('page-desc','栏目用于前台导航与内容归类，最多两级。类型决定前台呈现：列表页、单页、产品列表或外链跳转。')

@section('content')
<div class="card narrow">
  <h2>{{ $category->exists ? '编辑栏目：'.$category->name : '新建栏目' }}</h2>
  <form method="post"
        action="{{ $category->exists ? route('admin.categories.update',$category) : route('admin.categories.store') }}">
    @csrf @if($category->exists)@method('PUT')@endif
    <div class="form-grid">
      <div class="form-row"><label>栏目名 <span class="req">*</span></label>
        <input type="text" name="name" value="{{ old('name',$category->name) }}" required></div>
      <div class="form-row"><label>slug <span class="req">*</span></label>
        <input type="text" name="slug" value="{{ old('slug',$category->slug) }}" required placeholder="lowercase-dash"></div>
      <div class="form-row"><label>父栏目</label>
        <select name="parent_id">
          <option value="">— 顶级 —</option>
          @foreach($roots as $p)
            <option value="{{ $p->id }}" @selected(old('parent_id',$category->parent_id)==$p->id)>{{ $p->name }}</option>
          @endforeach
        </select></div>
      <div class="form-row"><label>排序</label>
        <input type="number" name="sort" value="{{ old('sort',$category->sort ?? 0) }}"></div>
      <div class="form-row"><label><span class="label-with-tip">栏目类型 <span class="req">*</span><x-admin-tip text="决定前台呈现：内容列表、产品列表、单页，或外链跳转（点击导航直接打开外链）。"/></span></label>
        <select name="type">
          @foreach(['list'=>'内容列表','product_list'=>'产品列表','page'=>'单页','external'=>'外链跳转'] as $vk=>$vn)
            <option value="{{ $vk }}" @selected(old('type',$category->type ?? 'list')===$vk)>{{ $vn }}</option>
          @endforeach
        </select></div>
      <div class="form-row"><label><span class="label-with-tip">外链地址 <x-admin-tip text="仅栏目类型选「外链跳转」时生效，需以 https:// 开头。"/></span></label>
        <input type="text" name="external_url" value="{{ old('external_url',$category->external_url) }}" placeholder="https://"></div>
    </div>

    <div class="form-row"><label><span class="label-with-tip">内置图标 <x-admin-tip type="help" text="使用全站内置线性图标库以保证风格统一；首页板块与场景卡的自定义图片请在「首页装修」中上传。"/></span></label>
      <div class="icon-pick-row">
        <span class="icon-preview" id="iconPreview">
          @if($category->icon)@include('site._icon',['name'=>$category->icon,'size'=>22])@else 选 @endif
        </span>
        <select name="icon" id="iconSelect" class="w-auto">
          <option value="">（无图标）</option>
          @foreach($iconOptions as $k=>$zh)
            <option value="{{ $k }}" @selected(old('icon',$category->icon)===$k)>{{ $zh }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <div class="form-row"><label>栏目简介</label>
      <textarea name="description" rows="2">{{ old('description',$category->description) }}</textarea></div>

    <div class="form-grid">
      <div class="form-row"><label>SEO Title（可空）</label>
        <input type="text" name="seo_title" value="{{ old('seo_title',$category->seo_title) }}"></div>
      <div class="form-row"><label>SEO Description（可空）</label>
        <input type="text" name="seo_desc" value="{{ old('seo_desc',$category->seo_desc) }}"></div>
    </div>

    <div class="btn-row mt-2">
      <label class="checkline"><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$category->is_active ?? true))> 启用</label>
      <label class="checkline"><input type="checkbox" name="is_nav" value="1" @checked(old('is_nav',$category->is_nav ?? true))> 显示在导航</label>
      <label class="checkline"><input type="checkbox" name="is_index" value="1" @checked(old('is_index',$category->is_index ?? false))> 首页推荐</label>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary">保存</button>
      <a class="btn" href="{{ route('admin.categories.index') }}">返回</a>
    </div>
  </form>
</div>
@endsection

@push('scripts')
<script>
// 内置图标下拉联动预览
(function(){
  var sel=document.getElementById('iconSelect'), prev=document.getElementById('iconPreview');
  if(!sel||!prev) return;
  var icons=@json(array_keys($iconOptions));
  // 服务端已渲染当前图标；切换时用简单文字占位预览（完整 SVG 由前台统一渲染）
  sel.addEventListener('change',function(){
    prev.textContent = sel.value ? ('「'+sel.options[sel.selectedIndex].text+'」') : '选';
  });
})();
</script>
@endpush
