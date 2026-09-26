<?php

namespace App\Console\Commands;

use App\Support\Blocks\BlockRegistry;
use App\Support\Blocks\SectionSemantic;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Templates\TemplateRecipeValidator;
use Illuminate\Console\Command;

/**
 * template:validate（P-STEP 18L-3b）。
 * ------------------------------------------------------------------
 * 对模板包做三层静态校验（在「应用 / 激活」之前）：
 *
 *   Layer 1 — Manifest Schema（TemplateManifestValidator）
 *   Layer 2 — Recipe（结构 / block 注册 / 必填字段 / grid source）
 *   Layer 3 — Runtime Compatibility（block 允许进入模板槽位 / defaults / preview）
 *
 * 输出分级：ERROR 阻断（exit 1）、WARNING 放行；--strict 时 WARNING 也失败（CI 用）。
 * 不修改任何数据。
 */
class TemplateValidate extends Command
{
    protected $signature = 'template:validate {pack? : Pack id to validate (default: all)} {--strict : Treat warnings as failures}';

    protected $description = 'Validate template packs (manifest, recipes, runtime compatibility)';

    public function handle(): int
    {
        $pack = $this->argument('pack');
        $base = TemplatePackageManager::basePath();

        if ($pack !== null) {
            $packPaths = [$base . '/' . $pack];
            if (! is_dir($packPaths[0])) {
                $this->error("Template pack「{$pack}」not found.");

                return self::FAILURE;
            }
        } else {
            $packPaths = array_map(
                static fn ($dir) => $dir,
                glob($base . '/*', GLOB_ONLYDIR) ?: []
            );
        }

        if ($packPaths === []) {
            $this->warn('No template packs found in resources/templates.');

            return self::SUCCESS;
        }

        $totalErrors = 0;
        $totalWarnings = 0;

        foreach ($packPaths as $packPath) {
            $id = basename($packPath);
            $result = TemplateRecipeValidator::inspect($packPath);

            $nErrors = count($result['errors']);
            $nWarnings = count($result['warnings']);
            $totalErrors += $nErrors;
            $totalWarnings += $nWarnings;

            $this->line(sprintf(
                '<info>%s</info>  recipes=%d blocks=%d  <error>ERROR=%d</error>  <comment>WARNING=%d</comment>',
                $id,
                $result['stats']['recipes'],
                $result['stats']['blocks'],
                $nErrors,
                $nWarnings
            ));

            foreach ($result['errors'] as $e) {
                $this->line("    <error>- {$e}</error>");
            }
            foreach ($result['warnings'] as $w) {
                $this->line("    <comment>- {$w}</comment>");
            }
        }

        // TD-132：平台 Section Semantic 词表自检（内置默认映射 + config 显式声明）。
        $semanticErrors = array_merge(
            SectionSemantic::validateDefaults(),
            $this->declaredSemanticErrors()
        );
        if ($semanticErrors !== []) {
            $this->line('<error>Section Semantic</error>');
            foreach ($semanticErrors as $semanticError) {
                $this->line("    <error>- {$semanticError}</error>");
            }
            $totalErrors += count($semanticErrors);
        }

        if ($totalErrors > 0) {
            $this->error("Validation failed: {$totalErrors} error(s). Pack cannot be applied.");

            return self::FAILURE;
        }

        if ($totalWarnings > 0 && $this->option('strict')) {
            $this->error("Strict mode: {$totalWarnings} warning(s) treated as failure.");

            return self::FAILURE;
        }

        $this->info('All template packs valid.' . ($totalWarnings > 0 ? " ({$totalWarnings} warning(s))" : ''));

        return self::SUCCESS;
    }

    /** 校验 config/blocks.php 各 block 的显式 semantic 声明，返回错误信息数组。 */
    private function declaredSemanticErrors(): array
    {
        $errors = [];
        foreach (BlockRegistry::all() as $type) {
            foreach (SectionSemantic::validateDeclared($type->semantic) as $message) {
                $errors[] = "{$type->type}: {$message}";
            }
        }

        return $errors;
    }
}
