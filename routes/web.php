<?php

use App\Http\Controllers\Geo\FeedController;
use App\Http\Controllers\Site\AboutController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\CooperationController;
use App\Http\Controllers\Site\FactoryController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\InquiryController;
use App\Http\Controllers\Site\KnowledgeController;
use App\Http\Controllers\Site\PageController;
use App\Http\Controllers\Site\ProductController;
use App\Http\Controllers\Site\SearchController;
use App\Http\Controllers\Site\SolutionController;
use App\Support\Facts;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 前台路由（v0.7：对齐交付包 URL 地图）
|--------------------------------------------------------------------------
| URL 规范：目录型（列表/栏目/单页）以「/」结尾，详情型不带尾斜杠。
|   {slash?} 内联可选斜杠让两种写法都能命中，再由 CanonicalizeSlash 301
|   到规范地址，避免重复页面。显式路由必须在通配分发之前。
|
|   1. GEO 产出与搜索最前
|   2. 首页
|   3. 产品 / 场景 / 工厂 / 合作 / 知识 / 关于 / 联系（结构化实体，config 驱动）
|   4. 旧 /scenarios 兼容 301
|   5. 留言提交
|   6. 其余（知识文章、新闻等 DB 长文）交给统一分发器
*/

// ---------- GEO 产出 ----------
Route::get('/sitemap.xml', [FeedController::class, 'sitemap'])->name('geo.sitemap');
Route::get('/llms.txt',    [FeedController::class, 'llms'])->name('geo.llms');
Route::get('/robots.txt',  [FeedController::class, 'robots'])->name('geo.robots');
Route::get('/feed.xml',    [FeedController::class, 'rss'])->name('geo.rss');
Route::get('/geo.json',    [FeedController::class, 'graph'])->name('geo.graph');

// ---------- 搜索 ----------
Route::get('/search', [SearchController::class, 'index'])->name('search');

// ---------- 首页 ----------
Route::get('/', [HomeController::class, 'index'])->name('home');

// 路由参数白名单（全部来自 facts，单一事实源，禁止手写枚举）
$lineRe  = collect(Facts::productLines())->pluck('slug')->implode('|');
$coreRe  = implode('|', Facts::CORE_PRODUCTS);
$sceneRe = collect(Facts::scenes())->pluck('slug')->implode('|');

// ---------- 产品中心 ----------
Route::get('products{slash?}', [ProductController::class, 'index'])
    ->where('slash', '/?')->defaults('_slash', 1)->name('products.index');
Route::get('products/{line}{slash?}', [ProductController::class, 'line'])
    ->where('line', $lineRe)->where('slash', '/?')->defaults('_slash', 1)->name('products.line');
Route::get('products/{slug}{slash?}', [ProductController::class, 'show'])
    ->where('slug', $coreRe)->where('slash', '/?')->name('products.show');

// ---------- 应用场景 ----------
Route::get('solutions{slash?}', [SolutionController::class, 'index'])
    ->where('slash', '/?')->defaults('_slash', 1)->name('solutions.index');
Route::get('solutions/{scene}{slash?}', [SolutionController::class, 'show'])
    ->where('scene', $sceneRe)->where('slash', '/?')->defaults('_slash', 1)->name('solutions.show');

// ---------- 工厂与资质 / 合作方式 ----------
Route::get('factory{slash?}', [FactoryController::class, 'show'])
    ->where('slash', '/?')->defaults('_slash', 1)->name('factory');
Route::get('cooperation{slash?}', [CooperationController::class, 'show'])
    ->where('slash', '/?')->defaults('_slash', 1)->name('cooperation');

// ---------- 知识中心（子栏目由 groups 数据驱动；非栏目 slug 转交分发器渲染扁平文章） ----------
Route::get('knowledge{slash?}', [KnowledgeController::class, 'index'])
    ->where('slash', '/?')->defaults('_slash', 1)->name('knowledge.index');
Route::get('knowledge/{channel}{slash?}', [KnowledgeController::class, 'channel'])
    ->where('channel', '[a-z0-9-]+')->where('slash', '/?')->defaults('_slash', 1)->name('knowledge.channel');

// ---------- 关于我们 ----------
Route::get('about/{page}{slash?}', [AboutController::class, 'page'])
    ->where('page', 'profile|history|culture')->where('slash', '/?')->defaults('_slash', 1)->name('about.page');

// ---------- 联系我们 ----------
Route::get('contact{slash?}', [ContactController::class, 'show'])
    ->where('slash', '/?')->defaults('_slash', 1)->name('contact');

// ---------- 兼容旧 /scenarios → /solutions（301，避免历史入口死链） ----------
Route::get('scenarios{slash?}', fn () => redirect(url('/solutions/'), 301))->where('slash', '/?');
Route::get('scenarios/{slug}{slash?}', function (string $slug) {
    return redirect(url('/solutions/' . $slug . '/'), 301);
})->where('slash', '/?');

// ---------- 在线留言（限流：每分钟 6 次） ----------
Route::post('/inquiry', [InquiryController::class, 'store'])
    ->middleware('throttle:6,1')->name('inquiry.store');

// ---------- 栏目与内容（统一分发：知识文章、新闻等 DB 长文） ----------
Route::get('/{path}', [PageController::class, 'dispatch'])
    ->where('path', '^(?!admin(?:/|$)|api(?:/|$)).+')
    ->name('page');
