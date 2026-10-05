<?php

namespace App\Support;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Fact;
use App\Models\Group;
use App\Models\Media;
use App\Models\Menu;
use App\Models\PageBlock;
use App\Models\Redirect;
use App\Models\SeoMeta;
use App\Models\Setting;
use App\Models\Site;

/**
 * 前台展示失效映射（20F-HAT P1-1 收口）。
 * ------------------------------------------------------------------
 * 「哪些模型的新增 / 修改 / 删除会影响前台展示」的唯一登记处。
 * 登记在此的模型保存 / 删除时自动使整页缓存版本号 +1，前台立刻生效。
 *
 * 背景：HAT-2026-10 实测「发布知识资产后首页不变」——Entity 上线时没有
 * 进入原先散落在 AppServiceProvider 内联数组里的失效清单。统一成显式
 * 登记表后，新增前台可见数据模型（20G 的 Evidence 等）只改这一处，
 * 并有测试锁定关键成员，杜绝再次漏登记。
 *
 * 刻意不登记（不影响前台展示）：Inquiry、AuditLog、SyncLog、User。
 */
class CacheInvalidationMap
{
    /** 影响前台展示的模型清单。 */
    public const MODELS = [
        // 内容域
        Content::class,
        ContentRevision::class,
        Category::class,
        Group::class,
        // 展示装修域
        Banner::class,
        Menu::class,
        PageBlock::class,
        Media::class,
        // 站点 / 设置域
        Setting::class,
        Site::class,
        SeoMeta::class,
        Redirect::class,
        // 事实与知识资产域（GEO 目录）
        Fact::class,
        Entity::class,
        EntityRelation::class,
    ];

    /** 注册保存 / 删除监听：版本号 +1，整页缓存作废。由 AppServiceProvider 启动时调用。 */
    public static function register(): void
    {
        foreach (self::MODELS as $model) {
            $model::saved(static fn () => PageCache::flush());
            $model::deleted(static fn () => PageCache::flush());
        }
    }
}
