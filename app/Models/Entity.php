<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\BelongsToSite;
use App\Support\EntitySlug;
use App\Support\FactReviewSignaler;
use App\Support\Localization\LocaleRegistry;
use App\Support\PageCache;
use App\Support\TombstoneResolver;
use App\Support\Translatable;

class Entity extends Model
{
    use SoftDeletes, BelongsToSite, Translatable;

    protected $guarded = [];

    /** 跨语言共享列（默认语言权威行单向同步）；name/slug/summary/description 按语言独立。 */
    protected static array $sharedTranslatableColumns = [
        'type', 'role', 'status', 'published_at', 'metadata', 'sort_order',
    ];

    // Organization 生命周期角色（WP-1）：role 列是生命周期策略唯一权威。
    const ROLE_SITE_SUBJECT = 'site_subject';
    const ROLE_CUSTOMER = 'customer';

    protected static function booted(): void
    {
        // TD-161：slug 兜底。后台 CRUD 总是显式提供合法 slug；此处仅对 slug 为空的写入
        // （向导 Str::slug(纯中文名) 得空串 / 未来批量导入）归一为合法唯一 slug，覆盖全部写入路径。
        static::creating(function (self $entity): void {
            if (trim((string) $entity->slug) === '') {
                $entity->slug = EntitySlug::fromName((string) $entity->name, (string) $entity->type, $entity->site_id, $entity->locale);
            }
        });

        // WP-1：role 归一。显式写入优先；未显式设置时从 metadata 兜底（覆盖向导 /
        // Seeder / 测试等只写 metadata 的路径），此后 role 驱动生命周期策略。
        static::saving(function (self $entity): void {
            if ($entity->role === null || $entity->role === '') {
                $metadata = is_array($entity->metadata) ? $entity->metadata : [];
                if ($entity->type === self::TYPE_ORGANIZATION) {
                    if (! empty($metadata['is_site_organization'])) {
                        $entity->role = self::ROLE_SITE_SUBJECT;
                    } elseif (($metadata['role'] ?? null) === 'customer') {
                        $entity->role = self::ROLE_CUSTOMER;
                    }
                }
            }
        });

        // P-STEP 18C / TD-08b：Entity 是前台目录、Schema、GEO、Sitemap 的权威数据源。
        // 整页缓存失效唯一登记在 CacheInvalidationMap，此处不重复挂 flush。
        //
        // WP-1：终态（forceDelete）才清理 Entity 拥有的结构投影；软删（可恢复）一律保留
        // Override Page 与 ContentEntity，以支持无损恢复。
        static::forceDeleted(function (self $entity): void {
            // Entity Detail Override（Entity-owned projection）随 purge 级联清除（含其 block / seo）。
            Page::where('entity_id', $entity->id)->get()->each->delete();
            // content_entity 无 DB 外键，终态清理避免孤儿堆积。
            ContentEntity::where('entity_id', $entity->id)->delete();

            // WP-4：仅默认语言锚点 purge 时写一条墓碑（entity refs / content_entity
            // 指向锚点 id）；翻译行级联 purge 不重复写。墓碑只作身份判定
            // （KNOWN-DELETED vs UNKNOWN-ID），不复活实体、不进 PKS。
            if ($entity->locale === LocaleRegistry::default()) {
                app(TombstoneResolver::class)->record($entity);

                // WP-8：purge 后产生 site 粒度事实复核 open marker + 一次性审计事件。
                // 没有 fact_entity_refs，不声称自动定位受影响具体 Fact；Fact 行不被 mutate。
                app(FactReviewSignaler::class)->signalForPurge($entity);
                AuditLog::record(
                    'fact.review_signaled',
                    '实体已永久删除，触发站点事实复核：' . $entity->name,
                    ['entity_type' => $entity->type, 'entity_id' => $entity->id, 'scope' => 'site'],
                    'entity',
                    $entity->id
                );
            }
        });
    }

    protected $casts = [
        'metadata' => 'array',
        'published_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    // Entity types（能力声明以 config/entities.php 的 EntityCapabilityRegistry 为唯一事实源；
    // 这里只放代码内可能引用的 type 字符串常量，新增类型无需改 DB——type 是 varchar(32) 无 CHECK）。
    const TYPE_ORGANIZATION = 'organization';
    const TYPE_PRODUCT = 'product';
    const TYPE_SERVICE = 'service';
    const TYPE_PERSON = 'person';
    const TYPE_LOCATION = 'location';
    const TYPE_TOPIC = 'topic';
    const TYPE_CASE_STUDY = 'case_study';
    const TYPE_DOWNLOAD_ASSET = 'download_asset';

    // Status
    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_ARCHIVED = 'archived';

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function relationsFrom(): HasMany
    {
        return $this->hasMany(EntityRelation::class, 'from_entity_id');
    }

    public function relationsTo(): HasMany
    {
        return $this->hasMany(EntityRelation::class, 'to_entity_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /** 站点主体 organization（聚合根，受保护，普通删除被禁止）。 */
    public function isSiteSubject(): bool
    {
        return $this->type === self::TYPE_ORGANIZATION && $this->role === self::ROLE_SITE_SUBJECT;
    }

    /** 客户案例 organization（独立生命周期）。 */
    public function isCustomer(): bool
    {
        return $this->type === self::TYPE_ORGANIZATION && $this->role === self::ROLE_CUSTOMER;
    }

    /**
     * 反向查询：引用了某产品的已发布客户案例（18R-2b，回答"哪些案例证明该产品有效"）。
     * CaseStudy --related_to--> Product；这里反向从 Product 找 case_study。
     */
    public static function relatedCaseStudiesForProduct(int $productId, ?string $locale = null): \Illuminate\Database\Eloquent\Builder
    {
        $caseIds = EntityRelation::where('to_entity_id', $productId)
            ->where('relation_type', EntityRelation::TYPE_RELATED_TO)
            ->pluck('from_entity_id');

        $query = static::query()
            ->published()
            ->ofType(self::TYPE_CASE_STUDY)
            ->whereIn('id', $caseIds);

        return $locale !== null ? $query->forLocale($locale) : $query;
    }
}