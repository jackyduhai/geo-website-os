<?php

namespace App\Console\Commands;

use App\Models\PageBlock;
use App\Models\Site;
use App\Support\PageCache;
use App\Support\SiteContext;
use App\Support\Templates\TemplateMigrationRegistry;
use App\Support\Templates\TemplateMigrator;
use App\Support\Templates\TemplatePackageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * template:migrate（P-STEP 18L-4b-3，TD-131 Lite）。
 * --------------------------------------------------
 * 按模板包 migration.json 的声明式规则，把站点已落地的 PageBlock 从旧版本
 * 结构安全迁移到新版本：
 *
 *   validate（规则安全）→ backup（快照）→ [dry-run] → apply（事务）→ validate（幂等）
 *
 *   - --dry-run：只输出变更计划，不写库；
 *   - --force：确认执行（默认不写，防止误操作）；
 *   - --rollback：恢复最近一次备份；
 *   - 失败（事务异常 / 迁移后仍非幂等）自动回滚并保留备份。
 *
 * 硬边界：规则只改结构键 / block type / variant / token 引用，不自动修改
 * SEO 内容、GEO semantic、URL、Schema；block 替换带来的语义变化在计划中显式标注。
 */
class TemplateMigrate extends Command
{
    protected $signature = 'template:migrate
        {pack : Template pack id}
        {--from= : Source version (default: first declared path)}
        {--to= : Target version}
        {--site= : Site id to migrate (default: current/first)}
        {--dry-run : Only show planned changes, do not write}
        {--force : Confirm applying the migration}
        {--rollback : Restore the latest backup for this pack}';

    protected $description = 'Migrate a site\'s composed blocks between template versions (declarative rules)';

    private string $backupDir;

    public function __construct()
    {
        parent::__construct();
        $this->backupDir = storage_path('app/template-migration-backups');
    }

    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $pack = (string) $this->argument('pack');
        $base = TemplatePackageManager::basePath();
        $packPath = $base . '/' . $pack;

        if (! is_dir($packPath)) {
            $this->error("Template pack「{$pack}」not found.");

            return self::FAILURE;
        }

        if (! TemplateMigrationRegistry::hasRules($packPath)) {
            $this->warn("Pack「{$pack}」declares no migration.json rules; nothing to migrate.");

            return self::SUCCESS;
        }

        // ① validate：规则本身必须安全（无保护字段 / 目标 block 已注册）。
        $ruleErrors = TemplateMigrationRegistry::validate($packPath);
        if ($ruleErrors !== []) {
            $this->error('Migration rules are invalid:');
            foreach ($ruleErrors as $e) {
                $this->line("    <error>- {$e}</error>");
            }

            return self::FAILURE;
        }

        $resolved = TemplateMigrationRegistry::rulesFor(
            $packPath,
            $this->nonEmpty($this->option('from')),
            $this->nonEmpty($this->option('to'))
        );
        if ($resolved === null) {
            $this->error('No migration path matches the requested from/to versions.');

            return self::FAILURE;
        }

        $rules = $resolved['rules'];
        $siteId = $this->resolveSiteId();
        $blocks = PageBlock::where('site_id', $siteId)->get();

        // ② plan（dry-run 与实际执行共用同一计划）。
        $plan = TemplateMigrator::plan($blocks, $rules);
        $this->presentPlan($pack, $resolved, $siteId, $plan);

        if ($plan['changes'] === []) {
            $this->info('Already up to date — no blocks require migration.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run only — no changes written. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->comment('Changes are pending. Re-run with --force to apply, or --dry-run to review.');

            return self::SUCCESS;
        }

        return $this->apply($pack, $resolved, $siteId, $blocks, $rules, $plan);
    }

    /** 备份 → 事务应用 → 幂等验证。 */
    private function apply(
        string $pack,
        array $resolved,
        int $siteId,
        $blocks,
        array $rules,
        array $plan
    ): int {
        $backupFile = $this->writeBackup($pack, $resolved, $siteId, $plan['changes']);
        $this->line("<info>Backup written:</info> {$backupFile}");

        try {
            DB::transaction(function () use ($blocks, $rules) {
                TemplateMigrator::apply($blocks, $rules);
            });
        } catch (\Throwable $e) {
            $this->error("Migration failed and was rolled back: {$e->getMessage()}");
            $this->line("Backup retained at: {$backupFile}");

            return self::FAILURE;
        }

        // ③ validate：迁移后再次规划应无变更（确定性、幂等）。
        $recheck = TemplateMigrator::plan(
            PageBlock::where('site_id', $siteId)->get(),
            $rules
        );
        if ($recheck['changes'] !== []) {
            $this->error('Migration is not idempotent after apply; restoring backup.');
            $this->restoreBackup($backupFile);

            return self::FAILURE;
        }

        PageCache::flush();

        $this->info('Migration applied successfully.');
        $this->line('  ' . $this->summaryLine($plan['summary']));

        return self::SUCCESS;
    }

    /** 呈现迁移计划（动作表 + 汇总 + 语义变化）。 */
    private function presentPlan(string $pack, array $resolved, int $siteId, array $plan): void
    {
        $this->line(sprintf(
            '<info>%s</info>  %s → %s  site=%d',
            $pack,
            $resolved['from'] ?: '?',
            $resolved['to'] ?: '?',
            $siteId
        ));

        $rows = [];
        foreach ($plan['changes'] as $change) {
            $typeNote = $change['new_type'] !== null
                ? $change['old_type'] . ' → ' . $change['new_type']
                : $change['old_type'];
            $rows[] = [
                $change['block_id'],
                $change['slot'],
                $typeNote,
                implode('; ', $change['actions']),
            ];
        }

        if ($rows !== []) {
            $this->table(['Block', 'Slot', 'Type', 'Changes'], $rows);
        }

        $this->line('  <comment>' . $this->summaryLine($plan['summary']) . '</comment>');

        // block 替换带来的 GEO 语义变化（显式标注，不隐藏）。
        foreach ($plan['changes'] as $change) {
            if ($change['semantic'] !== null) {
                $old = implode('/', $change['semantic']['old']) ?: '-';
                $new = implode('/', $change['semantic']['new']) ?: '-';
                $this->line("    <comment>• block #{$change['block_id']} GEO section：{$old} → {$new}</comment>");
            }
        }
    }

    /** 写备份快照（仅受影响 blocks）。 */
    private function writeBackup(string $pack, array $resolved, int $siteId, array $changes): string
    {
        if (! is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0775, true);
        }

        $snapshot = [];
        foreach ($changes as $change) {
            $snapshot[] = [
                'id' => $change['block_id'],
                'type' => $change['old_type'],
                'content' => $change['old_content'],
            ];
        }

        $payload = [
            'pack' => $pack,
            'from' => $resolved['from'],
            'to' => $resolved['to'],
            'site_id' => $siteId,
            'created_at' => now()->toIso8601String(),
            'blocks' => $snapshot,
        ];

        $stamp = now()->format('Ymd-His');
        $file = $this->backupDir . '/' . "{$pack}-{$resolved['from']}-{$resolved['to']}-{$stamp}.json";
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $file;
    }

    /** 恢复最近一次备份（--rollback）。 */
    private function rollback(): int
    {
        $pack = (string) $this->argument('pack');
        $files = glob($this->backupDir . '/' . $pack . '-*.json') ?: [];
        if ($files === []) {
            $this->error("No backup found for pack「{$pack}」.");

            return self::FAILURE;
        }

        $file = end($files);
        $this->restoreBackup($file);
        $this->info("Rolled back pack「{$pack}」from: " . basename($file));

        return self::SUCCESS;
    }

    /** 按备份文件内容恢复 blocks。 */
    private function restoreBackup(string $file): void
    {
        $payload = json_decode((string) file_get_contents($file), true);
        DB::transaction(function () use ($payload) {
            foreach ((array) ($payload['blocks'] ?? []) as $row) {
                $pb = PageBlock::find($row['id']);
                if ($pb === null) {
                    continue;
                }
                $pb->type = $row['type'];
                $pb->content = json_encode($row['content'], JSON_UNESCAPED_UNICODE);
                $pb->save();
            }
        });
        PageCache::flush();
    }

    /** 解析目标站点 id（--site > 当前上下文 > 第一个站点）。 */
    private function resolveSiteId(): int
    {
        $option = $this->nonEmpty($this->option('site'));
        if ($option !== null) {
            return (int) $option;
        }

        $current = SiteContext::currentSiteId();
        if ($current !== null) {
            return (int) $current;
        }

        return (int) (Site::min('id') ?? 1);
    }

    private function summaryLine(array $summary): string
    {
        return sprintf(
            'blocks=%d  replace=%d  rename_fields=%d  rename_variants=%d  rename_tokens=%d',
            $summary['blocks_affected'],
            $summary[TemplateMigrationRegistry::REPLACE_BLOCKS],
            $summary[TemplateMigrationRegistry::RENAME_FIELDS],
            $summary[TemplateMigrationRegistry::RENAME_VARIANTS],
            $summary[TemplateMigrationRegistry::RENAME_TOKENS]
        );
    }

    private function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
