<?php

namespace App\Support\Analytics;

use App\Models\Setting;

/**
 * Analytics 配置解析（Site-scoped，只读）。
 *
 * 事实源是 settings（analytics 组）；本类只做解析 / 呈现，不保存业务事实，
 * 也不直接调用任何第三方平台 API。供：
 *   - 前端注入（forFrontend）：provider / id / consent 策略；
 *   - SecurityHeaders：按启用 provider 受控动态拼 CSP（cspHosts）。
 */
class AnalyticsConfig
{
    public const PROVIDER_GA4 = 'ga4';
    public const PROVIDER_GTM = 'gtm';
    public const PROVIDER_META = 'meta';

    public function consentRequired(): bool
    {
        return Setting::get('analytics_consent_required', '1') === '1';
    }

    public function ga4Id(): string
    {
        return trim((string) Setting::get('analytics_ga4_id', ''));
    }

    public function gtmId(): string
    {
        return trim((string) Setting::get('analytics_gtm_id', ''));
    }

    public function metaId(): string
    {
        return trim((string) Setting::get('analytics_meta_id', ''));
    }

    public function ga4Enabled(): bool
    {
        return Setting::get('analytics_ga4_enabled') === '1' && $this->ga4Id() !== '';
    }

    public function gtmEnabled(): bool
    {
        return Setting::get('analytics_gtm_enabled') === '1' && $this->gtmId() !== '';
    }

    public function metaEnabled(): bool
    {
        return Setting::get('analytics_meta_enabled') === '1' && $this->metaId() !== '';
    }

    /** 已启用且 ID 有效的 provider 列表。 */
    public function enabledProviders(): array
    {
        $list = [];
        if ($this->ga4Enabled()) {
            $list[] = self::PROVIDER_GA4;
        }
        if ($this->gtmEnabled()) {
            $list[] = self::PROVIDER_GTM;
        }
        if ($this->metaEnabled()) {
            $list[] = self::PROVIDER_META;
        }

        return $list;
    }

    public function hasAnyProvider(): bool
    {
        return $this->enabledProviders() !== [];
    }

    /**
     * 给前端的配置（仅 provider + id + consent 策略；不含任何密钥）。
     * 第三方脚本在 consent 通过前不加载。
     */
    public function forFrontend(): array
    {
        return [
            'consentRequired' => $this->consentRequired(),
            'providers' => [
                self::PROVIDER_GA4 => ['enabled' => $this->ga4Enabled(), 'id' => $this->ga4Id()],
                self::PROVIDER_GTM => ['enabled' => $this->gtmEnabled(), 'id' => $this->gtmId()],
                self::PROVIDER_META => ['enabled' => $this->metaEnabled(), 'id' => $this->metaId()],
            ],
        ];
    }

    /**
     * 各 provider 需要的 CSP 域名（script / connect / frame）。
     * 仅启用 provider 进入，默认（全部关闭）不放开任何第三方域。
     */
    public const CSP = [
        self::PROVIDER_GA4 => [
            'script' => ['https://www.googletagmanager.com'],
            'connect' => [
                'https://*.google-analytics.com',
                'https://*.analytics.google.com',
                'https://www.googletagmanager.com',
                'https://*.google.com',
            ],
            'frame' => [],
        ],
        self::PROVIDER_GTM => [
            'script' => ['https://www.googletagmanager.com'],
            'connect' => [
                'https://*.google-analytics.com',
                'https://*.analytics.google.com',
                'https://www.googletagmanager.com',
                'https://*.google.com',
            ],
            'frame' => ['https://www.googletagmanager.com'],
        ],
        self::PROVIDER_META => [
            'script' => ['https://connect.facebook.net'],
            'connect' => ['https://www.facebook.com'],
            'frame' => [],
        ],
    ];

    /** 汇总启用 provider 的某类 CSP 域名（去重）。kind: script | connect | frame。 */
    public function cspHosts(string $kind): array
    {
        $hosts = [];
        foreach ($this->enabledProviders() as $provider) {
            foreach (self::CSP[$provider][$kind] ?? [] as $host) {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }
}
