{{-- 通用组合 Block · 面包屑：首页 / 当前页（自动读取 Page 标题，内联样式不依赖额外 CSS）。 --}}
@php
  $c = $block->cfg();
  $bcShowCurrent = array_key_exists('show_current', $c) ? ! empty($c['show_current']) : true;
  $bcCurrent = $page->title ?? ($block->page->title ?? null);
@endphp
<nav aria-label="{{ __('ui.breadcrumb') }}" style="font-size:13.5px">
  <div class="wrap">
    <ol style="list-style:none;display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:16px 0;padding:0;color:var(--ink-muted)">
      <li><a href="{{ \App\Support\PublicUrl::home() }}" style="color:var(--ink-muted)">{{ __('ui.back_home') }}</a></li>
      @if($bcShowCurrent && $bcCurrent)
        <li aria-hidden="true">/</li>
        <li aria-current="page" style="color:var(--ink)">{{ $bcCurrent }}</li>
      @endif
    </ol>
  </div>
</nav>
