<?php

namespace App\Console\Commands;

use App\Support\CliSiteContext;
use App\Support\Templates\RecipeApplier;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Templates\TemplateRecipeValidator;
use Illuminate\Console\Command;

/**
 * template:activate（P-STEP 18L-3b；取代 18L-3a 的 template:apply）。
 * ------------------------------------------------------------------
 * 生命周期「启用模板」：校验 → 激活（注册包模板）→ 应用配方生成页面骨架。
 *
 *   template:activate {pack} [--site=] [--site-id=] [--recipe=key1,key2]
 *
 * 应用前先跑三层 template:validate（manifest / recipe / runtime），存在 ERROR
 * 则拒绝激活（把 18L-3a 那种运行时 500 提前到应用前）。
 *
 * defaults（settings/menus/seo）不在本动作落地，由 template:bootstrap 显式完成，
 * 避免启用骨架时误覆盖站点设置。包只组合已注册 block，不携带可执行代码。
 */
class TemplateActivate extends Command
{
    protected $signature = 'template:activate
                            {pack : 模板包 id}
                            {--site= : 站点 slug}
                            {--site-id= : 站点 ID}
                            {--recipe= : 只应用指定配方（逗号分隔，默认全部）}';

    protected $description = 'Validate, activate a template pack and apply its recipes';

    public function handle(): int
    {
        $pack = (string) $this->argument('pack');

        $site = CliSiteContext::resolve($this);
        if ($site === null) {
            return self::FAILURE;
        }

        $packPath = TemplatePackageManager::basePath() . '/' . $pack;
        if (! is_dir($packPath)) {
            $this->error("Template pack not found: {$pack}");

            return self::FAILURE;
        }

        // 三层校验，ERROR 阻断
        $check = TemplateRecipeValidator::inspect($packPath);
        foreach ($check['errors'] as $error) {
            $this->line("  <error>- {$error}</error>");
        }
        foreach ($check['warnings'] as $warning) {
            $this->line("  <comment>- {$warning}</comment>");
        }
        if ($check['errors'] !== []) {
            $this->error("Template pack「{$pack}」failed validation; activation aborted.");

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
