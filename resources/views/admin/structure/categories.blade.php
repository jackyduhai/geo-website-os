@extends('admin.layout')
@section('title','内容结构')
@section('page-desc','栏目是前台内容分类与导航的来源；知识分组用于知识中心子分类与 AI 摘要，新增内容时按此归类。')

@section('content')
<div class="filter-bar">
  <a class="tab on" href="{{ route('admin.categories.index') }}">栏目分类</a>
  <a class="tab" href="{{ route('admin.groups.index') }}">知识分组</a>
</div>

<div class="card">
  <div class="card-head">
    <h2><span class="label-with-tip">栏目树 <x-admin-tip text="「显示在导航」开关控制顶部菜单是否出现该栏目；「首页推荐」控制首页内容流是否引用。"/></span></h2>
    <a class="btn btn-sm btn-primary" href="{{ route('admin.categories.create') }}">＋ 新建栏目</a>
  </div>
  <table class="tbl">
    <thead><tr><th class="w-220">栏目</th><th class="w-150">slug</th><th class="w-90">导航</th><th class="w-90">首页推荐</th><th class="w-80">启用</th><th class="w-80">排序</th><th class="actions w-130">操作</th></tr></thead>
    <tbody>
    @foreach($categories as $c)
      @if($c->parent_id === null)
        <tr class="row-head">
          <td><strong>{{ $c->name }}</strong>
            @if($c->icon)<span class="ml-1">@include('site._icon',['name'=>$c->icon,'size'=>16])</span>@endif
          </td>
          <td class="mono small">/{{ $c->slug }}</td>
          <td>@include('admin.structure._toggle',['field'=>'is_nav','on'=>$c->is_nav])</td>
          <td>@include('admin.structure._toggle',['field'=>'is_index','on'=>$c->is_index])</td>
          <td>@include('admin.structure._toggle',['field'=>'is_active','on'=>$c->is_active])</td>
          <td class="small">{{ $c->sort }}</td>
          <td class="actions">
            <a class="btn btn-sm" href="{{ route('admin.categories.edit',$c) }}">编辑</a>
            <form class="form-inline" method="post" action="{{ route('admin.categories.destroy',$c) }}"
                  onsubmit="return confirm('删除栏目？其下内容需要先迁移。')">@csrf @method('DELETE')
              <button class="btn btn-sm btn-danger">删</button></form>
          </td>
        </tr>
        @foreach($c->children as $ch)
          <tr>
            <td class="indent">{{ $ch->name }}
              @if($ch->icon)<span class="ml-1">@include('site._icon',['name'=>$ch->icon,'size'=>16])</span>@endif
            </td>
            <td class="mono small indent">/{{ $ch->slug }}</td>
            <td>@include('admin.structure._toggle',['field'=>'is_nav','on'=>$ch->is_nav])</td>
            <td>@include('admin.structure._toggle',['field'=>'is_index','on'=>$ch->is_index])</td>
            <td>@include('admin.structure._toggle',['field'=>'is_active','on'=>$ch->is_active])</td>
            <td class="small">{{ $ch->sort }}</td>
            <td class="actions">
              <a class="btn btn-sm" href="{{ route('admin.categories.edit',$ch) }}">编辑</a>
              <form class="form-inline" method="post" action="{{ route('admin.categories.destroy',$ch) }}"
                    onsubmit="return confirm('删除栏目？其下内容需要先迁移。')">@csrf @method('DELETE')
                <button class="btn btn-sm btn-danger">删</button></form>
            </td>
          </tr>
        @endforeach
      @endif
    @endforeach
    </tbody>
  </table>
</div>
@endsection
