@extends('admin.layout')
@php
  $exists = isset($page) && $page->exists;
@endphp
@section('title', $exists ? '编辑页面设置' : '新建组合页面')
@section('page-desc','选择模板与语言，填写标题与访问 slug；创建后进入组合内容。slug 是该语言下的唯一访问标识。')

@section('content')
@php
  $oldTemplate = old('template', $page->template ?? 'landing');
  $oldLocale   = old('locale', $page->locale ?? \App\Support\Localization\LocaleRegistry::default());
  $oldTitle    = old('title', $page->title ?? '');
  $oldSlug     = old('slug', $page->slug ?? '');
  $oldStatus   = old('status', $page->status ?? 'draft');
  $oldTrans    = old('translation_of');
@endphp

<form method="post" class="card narrow-md"
      action="{{ $exists ? route('admin.pages.update', $page) : route('admin.pages.store') }}">
  @csrf
  @if($exists)@method('PUT')@endif

  <div class="form-grid">
    <div class="form-row">
      <label class="req">模板</label>
      <select name="template" class="select" required>
        @foreach($templates as $tk => $td)
          <option value="{{ $tk }}" @selected($oldTemplate === $tk)>{{ $td->label }}（{{ $tk }}）</option>
        @endforeach
      </select>
      @error('template')<div class="field-err">{{ $message }}</div>@enderror
      <p class="hint">模板决定页面有哪些可放区块的槽位，不包含业务内容。</p>
    </div>

    @unless($exists)
      <div class="form-row">
        <label class="req">语言</label>
        <select name="locale" class="select" required>
          @foreach($locales as $lc)
            <option value="{{ $lc }}" @selected($oldLocale === $lc)>{{ $lc }}</option>
          @endforeach
        </select>
        @error('locale')<div class="field-err">{{ $message }}</div>@enderror
      </div>

      <div class="form-row">
        <label>作为某页面的语言版本（可选）</label>
        <select name="translation_of" class="select">
          <option value="">独立页面（自动新建翻译组）</option>
          @foreach($existing as $ex)
            <option value="{{ $ex->id }}" @selected((string) $oldTrans === (string) $ex->id)>
              {{ $ex->title ?: $ex->slug }}（{{ $ex->locale }}）
            </option>
          @endforeach
        </select>
        <p class="hint">选后本页与该页归为同一翻译组，切换语言时互相对应。</p>
      </div>
    @endunless

    <div class="form-row">
      <label>页面标题</label>
      <input type="text" name="title" class="select" maxlength="160" value="{{ $oldTitle }}">
      @error('title')<div class="field-err">{{ $message }}</div>@enderror
    </div>

    <div class="form-row">
      <label>访问 slug</label>
      <input type="text" name="slug" class="select" maxlength="120" value="{{ $oldSlug }}"
             placeholder="例如 product-launch">
      @error('slug')<div class="field-err">{{ $message }}</div>@enderror
      <p class="hint">首页可留空；落地页需填写。中文站地址 /{slug}，英文站 /en/{slug}。</p>
    </div>

    <div class="form-row">
      <label>状态</label>
      <select name="status" class="select">
        <option value="draft" @selected($oldStatus === 'draft')>草稿（前台不可见）</option>
        <option value="published" @selected($oldStatus === 'published')>已发布（前台可访问）</option>
      </select>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-sm btn-primary">
      {{ $exists ? '保存设置' : '创建并组合内容' }}
    </button>
    <a class="btn btn-sm" href="{{ route('admin.pages.index') }}">取消</a>
  </div>
</form>
@endsection
