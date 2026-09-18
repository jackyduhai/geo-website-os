{{--
  FeatMedia：条目视觉的唯一出口。
  有自定义图片（后台装修上传，item.image）时渲染图片；否则回退到统一线性图标库。
  入参：image=图片URL（可空）、icon=内置图标 key（可空）、size=图标/图片边长（默认24）、alt=替代文本。
--}}
@php
  $size = isset($size) ? (int) $size : 24;
  $alt  = $alt ?? '';
@endphp
@if(! empty($image))
  <x-picture img-class="feat-media-img" :src="$image" :alt="$alt" :width="$size" :height="$size" loading="lazy" decoding="async" />
@else
  @include('site._icon', ['name' => $icon ?? 'default', 'size' => $size])
@endif
