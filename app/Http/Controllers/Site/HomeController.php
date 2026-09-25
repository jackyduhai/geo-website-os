<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Render\CompositionRenderer;
use App\Support\Render\HomeRenderContext;

/**
 * 前台首页（P-STEP 18I / TD-70）。
 * --------------------------------------------------
 * 首页正式进入 Page Composition：
 *
 *   is_home Page（HomeRenderContext::resolve）
 *     └─ CompositionRenderer ─ home Template ─ Page Blocks
 *
 * 不再有手工 heroParams / sceneList / buildStats 等数据准备链，也不再读旧
 * 「首页整体装修」page='home' 区块。出厂 blank 首页 Page 无区块时，由 site.composed
 * 渲染中性欢迎屏。整页缓存（含 zh/en 隔离）由 CachePage 中间件统一处理。
 */
class HomeController extends Controller
{
    public function index()
    {
        $page = HomeRenderContext::resolve();

        return app(CompositionRenderer::class)->render(new HomeRenderContext($page));
    }
}
