{{--
  后台统一辅助说明组件（InfoTooltip）
  用法：
    <x-admin-tip text="说明文字" />                                  默认 info / 上方弹出 / 宽 260
    <x-admin-tip text="..." type="help" place="bottom" :width="300" />
    <x-admin-tip text="..." type="warning" place="left" />
  交互：hover 与键盘 focus 均触发（移动端点按 focus 可看）；纯 CSS，无 JS。
  type：info（ⓘ 普通说明）/ help（? 功能解释）/ warning（! 注意事项）
--}}
@props(['text' => '', 'type' => 'info', 'place' => 'top', 'width' => 260])
@php
  $icons = [
    'info'    => '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.2"/><path d="M8 7.2v3.6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="8" cy="5.1" r=".85" fill="currentColor"/></svg>',
    'help'    => '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.2"/><path d="M6.1 6.2c.15-1.05.9-1.7 1.95-1.7 1.1 0 1.85.7 1.85 1.65 0 .85-.5 1.3-1.2 1.7-.55.3-.75.55-.75 1.15v.2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><circle cx="8" cy="11.5" r=".8" fill="currentColor"/></svg>',
    'warning' => '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 2.2 14.2 13H1.8L8 2.2Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M8 6.6v3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="8" cy="11.2" r=".8" fill="currentColor"/></svg>',
  ];
@endphp
<span class="tip tip-{{ $type }}" tabindex="0" role="img" aria-label="{{ $text }}"
      data-tip="{{ $text }}" data-place="{{ $place }}" style="--tip-w:{{ $width }}px">{!! $icons[$type] ?? $icons['info'] !!}</span>
