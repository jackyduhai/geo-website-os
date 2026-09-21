<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BlockController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EntityController;
use App\Http\Controllers\Admin\FactController;
use App\Http\Controllers\Admin\GeoController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\InquiryController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\NarrativeController;
use App\Http\Controllers\Admin\RedirectController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 后台路由（bootstrap/app.php 已统一加 web 中间件、admin 前缀与 admin. 命名）
|--------------------------------------------------------------------------
*/

// ---------- 登录（游客可见） ----------
Route::get('login', [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login'])->name('login.attempt');

// ---------- 以下全部需要登录 ----------
// admin.site：超级管理员可经顶部切换器（session admin_site_slug）选择当前管理站点。
Route::middleware(['admin.auth', 'admin.site'])->group(function () {

    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // 修改自身密码
    Route::get('password', [AuthController::class, 'showPassword'])->name('password');
    Route::put('password', [AuthController::class, 'updatePassword'])->name('password.update');

    // ---------- 站点管理（跨站租户根，仅超级管理员） ----------
    Route::middleware('super.admin')->group(function () {
        Route::post('sites/switch', [SiteController::class, 'switchSite'])->name('sites.switch');
        Route::resource('sites', SiteController::class)->except(['show']);
        Route::post('sites/{site}/make-default', [SiteController::class, 'makeDefault'])->name('sites.default');
    });

    // ---------- 内容管理 ----------
    Route::get('contents/{tab?}', [ContentController::class, 'index'])
        ->where('tab', 'article|page|all')->name('contents.index');
    Route::get('contents/create/{type?}', [ContentController::class, 'create'])
        ->where('type', 'article|page')->name('contents.create');
    Route::post('contents', [ContentController::class, 'store'])->name('contents.store');
    Route::get('contents/{content}/edit', [ContentController::class, 'edit'])->name('contents.edit');
    Route::put('contents/{content}', [ContentController::class, 'update'])->name('contents.update');
    Route::delete('contents/{content}', [ContentController::class, 'destroy'])->name('contents.destroy');
    Route::post('contents/{content}/publish', [ContentController::class, 'publish'])->name('contents.publish');
    Route::post('contents/{content}/unpublish', [ContentController::class, 'unpublish'])->name('contents.unpublish');
    Route::post('contents/check', [ContentController::class, 'check'])->name('contents.check');
    Route::post('contents/md-preview', [ContentController::class, 'mdPreview'])->name('contents.md-preview');
    Route::get('contents/{content}/revisions', [ContentController::class, 'revisions'])->name('contents.revisions');
    // ---------- Entities / knowledge-graph catalog resources (P-STEP 17B) ----------
    // organization / product / service / person / location / topic. EntityRelation
    // management UI is delivered in 17C; 17B ships entity CRUD + example seeding only.
    Route::post('entities/seed-examples', [EntityController::class, 'seedExamples'])->name('entities.seed');
    Route::post('entities/{entity}/publish', [EntityController::class, 'publish'])->name('entities.publish');
    Route::post('entities/{entity}/unpublish', [EntityController::class, 'unpublish'])->name('entities.unpublish');
    Route::get('entities/create/{type}', [EntityController::class, 'create'])->name('entities.create');
    Route::post('entities', [EntityController::class, 'store'])->name('entities.store');
    Route::get('entities/{entity}/edit', [EntityController::class, 'edit'])->name('entities.edit');
    Route::put('entities/{entity}', [EntityController::class, 'update'])->name('entities.update');
    Route::delete('entities/{entity}', [EntityController::class, 'destroy'])->name('entities.destroy');
    Route::get('entities/{tab?}', [EntityController::class, 'index'])->name('entities.index');

    // ---------- 页面文案（结构化页面叙事插槽：hero 导语 / 企业简介正文） ----------
    Route::get('narrative', [NarrativeController::class, 'index'])->name('narrative.index');
    Route::get('narrative/{key}/edit', [NarrativeController::class, 'edit'])
        ->where('key', '[a-z0-9\-\.]+')->name('narrative.edit');
    Route::put('narrative/{key}', [NarrativeController::class, 'update'])
        ->where('key', '[a-z0-9\-\.]+')->name('narrative.update');
    Route::delete('narrative/{key}', [NarrativeController::class, 'reset'])
        ->where('key', '[a-z0-9\-\.]+')->name('narrative.reset');

    // ---------- 结构：栏目 / 分组 / 菜单 ----------
    Route::resource('categories', CategoryController::class)->except(['show']);
    Route::post('categories/{category}/toggle', [CategoryController::class, 'toggle'])->name('categories.toggle');

    Route::get('groups', [GroupController::class, 'index'])->name('groups.index');
    Route::post('groups', [GroupController::class, 'store'])->name('groups.store');
    Route::put('groups/{group}', [GroupController::class, 'update'])->name('groups.update');
    Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');

    Route::get('menus', [MenuController::class, 'index'])->name('menus.index');
    Route::post('menus', [MenuController::class, 'store'])->name('menus.store');
    Route::put('menus/{menu}', [MenuController::class, 'update'])->name('menus.update');
    Route::delete('menus/{menu}', [MenuController::class, 'destroy'])->name('menus.destroy');
    // 固定栏目覆盖层：改名 / 显隐 / 排序 / 恢复默认
    Route::post('menus/override', [MenuController::class, 'saveOverride'])->name('menus.override');
    Route::delete('menus/override/{key}', [MenuController::class, 'resetOverride'])->name('menus.override.reset')
        ->where('key', '[a-z0-9\-]+');

    // ---------- 展示：首页装修（首屏/中部横幅在此就地维护，不再单设 Banner 模块） ----------
    Route::get('blocks', [BlockController::class, 'index'])->name('blocks.index');
    Route::put('blocks/{block}', [BlockController::class, 'update'])->name('blocks.update');

    // ---------- 媒体库 ----------
    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media', [MediaController::class, 'store'])->name('media.store');
    Route::post('media/inline', [MediaController::class, 'uploadInline'])->name('media.inline');
    Route::put('media/{media}', [MediaController::class, 'update'])->name('media.update');
    Route::delete('media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');

    // ---------- 事实库 ----------
    Route::get('facts', [FactController::class, 'index'])->name('facts.index');
    Route::get('facts/create', [FactController::class, 'create'])->name('facts.create');
    Route::post('facts', [FactController::class, 'store'])->name('facts.store');
    Route::get('facts/{fact}/edit', [FactController::class, 'edit'])->name('facts.edit');
    Route::put('facts/{fact}', [FactController::class, 'update'])->name('facts.update');
    Route::delete('facts/{fact}', [FactController::class, 'destroy'])->name('facts.destroy');

    // ---------- 301 跳转 ----------
    // ---------- 客户留言 ----------
    Route::get('inquiries', [InquiryController::class, 'index'])->name('inquiries.index');
    Route::put('inquiries/{inquiry}', [InquiryController::class, 'handle'])->name('inquiries.handle');
    Route::delete('inquiries/{inquiry}', [InquiryController::class, 'destroy'])->name('inquiries.destroy');

    Route::get('redirects', [RedirectController::class, 'index'])->name('redirects.index');
    Route::post('redirects', [RedirectController::class, 'store'])->name('redirects.store');
    Route::put('redirects/{redirect}', [RedirectController::class, 'update'])->name('redirects.update');
    Route::delete('redirects/{redirect}', [RedirectController::class, 'destroy'])->name('redirects.destroy');

    // ---------- 站点设置 ----------
    Route::get('settings/{group?}', [SettingController::class, 'index'])
        ->where('group', 'general|theme|contact|copy|seo|geo|sync')->name('settings.index');
    Route::put('settings/{group}', [SettingController::class, 'update'])->name('settings.update');
    Route::post('settings/sync/regenerate-token', [SettingController::class, 'regenerateToken'])
        ->name('settings.token');

    // ---------- GEO 工具与对接 ----------
    Route::get('geo/tools', [GeoController::class, 'tools'])->name('geo.tools');
    Route::get('geo/preview/{kind}', [GeoController::class, 'preview'])
        ->where('kind', 'sitemap|llms|robots|rss')->name('geo.preview');
    Route::get('geo/sync-logs', [GeoController::class, 'syncLogs'])->name('geo.sync-logs');
});
