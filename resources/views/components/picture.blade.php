{{--
  统一图片组件（全站唯一）：渐进增强 WebP，原格式作为 <img> 回退。
  用法：<x-picture :src="$url" alt=".." img-class="hi-bg" loading="lazy" width="300" height="300" />
  规则：
   - 仅当同目录存在 .webp 兄弟文件时输出 <source type="image/webp">，否则只渲染原图，绝不裂图；
   - 外链 / 非 /storage 图片 / 无 webp 时等价于普通 <img>；
   - 给 <img> 的类名一律用 img-class（Blade 会把 class 合并到根节点 picture，故不能直接用 class）；
   - 其它属性（style/sizes/fetchpriority/decoding 等）通过 $attributes 透传到 <img>。
--}}
@props(['src', 'alt' => '', 'loading' => 'lazy', 'width' => null, 'height' => null, 'imgClass' => ''])
@php
  $webp = \App\Support\ImageOptimizer::webpUrl($src);
@endphp
<picture class="pic">
  @if($webp)
    <source type="image/webp" srcset="{{ $webp }}">
  @endif
  <img
    src="{{ $src }}"
    alt="{{ $alt }}"
    loading="{{ $loading }}"
    @if($width !== null) width="{{ $width }}" @endif
    @if($height !== null) height="{{ $height }}" @endif
    {{ $attributes->except(['src','alt','loading','width','height'])->merge(['class' => $imgClass]) }}
  >
</picture>
