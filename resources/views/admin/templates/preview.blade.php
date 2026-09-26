@extends('admin.layout')
@section('title','模板预览')
@section('page-desc','模板包随包提供的桌面 / 移动端预览截图。预览不修改任何数据，也不改变当前激活态；如需采用，可在下方确认激活。')

@section('content')
@php
  $m = $manifest;
  $name = $m['name'] ?? $pack;
  $industry = is_array($m['industry'] ?? null) ? implode(', ', $m['industry']) : ($m['industry'] ?? '-');
@endphp

<div class="card">
  <h2>{{ $name }}</h2>
  <p class="small empty-cell">
    包：<code>{{ $pack }}</code> · v{{ $m['version'] ?? '?' }}
    · 行业：{{ $industry }}
    · 语言：{{ implode('/', $m['locales'] ?? []) }}
  </p>

  @if($hasDesktop || $hasMobile)
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:var(--sp-4);margin-top:var(--sp-3);">
      @if($hasDesktop)
        <figure style="margin:0;">
          <img src="{{ route('admin.templates.screenshot', [$pack, 'desktop']) }}"
               alt="{{ $name }} 桌面端预览" loading="lazy"
               style="width:100%;display:block;border-radius:var(--radius);border:1px solid var(--border);">
          <figcaption class="small" style="margin-top:var(--sp-2);text-align:center;color:var(--text-muted);">桌面端 Desktop</figcaption>
        </figure>
      @endif
      @if($hasMobile)
        <figure style="margin:0;">
          <img src="{{ route('admin.templates.screenshot', [$pack, 'mobile']) }}"
               alt="{{ $name }} 移动端预览" loading="lazy"
               style="width:100%;display:block;border-radius:var(--radius);border:1px solid var(--border);">
          <figcaption class="small" style="margin-top:var(--sp-2);text-align:center;color:var(--text-muted);">移动端 Mobile</figcaption>
        </figure>
      @endif
    </div>
  @else
    <p class="empty-cell">该模板包未提供预览截图。</p>
  @endif
</div>

<div class="btn-row mt-2">
  <form method="post" action="{{ route('admin.templates.activate', $pack) }}"
        onsubmit="return confirm('确认激活模板「{{ $name }}」并生成页面骨架？')">
    @csrf
    <button class="btn btn-primary">激活「{{ $name }}」</button>
  </form>
  <a class="btn" href="{{ route('admin.templates.index') }}">返回模板中心</a>
</div>
@endsection
