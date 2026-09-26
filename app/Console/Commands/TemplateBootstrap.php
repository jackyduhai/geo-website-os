<?php

namespace App\Console\Commands;

use App\Support\CliSiteContext;
use App\Support\Templates\TemplateDefaultsInstaller;
use App\Support\Templates\TemplatePackageManager;
use Illuminate\Console\Command;

/**
 * template:bootstrap（P-STEP 18L-3b）。
 * ------------------------------------------------------------------
 * 生命周期「初始化默认内容」：把模板包 defaults/（settings / menus / site SEO）
 * 显式、幂等地落地到站点。
 *
 *   template:bootstrap {pack} [--site=] [--site-id=] [--force]
 *
 * 默认只在「当前为空」时写入建议值、已有值跳过（不覆盖）；--force 显式覆盖。
 * 必须在 template:activate 之后使用。defaults 全部写入正式模型，不造第二事实源。
 */
class TemplateBootstrap extends Command
{
    protected $signature = 'template:bootstrap
                            {pack : 模板包 id}
                            {--site= : 站点 slug}
                            {--site-id= : 站点 ID}
                            {--force : 覆盖已有默认值}';

    protected $description = 'Bootstrap a site with the pack default settings/menus/SEO';

    public function handle(): int
    {
        $pack = (string) $this->argument('pack');

        $site = CliSiteContext::resolve($this);
        if ($site === null) {
            return self::FAILURE;
        }

        if (! TemplatePackageManager::exists($pack)) {
            $this->error("Template pack not found or invalid: {$pack}");

            return self::FAILURE;
        }

        $report = TemplateDefaultsInstaller::bootstrap($site, $pack, (bool) $this->option('force'));

        foreach (['settings', 'menus', 'seo'] as $kind) {
            $applied = implode(', ', $report[$kind]['applied']);
            $skipped = implode(', ', $report[$kind]['skipped']);
            $this->line("  {$kind}: applied=[" . ($applied ?: 'none') . '] skipped=[' . ($skipped ?: 'none') . ']');
        }

        $this->info("Bootstrapped defaults for pack: {$pack}" . ($this->option('force') ? ' (forced)' : ''));

        return self::SUCCESS;
    }
}
