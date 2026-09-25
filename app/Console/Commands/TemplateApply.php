<?php

namespace App\Console\Commands;

use App\Support\CliSiteContext;
use App\Support\Templates\RecipeApplier;
use App\Support\Templates\TemplatePackageManager;
use Illuminate\Console\Command;

/**
 * template:apply（P-STEP 18L-3a）。
 * ------------------------------------------------------------------
 * 激活模板包并把其配方应用到站点（幂等，可重复执行）：
 *   template:apply {pack} [--site=] [--recipe=key1,key2]
 *
 * 流程：解析站点 → 校验包 → 激活（注册包模板）→ 逐个配方落地 Page / Block。
 * 只组合已注册核心 block，不携带可执行代码；不复制业务事实。
 */
class TemplateApply extends Command
{
    protected $signature = 'template:apply
                            {pack : 模板包 id}
                            {--site= : 站点 slug}
                            {--site-id= : 站点 ID}
                            {--recipe= : 只应用指定配方（逗号分隔，默认全部）}';

    protected $description = 'Activate a template pack and apply its recipes to a site';

    public function handle(): int
    {
        $pack = (string) $this->argument('pack');

        $site = CliSiteContext::resolve($this);
        if ($site === null) {
            return self::FAILURE;
        }

        if (! TemplatePackageManager::exists($pack)) {
            $this->error("Template pack not found or invalid: {$pack}");
            foreach (TemplatePackageManager::invalidPacks() as $id => $errors) {
                if ($id === $pack) {
                    foreach ($errors as $error) {
                        $this->line("  - {$error}");
                    }
                }
            }

            return self::FAILURE;
        }

        if (! TemplatePackageManager::activate($pack)) {
            $this->error("Failed to activate template pack: {$pack}");

            return self::FAILURE;
        }
        $this->info("Activated template pack: {$pack}");

        $recipes = TemplatePackageManager::recipes($pack);
        $only = trim((string) $this->option('recipe'));
        if ($only !== '') {
            $keys = array_flip(array_map('trim', explode(',', $only)));
            $recipes = array_intersect_key($recipes, $keys);
        }

        if ($recipes === []) {
            $this->warn('No recipes to apply.');

            return self::SUCCESS;
        }

        foreach ($recipes as $key => $recipe) {
            $r = RecipeApplier::apply($site, $pack, $recipe);
            $this->line(sprintf(
                '  recipe [%-10s] pages=%d created=%d updated=%d deleted=%d locales=%s',
                $key,
                $r['pages'],
                $r['created'],
                $r['updated'],
                $r['deleted'],
                implode(',', $r['locales'])
            ));
        }

        return self::SUCCESS;
    }
}
