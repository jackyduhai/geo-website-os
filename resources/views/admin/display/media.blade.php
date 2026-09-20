@extends('admin.layout')
@section('title','媒体库')
@section('page-desc','上传图片 / 文件的统一仓库，可在首页装修、内容封面、站点设置等位置直接引用。')

@section('content')
<div class="card">
  <div class="card-head">
    <h2>上传媒体</h2>
    <div class="filter-bar mb-0">
      <a class="tab {{ !$type ? 'on' : '' }}" href="{{ route('admin.media.index') }}">全部</a>
      <a class="tab {{ $type==='image' ? 'on' : '' }}" href="{{ route('admin.media.index',['type'=>'image']) }}">图片</a>
      <a class="tab {{ $type==='file' ? 'on' : '' }}" href="{{ route('admin.media.index',['type'=>'file']) }}">文件</a>
    </div>
  </div>
  <form method="post" action="{{ route('admin.media.store') }}" enctype="multipart/form-data" class="upload-form">
    @csrf
    <div class="form-row">
      <label>选择文件</label>
      <input type="file" name="file" required class="file-input">
      <div class="help mt-1">图片建议 WebP / JPG，单文件 ≤ 10MB。</div>
    </div>
    <div class="form-row grow">
      <label><span class="label-with-tip">alt 描述 <x-admin-tip text="图片的文字替代说明，用于无障碍读屏与图片搜索，建议描述画面主体。"/></span></label>
      <input type="text" name="alt" placeholder="如：生产车间实拍">
    </div>
    <button class="btn btn-primary">上传</button>
  </form>
</div>

<div class="card">
  <h2>媒体文件（{{ $items->total() }}）</h2>
  <div class="media-grid">
    @forelse($items as $m)
      <div class="media-card">
        @if($m->isImage())
          <div class="media-thumb">
            <img src="{{ $m->url() }}" alt="{{ $m->alt }}">
          </div>
        @else
          <div class="media-thumb mono small">{{ $m->mime }}</div>
        @endif
        <div class="media-body">
          <div class="media-name">{{ $m->original_name }}</div>
          <div class="small muted">@if($m->width){{ $m->width }} × {{ $m->height }} · @endif{{ round($m->size/1024) }}KB</div>
          <form method="post" action="{{ route('admin.media.update',$m) }}">
            @csrf @method('PUT')
            <input type="text" name="alt" value="{{ $m->alt }}" placeholder="alt 描述">
            <input type="text" name="title" value="{{ $m->title }}" placeholder="title（可空）">
            <button class="btn btn-sm btn-primary">保存信息</button>
          </form>
          <form method="post" action="{{ route('admin.media.destroy',$m) }}"
                onsubmit="return confirm('删除该文件？')">@csrf @method('DELETE')
            <button class="btn btn-sm btn-danger">删除文件</button>
          </form>
          <div class="mono small muted media-url">{{ $m->url() }}</div>
        </div>
      </div>
    @empty
      <p class="muted small">暂无媒体文件</p>
    @endforelse
  </div>
  <div class="pager">{{ $items->links() }}</div>
</div>
@endsection
