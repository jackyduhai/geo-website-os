<!DOCTYPE html
{{-- Example Theme · 布局：只消费归一化 $seo（SeoHeadComposer）与公开视图数据，零引擎内部依赖 --}}
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $seo['title_full'] }}</title>
<meta name="description" content="{{ $seo['description'] }}">
<meta name="robots" content="{{ $seo['noindex'] ? 'noindex, follow' : 'index, follow' }}">
<link rel="canonical" href="{{ $seo['canonical'] }}">
<meta property="og:type" content="{{ $seo['type'] }}">
<meta property="og:title" content="{{ $seo['og_title'] }}">
<meta property="og:description" content="{{ $seo['og_description'] }}">
<meta property="og:url" content="{{ $seo['canonical'] }}">
<meta property="og:site_name" content="{{ $seo['og_site_name'] }}">
<meta property="og:image" content="{{ $seo['og_image'] }}">
@yield('theme_head')
</head>
<body data-theme="example">
<header><strong>{{ $siteSettings['site_name'] ?? ($seo['og_site_name'] ?? 'Website') }}</strong></header>
<main>
@yield('content')
</main>
<footer>
{{-- 结构化数据由引擎生成并经控制器注入，主题只负责透传输出 --}}
@foreach (($schemas ?? []) as $schema)
<script type="application/ld+json">{{ json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</script>
@endforeach
</footer>
</body>
</html>
