<?php

namespace App\Support;

use App\Models\Entity;
use Illuminate\Support\Str;

/**
 * 实体 slug 归一（TD-161）。
 *
 * 后台 CRUD 的 slug 由用户提供并经 `^[a-z0-9]+(?:-[a-z0-9]+)*$` 校验；
 * 而向导 / 未来的批量导入用 `Str::slug(name)` 自动生成 slug。纯中文产品名
 * （如「实木餐桌」）经 `Str::slug()` 得到空串，会命中 entities.slug NOT NULL。
 *
 * 这里是唯一兜底点：候选 slug 非空则原样使用；为空时退化为稳定、URL 安全、
 * 按站点/类型/语言唯一的 token（`{type}-{hash}`），不另造一套 URL 规则。
 */
class EntitySlug
{
    /**
     * 由名称得到合法 slug：Str::slug 非空即用，为空用哈希兜底。
     */
    public static function fromName(string $name, string $type, ?int $siteId, ?string $locale): string
    {
        $candidate = Str::slug($name);

        if ($candidate === '') {
            // id 在 insert 前不可得，用 site+type+locale+name 的短哈希做等价唯一回退：
            // 非空、[a-z0-9]、对同一输入稳定可复现。
            $candidate = $type.'-'.substr(md5(($siteId ?? 0).'|'.$type.'|'.($locale ?? '').'|'.$name), 0, 10);
        }

        return self::ensureUnique($candidate, $type, $siteId, $locale);
    }

    /**
     * 保证 base 在 站点+类型+语言 范围内唯一（冲突追加 -2 / -3 …）。
     */
    public static function ensureUnique(string $base, string $type, ?int $siteId, ?string $locale): string
    {
        $slug = $base;
        $i = 2;

        while (Entity::withoutSiteScope()
            ->where('type', $type)
            ->where('slug', $slug)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->when($locale !== null, fn ($q) => $q->where('locale', $locale))
            ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
