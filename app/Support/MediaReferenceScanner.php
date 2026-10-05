<?php

namespace App\Support;

use App\Models\Banner;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Entity;
use App\Models\Media;
use App\Models\SeoMeta;
use App\Models\Setting;

/**
 * 媒体引用反查守卫（Discovery §3.4 七路扫描）。
 *
 * media 表无 DB 外键约束，封面 / OG / Banner / 实体 metadata / 正文内联图 /
 * 页面 SEO OG 图 / 设置项图片（Logo / 默认 OG / 二维码）均以软引用指向 media.id
 * 或 /storage/{path} 字符串。物理删文件之前必须反查这些引用点，否则前台直接
 * 破图、OG 图 404。
 *
 * 七路（全部在当前站点上下文内查询，跨站引用天然隔离）：
 *   1. Content.cover_id        FK 直查
 *   2. Content.og_image_id     FK 直查
 *   3. Banner.image_id         FK 直查
 *   4. Entity.metadata JSON-int（media_id / og_image，whereJsonContains）
 *   5. Content.body            path-string LIKE（/storage/{path}）
 *   6. SeoMeta.og_image_path   精确匹配 + Entity.metadata->image LIKE 兜底
 *   7. Settings 图片字段        geo_org_logo / seo_og_image / contact_wechat_qr
 *      （Media Picker 存完整 URL，含 /storage/{path}）
 * 附查：ContentRevision 历史快照只记 warning，不阻断删除
 * （历史正文可被回滚复活图片引用，删文件本身仍放行，但需提示运营）。
 *
 * 返回每条：['model' => 类名, 'id' => 主键, 'field' => 列名, 'label' => 人类可读描述]；
 * ContentRevision 告警行额外带 'warning' => true。
 */
class MediaReferenceScanner
{
    /**
     * 反查某媒体被哪些业务对象引用。
     *
     * @return array<int, array{model: class-string, id: int|string, field: string, label: string, warning?: bool}>
     */
    public static function for(Media $media): array
    {
        $refs = [];
        $path = ltrim((string) $media->path, '/');
        $storageUrl = '/storage/' . $path;

        // 路1：文章封面
        Content::where('cover_id', $media->id)
            ->get(['id', 'title'])
            ->each(function (Content $c) use (&$refs) {
                $refs[] = [
                    'model' => Content::class,
                    'id'    => $c->id,
                    'field' => 'cover_id',
                    'label' => '文章《' . ($c->title ?: ("未命名#{$c->id}")) . '》封面图',
                ];
            });

        // 路2：文章 OG 分享图
        Content::where('og_image_id', $media->id)
            ->get(['id', 'title'])
            ->each(function (Content $c) use (&$refs) {
                $refs[] = [
                    'model' => Content::class,
                    'id'    => $c->id,
                    'field' => 'og_image_id',
                    'label' => '文章《' . ($c->title ?: ("未命名#{$c->id}")) . '》OG 分享图',
                ];
            });

        // 路3：Banner 轮播图
        Banner::where('image_id', $media->id)
            ->get(['id', 'position'])
            ->each(function (Banner $b) use (&$refs) {
                $refs[] = [
                    'model' => Banner::class,
                    'id'    => $b->id,
                    'field' => 'image_id',
                    'label' => 'Banner 轮播位「' . ($b->position ?: "未命名#{$b->id}") . '」图片',
                ];
            });

        // 路4：Entity metadata JSON-int（media_id 主图 / og_image OG 图）
        Entity::whereJsonContains('metadata->media_id', $media->id)
            ->orWhereJsonContains('metadata->og_image', $media->id)
            ->get(['id', 'name', 'metadata'])
            ->each(function (Entity $e) use (&$refs, $media) {
                $meta = $e->metadata ?? [];
                $name = $e->name ?: ("未命名#{$e->id}");
                if (($meta['media_id'] ?? null) === $media->id) {
                    $refs[] = [
                        'model' => Entity::class,
                        'id'    => $e->id,
                        'field' => 'metadata->media_id',
                        'label' => '实体《' . $name . '》主图',
                    ];
                }
                if (($meta['og_image'] ?? null) === $media->id) {
                    $refs[] = [
                        'model' => Entity::class,
                        'id'    => $e->id,
                        'field' => 'metadata->og_image',
                        'label' => '实体《' . $name . '》OG 图',
                    ];
                }
            });

        // 路5：正文 Markdown 内联图（编辑器插入的 /storage/... 相对地址）
        Content::where('body', 'like', '%' . $storageUrl . '%')
            ->get(['id', 'title'])
            ->each(function (Content $c) use (&$refs) {
                $refs[] = [
                    'model' => Content::class,
                    'id'    => $c->id,
                    'field' => 'body',
                    'label' => '文章《' . ($c->title ?: ("未命名#{$c->id}")) . '》正文内联图',
                ];
            });

        // 路6a：页面级 SEO OG 图（精确 path 匹配）
        SeoMeta::where('og_image_path', $storageUrl)
            ->get(['id'])
            ->each(function (SeoMeta $s) use (&$refs) {
                $refs[] = [
                    'model' => SeoMeta::class,
                    'id'    => $s->id,
                    'field' => 'og_image_path',
                    'label' => '页面级SEO OG图（#' . $s->id . '）',
                ];
            });

        // 路6b：Entity.metadata->image 兜底（存的是 /storage/... 或外链相对路径字符串）
        Entity::where('metadata->image', 'like', '%/' . $path . '%')
            ->get(['id', 'name'])
            ->each(function (Entity $e) use (&$refs) {
                $refs[] = [
                    'model' => Entity::class,
                    'id'    => $e->id,
                    'field' => 'metadata->image',
                    'label' => '实体《' . ($e->name ?: ("未命名#{$e->id}")) . '》配图',
                ];
            });

        // 路7：Settings 图片字段（站点 Logo / 默认 OG / 微信二维码）。
        // Media Picker 选择后存的是完整 URL（含 /storage/{path}），删除前必须拦截。
        $settingImageKeys = [
            'geo_org_logo'      => '站点 Logo',
            'seo_og_image'      => '默认社交分享图',
            'contact_wechat_qr' => '微信二维码',
        ];
        foreach ($settingImageKeys as $sKey => $sLabel) {
            $val = Setting::get($sKey);
            if (is_string($val) && $val !== '' && str_contains($val, $storageUrl)) {
                $refs[] = [
                    'model' => Setting::class,
                    'id'    => $sKey,
                    'field' => $sKey,
                    'label' => '设置项「' . $sLabel . '」',
                ];
            }
        }

        // 附查：ContentRevision 历史快照——只告警不阻断。
        // 快照 body 里若引用本图，删除文件后回滚历史版本会复活一张破图；
        // 但历史快照本身不展示给访客，因此放行物理删除，仅提示运营。
        // snapshot 是 JSON 文本，json_encode 会把 '/' 转义成 '\/'，无法直接 LIKE
        // '/storage/...'；先按无斜杠的文件名粗筛候选行，再对解码后的 body 做精确子串判定。
        ContentRevision::where('snapshot', 'like', '%' . basename($path) . '%')
            ->get(['id', 'content_id', 'snapshot'])
            ->each(function (ContentRevision $r) use (&$refs, $storageUrl) {
                $body = (string) ($r->snapshot['body'] ?? '');
                if (! str_contains($body, $storageUrl)) {
                    return;
                }
                $refs[] = [
                    'model'   => ContentRevision::class,
                    'id'      => $r->id,
                    'field'   => 'snapshot->body',
                    'label'   => '内容#.' . $r->content_id . ' 历史版本快照（#' . $r->id . '）引用此图，回滚可能复活',
                    'warning' => true,
                ];
            });

        return $refs;
    }

    /** 是否存在阻断性引用（warning 级历史快照不计入）。 */
    public static function hasReferences(Media $media): bool
    {
        foreach (self::for($media) as $ref) {
            if (empty($ref['warning'])) {
                return true;
            }
        }

        return false;
    }

    /** 仅返回 warning 级引用（历史快照），不阻断删除。 */
    public static function warnings(Media $media): array
    {
        return array_values(array_filter(
            self::for($media),
            fn (array $ref) => ! empty($ref['warning'])
        ));
    }
}
