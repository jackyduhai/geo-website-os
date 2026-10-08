<?php

namespace App\Support;

use App\Models\Entity;

/**
 * Entity slug 守卫（WP-1）。
 * ------------------------------------------------------------------
 * ACTIVE（未软删）实体在同一 site 下 (type, slug) 唯一；软删行不占用 slug；
 * restore 时若当前已有 ACTIVE 同 slug 实体 → BLOCK（不产生两个 ACTIVE identity）。
 *
 * DB 层由 entities.active_slug 生成列 + 唯一索引做硬保证；本守卫是写入路径的
 * 显式权威判定，尤其让 restore 冲突在命中 DB 约束前得到干净的业务错误。
 */
class EntitySlugGuard
{
    /**
     * 是否存在 ACTIVE 同 (site, type, slug) 实体（可排除自身）。
     */
    public static function activeExists(int $siteId, string $type, string $slug, ?int $exceptId = null): bool
    {
        $query = Entity::query()
            ->where('site_id', $siteId)
            ->where('type', $type)
            ->where('slug', $slug);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists();
    }

    /**
     * Restore 前断言：当前不得有 ACTIVE 同 slug 实体，否则抛出阻断。
     */
    public static function assertCanRestore(Entity $entity): void
    {
        if (static::activeExists((int) $entity->site_id, (string) $entity->type, (string) $entity->slug, (int) $entity->id)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'restore' => "恢复失败：当前已存在同类型、同 slug（{$entity->slug}）的有效实体；"
                    . '请先改名或删除现有实体，避免产生两个有效身份。',
            ]);
        }
    }
}