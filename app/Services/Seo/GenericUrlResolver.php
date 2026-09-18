<?php

namespace App\Services\Seo;

use App\Contracts\UrlResolverInterface;
use App\Support\SiteContext;

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
