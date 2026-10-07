<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Site;
use App\Support\SiteContext;
use App\Support\SystemAuthorization;
use App\Support\Templates\RecipeApplier;
use App\Support\Templates\TemplateDefaultsInstaller;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Templates\TemplatePreviewSite;
use App\Support\Templates\TemplateRecipeValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 模板生态管理后台（P-STEP 18L-3b）。
 *
 * 模板包是**声明式文件资源**（resources/templates/{id}/：manifest + recipes + defaults），
 * 本控制器只暴露「发现 / 预览 / 对比 / 激活 / 初始化默认值 / 停用」生命周期，不提供
 * 上传、删除或在线编辑（归 Template SDK / 文件部署，避免后台写文件的安全面）。激活态
 * 是 per-site 设置 template_active_pack，与 {@see \App\Support\Theme\ThemeManager} 平行。
 *
 *  - activate：三层校验 → 激活 → 应用全部 recipe（页面骨架），**不含 defaults**。
 *  - bootstrap：独立显式落地建议默认 settings / menus / SEO（空值才写，不覆盖已有）。
 *  - compare：切换前只读对拍当前包与目标包，保护已有内容。
 */
class TemplateController extends Controller
{
    public function index(): View
    {
        return view('admin.templates.index', [
            'packs'   => TemplatePackageManager::all(),
            'active'  => TemplatePackageManager::active(),
            'invalid' => TemplatePackageManager::invalidPacks(),
            'cross'   => $this->crossSiteMatrix(),
            'structure' => TemplatePackageManager::structureMatrix(),
        ]);
    }

    public function activate(string $pack): RedirectResponse
    {
        if (! TemplatePackageManager::exists($pack)) {
            abort(404);
        }

        $packPath = TemplatePackageManager::basePath() . '/' . $pack;
        $check = TemplateRecipeValidator::inspect($packPath);
        if ($check['errors'] !== []) {
            return redirect()->route('admin.templates.index')
                ->with('error', "模板包「{$pack}」校验未通过，激活已中止：" . implode('；', $check['errors']));
        }

        $site = SiteContext::currentSite();
        if ($site === null) {
            abort(500);
        }

        if (! TemplatePackageManager::activate($pack)) {
            return redirect()->route('admin.templates.index')
                ->with('error', '模板激活失败：包清单无效或已缺失，激活态未改变。');
        }

        $summary = [];
        foreach (TemplatePackageManager::recipes($pack) as $key => $recipe) {
            $r = RecipeApplier::apply($site, $pack, $recipe);
            $summary[] = "{$key}（页面 {$r['pages']} / 新增 {$r['created']}）";
        }

        AuditLog::record('template.activate', "激活模板包：{$pack}", [], 'Setting');

        return redirect()->route('admin.templates.index')
            ->with('success', "已为当前站点激活模板包「{$pack}」并应用配方：" . implode('；', $summary) . '。');
    }

    /** 独立显式落地建议默认值（settings / menus / SEO），空值才写、不覆盖已有配置。 */
    public function bootstrap(string $pack): RedirectResponse
    {
        if (! TemplatePackageManager::exists($pack)) {
            abort(404);
        }

        $site = SiteContext::currentSite();
        if ($site === null) {
            abort(500);
        }

        $result = TemplateDefaultsInstaller::bootstrap($site, $pack, false);

        AuditLog::record('template.bootstrap', "初始化模板默认值：{$pack}", [], 'Setting');

        return redirect()->route('admin.templates.index')
            ->with('success', "模板「{$pack}」默认值初始化完成：" . $this->describeBootstrap($result) . '。');
    }

    public function deactivate(): RedirectResponse
    {
        TemplatePackageManager::deactivate();
        AuditLog::record('template.deactivate', '停用模板包，恢复核心模板', [], 'Setting');

        return redirect()->route('admin.templates.index')
            ->with('success', '已停用模板包，恢复核心模板。');
    }

    /**
     * RC-11 G：开启活站预览。
     *
     * 建一个**隔离的预览站**（独立 site_id / settings / menus / seo），
     * 把模板默认值装进去，然后跳到该站前台。
     *
     * 为什么不在当前站点上「激活 → 看 → 回滚」：
     *   TemplateDefaultsInstaller::bootstrap() 写Setting / Menu / SeoMeta，
     *   **无自动回滚**。拿真实站点做实验等于把生产数据当试验田。
     *   预览站用完整体删除即可，真实站点零风险。
     */
    public function openLivePreview(string $pack): RedirectResponse
    {
        if (! TemplatePackageManager::exists($pack)) {
            abort(404);
        }

        $site = TemplatePreviewSite::ensure($pack);
        if ($site === null) {
            return redirect()->route('admin.templates.index')
                ->with('error', "模板「{$pack}」无法创建预览站，请检查包结构。");
        }

        $url = TemplatePreviewSite::url($pack);

        return redirect()->away($url)
            ->with('success', '预览站已就绪：' . $url
                . '（独立站点，可随时「关闭预览」整体回收）');
    }

    /**
     * RC-11 G：关闭活站预览，回收预览站全部数据。
     */
    public function closeLivePreview(string $pack): RedirectResponse
    {
        if (! TemplatePackageManager::exists($pack)) {
            abort(404);
        }

        $site = Site::withoutGlobalScopes()
            ->where('slug', TemplatePreviewSite::SLUG_PREFIX . $pack)
            ->first();

        if ($site === null) {
            return redirect()->route('admin.templates.index')
                ->with('error', '该模板当前没有开启中的预览站。');
        }

        TemplatePreviewSite::discard($site);

        return redirect()->route('admin.templates.index')
            ->with('success', "模板「{$pack}」的预览站已关闭并清理。");
    }

    /**
     * 模板预览页：展示包自带的桌面 / 移动端预览截图（preview/*.webp）。
     *
     * 模板包的「激活效果」包含 recipe 落地的区块，无法在不写库的前提下用实时首页完整
     * 复现；因此预览采用模板作者随包提供的截图（模板生态 / SDK 的标准做法），不临时
     * 模拟激活，也不改变持久激活态。截图由 {@see self::screenshot()} 受控读取。
     */
    public function preview(string $pack): View
    {
        if (! TemplatePackageManager::exists($pack)) {
            abort(404);
        }

        $all = TemplatePackageManager::all();
        $previewDir = TemplatePackageManager::basePath() . '/' . $pack . '/preview';

        return view('admin.templates.preview', [
            'pack'       => $pack,
            'manifest'   => $all[$pack] ?? [],
            'hasDesktop' => is_file($previewDir . '/desktop.webp'),
            'hasMobile'  => is_file($previewDir . '/mobile.webp'),
        ]);
    }

    /** 受控返回模板包的预览截图（仅允许 desktop / mobile，realpath 防目录穿越）。 */
    public function screenshot(string $pack, string $view)
    {
        if (! TemplatePackageManager::exists($pack) || ! in_array($view, ['desktop', 'mobile'], true)) {
            abort(404);
        }

        $previewDir = realpath(TemplatePackageManager::basePath() . '/' . $pack . '/preview');
        $file = $previewDir !== false ? realpath($previewDir . '/' . $view . '.webp') : false;

        if ($previewDir === false || $file === false || ! str_starts_with($file, $previewDir) || ! is_file($file)) {
            abort(404);
        }

        return response()->file($file, [
            'Content-Type'  => 'image/webp',
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]);
    }

    /** 切换前只读对比当前激活包与目标包。 */
    public function compare(string $pack): View
    {
        if (! TemplatePackageManager::exists($pack)) {
            abort(404);
        }

        $currentId = TemplatePackageManager::active();

        return view('admin.templates.compare', [
            'currentId' => $currentId,
            'targetId'  => $pack,
            'current'   => $this->snapshot($currentId),
            'target'    => $this->snapshot($pack),
        ]);
    }

    /** 计算一个包用于对比的关键指标。 */
    private function snapshot(string $id): array
    {
        if ($id === '') {
            return ['name' => '核心模板（无激活包）', 'theme' => 'default', 'homeBlocks' => 0, 'recipes' => 0, 'menus' => false, 'seo' => 0];
        }

        $all = TemplatePackageManager::all();
        $manifest = $all[$id] ?? [];
        $home = TemplatePackageManager::recipe($id, 'homepage');

        $menus = ['main' => [], 'footer' => []];
        $menusFile = TemplatePackageManager::basePath() . '/' . $id . '/defaults/menus.json';
        if (is_file($menusFile)) {
            $decoded = json_decode((string) file_get_contents($menusFile), true);
            if (is_array($decoded)) {
                $menus = ['main' => $decoded['main'] ?? [], 'footer' => $decoded['footer'] ?? []];
            }
        }

        $seoCount = 0;
        $seoFile = TemplatePackageManager::basePath() . '/' . $id . '/defaults/seo.json';
        if (is_file($seoFile)) {
            $decoded = json_decode((string) file_get_contents($seoFile), true);
            if (is_array($decoded)) {
                $seoCount = count((array) ($decoded['site'] ?? []));
            }
        }

        return [
            'name'       => (string) ($manifest['name'] ?? $id),
            'theme'      => (string) ($manifest['theme'] ?? 'default'),
            'homeBlocks' => is_array($home) ? count((array) ($home['blocks'] ?? [])) : 0,
            'recipes'    => count(TemplatePackageManager::recipes($id)),
            'menus'      => (count($menus['main']) + count($menus['footer'])) > 0,
            'seo'        => $seoCount,
        ];
    }

    private function describeBootstrap(array $result): string
    {
        $parts = [];
        foreach (['settings' => '设置', 'menus' => '菜单', 'seo' => 'SEO'] as $key => $label) {
            $row = $result[$key] ?? ['applied' => [], 'skipped' => []];
            $parts[] = "{$label} 落地 " . count((array) $row['applied']) . ' / 跳过 ' . count((array) $row['skipped']);
        }

        return implode('；', $parts);
    }

    /**
     * 超管只读「各站当前激活模板包」总览；普通站点管理员返回 null（仅看本站）。
     *
     * @return array<int,array{site:\App\Models\Site,pack:string}>|null
     */
    private function crossSiteMatrix(): ?array
    {
        if (! SystemAuthorization::canCrossSite()) {
            return null;
        }

        $rows = Setting::withoutSiteScope()
            ->where('key', TemplatePackageManager::SETTINGS_KEY)
            ->get(['site_id', 'value']);
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->site_id] = (string) $row->value;
        }

        $matrix = [];
        foreach (Site::orderBy('id')->get() as $site) {
            $matrix[] = [
                'site' => $site,
                'pack' => $map[$site->id] ?? '',
            ];
        }

        return $matrix;
    }
}
