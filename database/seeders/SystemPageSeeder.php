<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Site;
use App\Support\PageCache;
use App\Support\Render\SystemPageRenderContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * 固定系统页种子（P-STEP 18G-2b）。
 * --------------------------------------------------
 * 由 geo:install 在默认站点注入 is_system Page，为固定路由提供页面身份 / SEO /
 * 模板绑定：产品总览、场景总览、知识总览、About（profile/history/culture）、
 * 工厂、合作、联系。全部默认 published、行业中性；业务事实仍来自 Site / Setting /
 * Entity / Content，Page 不复制。
 *
 *  - 总览 / About / 工厂 / 合作：main 固定槽由对应 sys_* 系统块直驱（控制器准备数据）；
 *  - 联系：contact 模板，header 标题 + main 联系信息 / 咨询表单（普通可组合 block，
 *    管理员可改 ContactInfo、加 / 删 FormReference，满足 Scenario A）。
 *
 * Demo 内容仍由 DemoSeeder 独立加载，不污染出厂系统页（Blank System ≠ Demo Site）。
 * 幂等：updateOrCreate，重复执行安全。
 */
class SystemPageSeeder extends Seeder
{
    /**
     * @param  Site|null  $target  目标站点；缺省回退默认站点（geo:install 调用兼容）。
     *                            Admin 建站（SiteController::store）显式传入新站。
     */
    public function __construct(private ?Site $target = null)
    {
    }

    public function run(): void
    {
        $site = $this->target ?? Site::where('slug', Site::DEFAULT_SLUG)->first();
        if (! $site) {
            return;
        }

        // Seeder 会为逐语言页面临时切换 locale；必须在结束时恢复，否则在测试进程内
        // （$this->seed(...)）会让 App locale 停留在 'en'，污染后续菜单 / 前台渲染。
        $originalLocale = App::getLocale();

        try {
            // 默认定义唯一来源在 SystemPageRenderContext::DEFINITIONS（fallback 共用）。
            foreach (SystemPageRenderContext::DEFINITIONS as $key => [$template, $slugPath, $titleKey]) {
                $zhPage = $this->ensurePage($site, $key, $template, $slugPath, $titleKey, 'zh-CN');
                $this->ensurePage($site, $key, $template, $slugPath, $titleKey, 'en', $zhPage->translation_group);

                if ($key === 'contact') {
                    $this->ensureContactBlocks($zhPage);
                    $enContact = $zhPage->translation('en');
                    if ($enContact) {
                        $this->ensureContactBlocks($enContact);
                    }
                }
            }
        } finally {
            App::setLocale($originalLocale);
            PageCache::flush();
        }
    }

    private function ensurePage(
        Site $site,
        string $key,
        string $template,
        string $slugPath,
        string $titleKey,
        string $locale,
        ?string $group = null
    ): Page {
        App::setLocale($locale);

        $values = [
            'template' => $template,
            'slug' => $slugPath,
            'is_system' => true,
            'is_home' => false,
            'status' => Page::STATUS_PUBLISHED,
            'title' => (string) __($titleKey),
        ];
        if ($group !== null) {
            $values['translation_group'] = $group;
        }

        return Page::updateOrCreate(
            ['site_id' => $site->id, 'system_key' => $key, 'locale' => $locale],
            $values
        );
    }

    private function ensureContactBlocks(Page $page): void
    {
        // 区块固定文案必须使用该页面对应语言（调用前 locale 可能停留在另一语言）。
        App::setLocale($page->locale);

        // header：可见页头标题（contact 模板无 hero，composed 另提供视觉隐藏 H1）。
        $this->ensureBlock($page, [
            'page' => 'contact', 'slot' => 'header', 'type' => 'rich_text',
            'sort' => 0, 'limit' => 0,
            'content' => json_encode(['title' => (string) __('ui.contact_h1'), 'body' => '']),
        ]);

        // main：联系信息（完整事实列）。
        $this->ensureBlock($page, [
            'page' => 'contact', 'slot' => 'main', 'type' => 'contact_info',
            'sort' => 0, 'limit' => 0,
            'content' => json_encode([
                'title' => '',
                'show_phone' => true, 'show_email' => true,
                'show_address' => true, 'show_social' => true,
            ]),
        ]);

        // main：咨询表单（FormReference，引用默认 contact 表单；字段由表单配置驱动）。
        $contactFormId = \App\Models\Form::where('slug', \App\Models\Form::DEFAULT_SLUG)->value('id');
        $this->ensureBlock($page, [
            'page' => 'contact', 'slot' => 'main', 'type' => 'form_reference',
            'sort' => 1, 'limit' => 0,
            'content' => json_encode(
                ['title' => '', 'subtitle' => '', 'form_id' => $contactFormId],
                JSON_UNESCAPED_UNICODE
            ),
        ]);
    }

    private function ensureBlock(Page $page, array $vals): void
    {
        PageBlock::updateOrCreate(
            ['page_id' => $page->id, 'slot' => $vals['slot'], 'type' => $vals['type']],
            [
                'site_id' => $page->site_id,
                'page' => $vals['page'],
                'title' => $vals['title'] ?? null,
                'subtitle' => null,
                'content' => $vals['content'],
                'category_id' => null,
                'limit' => $vals['limit'],
                'sort' => $vals['sort'],
                'is_active' => true,
            ]
        );
    }
}
