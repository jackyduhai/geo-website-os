<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\Media;
use App\Models\SeoMeta;
use Illuminate\Console\Command;

/**
 * geo:backfill-seo（P-STEP 07 / P0-B：legacy 字段数据迁移）
 *
 * 把 contents 表的 legacy SEO 字段（seo_title / seo_desc / canonical / noindex）
 * 迁移到 Content-level SeoMeta。幂等：已有 SeoMeta 的 Content 跳过。
 *
 * 执行顺序契约：必须在删除 legacy 列的 migration 之前运行（geo:upgrade
 * 已保证该顺序）；本命令在 legacy 列不存在时为 no-op（全新库安全）。
 */
class GeoBackfillSeo extends Command
{
    protected $signature = 'geo:backfill-seo {--force : 覆盖已有 SeoMeta（默认跳过）}';
    protected $description = 'Backfill legacy contents SEO columns into seo_metas (idempotent, run before column drop)';

    public function handle(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('contents', 'seo_title')) {
            $this->line('  [skip] contents legacy SEO columns not present (already migrated)');

            return self::SUCCESS;
        }

        $contents = Content::withoutSiteScope()
            ->where(function ($q) {
                $q->whereNotNull('seo_title')->where('seo_title', '!=', '')
                    ->orWhereNotNull('seo_desc')->where('seo_desc', '!=', '')
                    ->orWhereNotNull('canonical')->where('canonical', '!=', '')
                    ->orWhere('noindex', true);
            })
            ->get();

        $created = 0;
        $updated = 0;
        foreach ($contents as $content) {
            $payload = array_filter([
                'title'       => $content->seo_title,
                'description' => $content->seo_desc,
                'canonical'   => $content->canonical,
                'noindex'     => (bool) $content->noindex,
            ], fn ($v) => $v !== null && $v !== '');

            $existing = SeoMeta::where('site_id', $content->site_id)
                ->where('content_id', $content->id)
                ->first();

            if ($existing) {
                if ($this->option('force')) {
                    $existing->fill($payload)->save();
                    $updated++;
                }
                continue;
            }

            SeoMeta::create(array_merge([
                'site_id'    => $content->site_id,
                'content_id' => $content->id,
            ], $payload));
            $created++;
        }

        $this->line("  [ok] backfill: {$created} SeoMeta created, {$updated} updated");

        return self::SUCCESS;
    }
}
