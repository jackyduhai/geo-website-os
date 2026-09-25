<?php

namespace App\Console\Commands;

use App\Support\Templates\TemplatePackageManager;
use Illuminate\Console\Command;

/**
 * template:list（P-STEP 18L-3a）。
 * ------------------------------------------------------------------
 * 列出 resources/templates 下发现的模板包：有效包（id / 版本 / 行业 / 语言 /
 * 激活态）与无效包（清单校验错误）。不修改任何数据。
 */
class TemplateList extends Command
{
    protected $signature = 'template:list';

    protected $description = 'List discovered template packs (valid and invalid)';

    public function handle(): int
    {
        $active = TemplatePackageManager::active();
        $packs = TemplatePackageManager::all();

        if ($packs === []) {
            $this->warn('No valid template packs found in resources/templates.');
        } else {
            $this->info('Available template packs:');
            foreach ($packs as $id => $manifest) {
                $flag = $id === $active ? '  <active>' : '';
                $this->line(sprintf(
                    '  %-22s v%-8s [%s]%s',
                    $id,
                    $manifest['version'] ?? '?',
                    implode(', ', (array) ($manifest['industry'] ?? [])),
                    $flag
                ));
                $this->line('      locales: ' . implode(', ', (array) ($manifest['locales'] ?? [])));
            }
        }

        foreach (TemplatePackageManager::invalidPacks() as $id => $errors) {
            $this->error("  invalid pack [{$id}]:");
            foreach ($errors as $error) {
                $this->line("      - {$error}");
            }
        }

        return self::SUCCESS;
    }
}
