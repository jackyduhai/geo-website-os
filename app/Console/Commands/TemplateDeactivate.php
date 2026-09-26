<?php

namespace App\Console\Commands;

use App\Support\CliSiteContext;
use App\Support\Templates\TemplatePackageManager;
use Illuminate\Console\Command;

/**
 * template:deactivate（P-STEP 18L-3b；生命周期 Rollback）。
 * ------------------------------------------------------------------
 * 停用当前站点激活的模板包：清除 template_active_pack 设置、移除包级模板注册、
 * 恢复 core 模板，清页面缓存。保留包文件（仅停用，不删除）。
 *
 * 已由 recipe 落地的 Page / Block 不自动删除（避免误删管理员内容）；
 * 如需清理，由管理员在后台显式处理。
 */
class TemplateDeactivate extends Command
{
    protected $signature = 'template:deactivate
                            {--site= : 站点 slug}
                            {--site-id= : 站点 ID}';

    protected $description = 'Deactivate the active template pack (rollback to core templates)';

    public function handle(): int
    {
        $site = CliSiteContext::resolve($this);
        if ($site === null) {
            return self::FAILURE;
        }

        $active = TemplatePackageManager::active();
        if ($active === '') {
            $this->warn('No active template pack on this site.');

            return self::SUCCESS;
        }

        TemplatePackageManager::deactivate();
        $this->info("Deactivated template pack: {$active}. Core templates restored.");

        return self::SUCCESS;
    }
}
