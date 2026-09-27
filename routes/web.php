<?php

use App\Http\Controllers\Geo\FeedController;
use App\Http\Controllers\Site\AboutController;
use App\Http\Controllers\Site\CaseController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\CooperationController;
use App\Http\Controllers\Site\FactoryController;
use App\Http\Controllers\Site\FormSubmissionController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\InquiryController;
use App\Http\Controllers\Site\KnowledgeController;
use App\Http\Controllers\Site\PageController;
use App\Http\Controllers\Site\ProductController;
use App\Http\Controllers\Site\SearchController;
use App\Http\Controllers\Site\SolutionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 前台路由（P-STEP 18F：zh-CN 默认 + en 前缀双注册）
|--------------------------------------------------------------------------
| URL 规范：目录型（列表/栏目/单页）以「/」结尾，详情型不带尾斜杠。
|   {slash?} 内联可选斜杠让两种写法都能命中，再由 CanonicalizeSlash 301
|   到规范地址，避免重复页面。
|
|   同一组前台路由注册两次：
|     1. zh-CN（默认，无前缀），中间件 locale:zh-CN
|     2. English（前缀 en），中间件 locale:en
|   语言由 SetLocale 单一裁决；站点未启用 en 时 /en/* 由该中间件 404。
|   robots.txt 仅注册一次（中文），其中同时引用两套 sitemap。
*/

$registerFrontend = function (string $locale, string $nameSuffix): void {
    // ---------- GEO 产出 ----------
    Route::get('/sitemap.xml', [FeedController::class, 'sitemap'])->name('geo.sitemap'.$nameSuffix);
    Route::get('/llms.txt',    [FeedController::class, 'llms'])->name('geo.llms'.$nameSuffix);
    Route::get('/feed.xml',    [FeedController::class, 'rss'])->name('geo.rss'.$nameSuffix);
    Route::get('/geo.json',    [FeedController::class, 'graph'])->name('geo.graph'.$nameSuffix);

    // ---------- 搜索 ----------
    Route::get('/search', [SearchController::class, 'index'])->name('search'.$nameSuffix);

    // ---------- 首页 ----------
    Route::get('/', [HomeController::class, 'index'])->name('home'.$nameSuffix);

    // 产品 / 场景的可访问 slug 不再在路由加载期由全局 Facts 白名单决定（那会绕过
    // 多站隔离，让空站 / 他站也能命中本站不存在的目录 URL）。统一交给站点隔离的
    // Catalog 在控制器内判定：系列 / 核心产品存在才渲染，否则 404（P-STEP 14 / D.2）。

    // ---------- 产品中心 ----------
    Route::get('products{slash?}', [ProductController::class, 'index'])
        ->where('slash', '/?')->defaults('_slash', 1)->name('products.index'.$nameSuffix);
    // 系列页（目录型，带尾斜杠）与核心产品详情（详情型，无尾斜杠）共用单一路由，
    // ProductController::resolve 按当前站 Catalog 分流；斜杠方向无法在路由层静态声明
    // （同一路由承载两类页面），由 CanonicalizeSlash 按 Catalog 实体类型动态判定。
    Route::get('products/{param}{slash?}', [ProductController::class, 'resolve'])
        ->where('param', '[a-z0-9-]+')->where('slash', '/?')->name('products.show'.$nameSuffix);

    // ---------- 应用场景 ----------
    Route::get('solutions{slash?}', [SolutionController::class, 'index'])
        ->where('slash', '/?')->defaults('_slash', 1)->name('solutions.index'.$nameSuffix);
    // 场景是否存在由当前站 Catalog 决定，show() 内查无即 404
    Route::get('solutions/{scene}{slash?}', [SolutionController::class, 'show'])
        ->where('scene', '[a-z0-9-]+')->where('slash', '/?')->defaults('_slash', 1)->name('solutions.show'.$nameSuffix);

    // ---------- 客户案例（18R-2b）：列表 /cases + 详情 /cases/{slug} ----------
    // 案例是否存在由当前站已发布 case_study Entity 决定，show() 内查无即 404。
    Route::get('cases{slash?}', [CaseController::class, 'index'])
        ->where('slash', '/?')->defaults('_slash', 1)->name('cases.index'.$nameSuffix);
    Route::get('cases/{slug}{slash?}', [CaseController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')->where('slash', '/?')->name('cases.show'.$nameSuffix);

    // ---------- 工厂与资质 / 合作方式 ----------
    Route::get('factory{slash?}', [FactoryController::class, 'show'])
        ->where('slash', '/?')->defaults('_slash', 1)->name('factory'.$nameSuffix);
    Route::get('cooperation{slash?}', [CooperationController::class, 'show'])
        ->where('slash', '/?')->defaults('_slash', 1)->name('cooperation'.$nameSuffix);

    // ---------- 知识中心（子栏目由 groups 数据驱动；非栏目 slug 转交分发器渲染扁平文章） ----------
    Route::get('knowledge{slash?}', [KnowledgeController::class, 'index'])
        ->where('slash', '/?')->defaults('_slash', 1)->name('knowledge.index'.$nameSuffix);
    Route::get('knowledge/{channel}{slash?}', [KnowledgeController::class, 'channel'])
        ->where('channel', '[a-z0-9-]+')->where('slash', '/?')->defaults('_slash', 1)->name('knowledge.channel'.$nameSuffix);

    // ---------- 关于我们 ----------
    Route::get('about/{page}{slash?}', [AboutController::class, 'page'])
        ->where('page', 'profile|history|culture')->where('slash', '/?')->defaults('_slash', 1)->name('about.page'.$nameSuffix);

    // ---------- 联系我们 ----------
    Route::get('contact{slash?}', [ContactController::class, 'show'])
        ->where('slash', '/?')->defaults('_slash', 1)->name('contact'.$nameSuffix);

    // ---------- 兼容旧 /scenarios → /solutions（301，避免历史入口死链） ----------
    Route::get('scenarios{slash?}', fn () => redirect(PublicUrlLocalized('/solutions/'), 301))->where('slash', '/?');
    Route::get('scenarios/{slug}{slash?}', function (string $slug) {
        return redirect(PublicUrlLocalized('/solutions/' . $slug . '/'), 301);
    })->where('slash', '/?');

    // ---------- 在线留言（限流：每分钟 6 次） ----------
    Route::post('/inquiry', [InquiryController::class, 'store'])
        ->middleware('throttle:6,1')->name('inquiry.store'.$nameSuffix);

    // ---------- 通用表单提交（按表单 slug；限流每分钟 6 次） ----------
    // {form:slug} 隐式绑定受 Form 站点全局作用域限制（跨站 / 缺失 404）；
    // disabled 表单在控制器 404。en 组自动生成 /en/forms/{slug}/submit。
    Route::post('forms/{form:slug}/submit', [FormSubmissionController::class, 'submit'])
        ->middleware('throttle:6,1')->name('forms.submit'.$nameSuffix);

    // ---------- 栏目与内容（统一分发：知识文章、新闻等 DB 长文） ----------
    // plugins/ 前缀属于插件层（P-STEP 06 契约）：由插件自有路由承接，
    // 不进入统一分发器；未匹配的插件路径自然 404。
    Route::get('/{path}', [PageController::class, 'dispatch'])
        ->where('path', '^(?!admin(?:/|$)|api(?:/|$)|plugins(?:/|$)).+')
        ->name('page'.$nameSuffix);
};

// 注册顺序很重要：en 组（prefix en）必须先于 zh 组注册，否则 zh 组的 catch-all
// `/{path}` 会抢先匹配 /en/* 请求。en 显式路由 / en catch-all(en/{path}) 先命中；
// 非 en 请求再落到 zh 组显式路由 / zh catch-all。

// robots.txt 协议规定根级、语言无关：在 locale 组之外注册（不经过 SetLocale），
// 任何站点语言配置（含 en-only 站点）下 /robots.txt 都可访问（TD-109）。站点由全局
// ResolveSite 按 host 解析，robots 内容不随语言变化，并列出该站各语言 sitemap。
Route::get('/robots.txt', [FeedController::class, 'robots'])->name('geo.robots');

// ---------- English（前缀 en） ----------
Route::prefix('en')->middleware('locale:en')->group(function () use ($registerFrontend): void {
    $registerFrontend('en', '.en');
});

// ---------- zh-CN（默认，无前缀） ----------
Route::middleware('locale:zh-CN')->group(function () use ($registerFrontend): void {
    $registerFrontend('zh-CN', '');
});

// 注：PublicUrlLocalized() 与 localized_route() 已移至 app/Support/helpers.php
// （composer.json autoload.files 加载），确保 route:cache 生产模式下仍可用。
