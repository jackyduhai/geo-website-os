{{-- 分类图标：优先由调用方传入 icon / 数据字段，缺省回退统一占位图标（不在此绑定具体分类 slug）。保留 $slug 变量仅为兼容调用签名。 --}}
@include('site._icon', ['name' => $icon ?? 'default'])
