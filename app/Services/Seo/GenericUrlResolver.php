<?php

namespace App\Services\Seo;

use App\Contracts\UrlResolverInterface;
use App\Support\SiteContext;

/**
 * @deprecated P-STEP 17G / Public Render Contract 起，canonical 统一由
 * {@see \App\Support\PublicUrl} 按真实前台路由裁决。本类硬编码的
 * `https://{domain}/{单数类型}/{slug}` 会产出 /product/、/service/、/article/
 * 等前台并不存在（404）的地址，已不再被 SeoMetaResolver 用于 canonical。
 *
 * 仅保留以满足 UrlResolverInterface 的容器绑定与历史测试，待 #86（Catalog Read
 * Model / Public Render Contract 专门阶段）确认无消费者后整体删除，禁止新代码调用。
 */
class GenericUrlResolver implements UrlResolverInterface
{
    public function generateCanonical(string $type, array $params = []): string
    {
        $site = SiteContext::currentSite();
        $domain = $site?->domain ?? 'localhost';
        $baseUrl = 'https://' . $domain;

        return match($type) {
            'home' => $baseUrl . '/',
            'content' => $baseUrl . '/' . ($params['type'] ?? 'page') . '/' . ($params['slug'] ?? ''),
            'entity' => $baseUrl . '/' . ($params['type'] ?? 'product') . '/' . ($params['slug'] ?? ''),
            default => $baseUrl . '/',
        };
    }
}
