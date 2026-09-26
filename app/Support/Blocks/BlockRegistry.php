<?php

namespace App\Support\Blocks;

use App\Models\Content;
use App\Models\Entity;
use App\Models\Media;
use App\Models\PageBlock;
use App\Support\Catalog;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use App\Support\PublicUrl;

/**
 * 通用 Block 注册表 / 渲染编排（Block Registry）。
 * --------------------------------------------------
 * 单一来源：block 类型从 config/blocks.php 声明式加载；本类负责类型化访问、
 * 槽位允许查询、数据源解析与渲染。控制器 / Blade 不写 `if type==` 分支。
 *
 * 数据源（grid 类 block）单向从正式领域模型 Entity / Content（经 PublicUrl 准入、
 * Catalog 分组）投影，不产生第二事实源；无公开落地页的条目不进入 Landing 网格
 * （Public Render Contract）。
 */
class BlockRegistry
{
    /** @var array<string,BlockType> */
    private static array $types = [];
    private static bool $booted = false;

    /** 从配置加载全部 block 类型（仅一次）。 */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        foreach ((array) config('blocks.types', []) as $key => $cfg) {
            self::$types[$key] = BlockType::fromConfig($key, $cfg);
        }
        self::$booted = true;
    }

    /** 强制重新加载（测试 / 配置变更场景）。 */
    public static function flush(): void
    {
        self::$types = [];
        self::$booted = false;
    }

    /** @return array<string,BlockType> */
    public static function all(): array
    {
        self::boot();

        return self::$types;
    }

    public static function get(string $type): ?BlockType
    {
        self::boot();

        return self::$types[$type] ?? null;
    }

    public static function has(string $type): bool
    {
        return self::get($type) !== null;
    }

    /** 当前模板槽位允许插入的 block 类型（Add block 选择器数据源）。 */
    public static function forSlot(string $templateKey, string $slot): array
    {
        return array_filter(
            self::all(),
            static fn (BlockType $t) => $t->allows($templateKey, $slot)
        );
    }

    /**
     * Admin “Add block” 选择器数据源：在 forSlot 基础上排除 system block。
     * 系统块（entity_*）由当前 Entity 直驱、仅存在于 Detail 固定槽，不可手动添加，
     * 避免管理员在 override Page 中产生第二套 Entity 状态。
     */
    public static function selectableForSlot(string $templateKey, string $slot): array
    {
        return array_filter(
            self::forSlot($templateKey, $slot),
            static fn (BlockType $t) => ! $t->system
        );
    }

    /** 渲染单个 block 为 HTML（含数据源解析）。未注册类型返回空串。 */
    public static function render(BlockContract $block, array $context = []): string
    {
        $type = self::get($block->type);
        if ($type === null) {
            return '';
        }

        $viewData = array_merge($context, [
            'block'         => $block,
            'blk'           => $block,
            'data'          => self::resolveData($type, $block, $context),
            'semanticAttrs' => SectionSemantic::attributes($type, $context),
        ]);

        return view($type->view, $viewData)->render();
    }

    /** 解析数据源型 block 的卡片条目（统一为卡片数组）。 */
    public static function resolveData(BlockType $type, BlockContract $block, array $context = []): array
    {
        if (! $type->dataSource) {
            return [];
        }

        $cfg = $block->cfg();
        $source = $cfg['source'] ?? [];
        $limit = (int) ($cfg['limit'] ?? 0);

        $cards = match ($type->type) {
            'product_grid' => self::productCards($source, $context),
            'service_grid' => self::serviceCards($source, $context),
            'content_grid' => self::contentCards($cfg, $source, $context),
            default => [],
        };

        return $limit > 0 ? array_slice($cards, 0, $limit) : $cards;
    }

    /** 当前语言（兜底默认语言）。 */
    private static function locale(): string
    {
        return LocaleContext::current() ?: LocaleRegistry::default();
    }

    /** 媒体 id → 公开 URL（容错缺失）。 */
    private static function mediaUrl(mixed $id): ?string
    {
        if (! is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        return Media::find((int) $id)?->url();
    }

    /** Product 网格卡片：仅保留有公开落地页（core 等）的产品。 */
    private static function productCards(array $source, array $context = []): array
    {
        $query = Entity::published()->forLocale(self::locale())
            ->ofType(Entity::TYPE_PRODUCT)->orderBy('sort_order');

        $mode = $source['mode'] ?? 'all';
        if ($mode === 'line') {
            $slugs = array_column(Catalog::productsByLine((string) ($source['line'] ?? '')), 'slug');
            $query->whereIn('slug', $slugs);
        } elseif ($mode === 'picked') {
            $query->whereIn('id', array_map('intval', (array) ($source['ids'] ?? [])));
        } elseif ($mode === 'current') {
            // TD-63：当前栏目 / 系列由 Render Context 提供，不在 Blade 判断 id。
            $lineSlug = (string) ($context['lineSlug'] ?? ($context['line']['slug'] ?? ''));
            if ($lineSlug !== '') {
                $query->whereIn('slug', array_column(Catalog::productsByLine($lineSlug), 'slug'));
            }
        } elseif ($mode === 'related') {
            // TD-63：当前 Entity 的相关产品由 Render Context（Catalog 读模型）提供。
            $catalog = is_array($context['catalog'] ?? null) ? $context['catalog'] : null;
            if ($catalog !== null) {
                $slugs = array_column(Catalog::relatedProducts($catalog), 'slug');
                $query->whereIn('slug', $slugs);
            }
        }

        $cards = [];
        foreach ($query->get() as $e) {
            $url = PublicUrl::entity($e);
            if ($url === null) {
                continue; // 无公开落地页不进 Landing 网格
            }
            $meta = is_array($e->metadata) ? $e->metadata : [];
            $cards[] = [
                'url'     => $url,
                'name'    => $e->name,
                'summary' => $e->summary ?: $e->description,
                'image'   => self::mediaUrl($meta['image'] ?? null),
                'icon'    => $meta['icon'] ?? 'package',
            ];
        }

        return $cards;
    }

    /** Service（场景）网格卡片：仅保留有公开页（有场景）的服务。 */
    private static function serviceCards(array $source, array $context = []): array
    {
        $query = Entity::published()->forLocale(self::locale())
            ->ofType(Entity::TYPE_SERVICE)->orderBy('sort_order');

        $mode = $source['mode'] ?? 'all';
        if ($mode === 'picked') {
            $query->whereIn('id', array_map('intval', (array) ($source['ids'] ?? [])));
        } elseif ($mode === 'related') {
            // TD-63：相邻场景由 Render Context（prev / next）提供。
            $slugs = array_values(array_filter([
                $context['prev']['slug'] ?? null,
                $context['next']['slug'] ?? null,
            ]));
            if ($slugs !== []) {
                $query->whereIn('slug', $slugs);
            }
        }

        $cards = [];
        foreach ($query->get() as $e) {
            $url = PublicUrl::entity($e);
            if ($url === null) {
                continue;
            }
            $meta = is_array($e->metadata) ? $e->metadata : [];
            $cards[] = [
                'url'     => $url,
                'name'    => $e->name,
                'summary' => $e->summary ?: $e->description,
                'image'   => self::mediaUrl($meta['image'] ?? null),
                'icon'    => $meta['icon'] ?? 'sliders',
            ];
        }

        return $cards;
    }

    /** Content 网格卡片：栏目最新或手选，当前语言、已发布（自动排除 slot 片段）。 */
    private static function contentCards(array $cfg, array $source, array $context = []): array
    {
        $query = Content::published()->forLocale(self::locale())
            ->orderByDesc('published_at')->orderByDesc('id');

        $categoryId = (int) ($cfg['category_id'] ?? 0);
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }
        $mode = $source['mode'] ?? 'latest';
        if ($mode === 'picked') {
            $query->whereIn('id', array_map('intval', (array) ($source['ids'] ?? [])));
        } elseif ($mode === 'current') {
            // TD-63：当前栏目由 Render Context 提供（Listing 2b）。
            $contextCategory = (int) ($context['category_id'] ?? 0);
            if ($contextCategory > 0) {
                $query->where('category_id', $contextCategory);
            }
        }

        $cards = [];
        foreach ($query->get() as $c) {
            $cards[] = [
                'url'     => PublicUrl::content($c),
                'name'    => $c->title,
                'summary' => $c->summary,
                'image'   => self::mediaUrl($c->cover_id),
                'date'    => $c->published_at,
            ];
        }

        return $cards;
    }
}
