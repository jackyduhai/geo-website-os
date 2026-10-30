resources\views\admin\auth\login.blade.php:6: <meta name="robots" content="noindex,nofollow">
resources\views\admin\layout.blade.php:7: <meta name="robots" content="noindex,nofollow">
resources\views\admin\structure\groups.blade.php:30: <div class="form-row"><label><span class="label-with-tip">简介 <x-admin-tip text="可选；会用于知识子栏目页与 llms.txt 等 AI 摘要。"/></span></label><input type="text" name="description" placeholder="一句话说明该分组内容"></div>
resources\views\admin\structure\groups.blade.php:54: <div class="form-row"><label>简介</label><input type="text" name="description" value="{{ $g->description }}"></div>
resources\views\layouts\site.blade.php:10: <meta name="robots" content="noindex, follow">
resources\views\layouts\site.blade.php:12: <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
resources\views\layouts\site.blade.php:14: <link rel="canonical" href="{{ $seo['canonical'] ?? url()->current() }}">
resources\views\layouts\site.blade.php:22: <meta property="og:title" content="{{ $ogTitle }}">
resources\views\layouts\site.blade.php:23: <meta property="og:description" content="{{ $seo['description'] ?? ($siteSettings['seo_default_desc'] ?? '') }}">
resources\views\layouts\site.blade.php:24: <meta property="og:url" content="{{ $seo['canonical'] ?? url()->current() }}">
resources\views\layouts\site.blade.php:29: <meta name="twitter:card" content="summary_large_image">
resources\views\layouts\site.blade.php:30: <meta name="twitter:title" content="{{ $ogTitle }}">
resources\views\layouts\site.blade.php:31: <meta name="twitter:description" content="{{ $seo['description'] ?? ($siteSettings['seo_default_desc'] ?? '') }}">
resources\views\layouts\site.blade.php:32: <meta name="twitter:image" content="{{ $ogImage }}">
resources\views\layouts\site.blade.php:8: <meta name="description" content="{{ $seo['description'] ?? ($siteSettings['seo_default_desc'] ?? '') }}">
