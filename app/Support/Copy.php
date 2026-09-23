<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 站点可运营文案取数层（Copy）。
 *
 * 底部 CTA、全局咨询表单、404、页脚 slogan 等「边角话术」此前散落在 config/copy.php
 * 与 Blade 硬编码里，后台无法修改。统一收口到此处：默认值走翻译文件 lang/<locale>/ui.php
 * （随前台语言切换），后台「系统 → 站点设置 → 文案话术」可逐句覆盖，留空即回退默认。
 *
 * 约定：本层只承载话术，不承载业务规则（字段是否必填、归因、校验正则仍在控制器/前端）。
 */
class Copy
{
    private static ?array $memo = null;

    /** 取设置值；空串视为未覆盖，回退默认（默认值已按当前语言解析） */
    private static function val(string $key, string $default): string
    {
        // UI 话术：非站点默认语言（如英文 /en）时，单语言 copy Setting 不跨语言套用，
        // 直接回退当前语言翻译，避免默认语言话术泄漏到其他语言页。v1 文案按默认语言维护。
        if (app()->getLocale() !== \App\Support\Localization\LocaleRegistry::default()) {
            return $default;
        }
        $v = trim((string) (self::s()[$key] ?? ''));

        return $v !== '' ? $v : $default;
    }

    private static function s(): array
    {
        return self::$memo ??= Setting::allCached();
    }

    /** 请求级复位（worker 进程复用，AppServiceProvider 每请求调用） */
    public static function flush(): void
    {
        self::$memo = null;
    }

    /**
     * 底部统一 CTA 文案。$variant='factory' 时使用工厂页变体（全站唯一变体）。
     *
     * @return array{title:string,desc:string,primaryCta:string,secondaryCta:string}
     */
    public static function bcta(?string $variant = null): array
    {
        $out = [
            'title'        => self::val('copy_bcta_title', __('ui.bcta_title')),
            'desc'         => self::val('copy_bcta_desc', __('ui.bcta_desc')),
            'primaryCta'   => self::val('copy_bcta_primary', __('ui.bcta_primary')),
            'secondaryCta' => self::val('copy_bcta_secondary', __('ui.bcta_secondary')),
        ];

        if ($variant === 'factory') {
            $out['primaryCta'] = self::val(
                'copy_bcta_factory_primary',
                __('ui.bcta_factory_primary')
            );
            $out['secondaryCta'] = self::val(
                'copy_bcta_factory_secondary',
                __('ui.bcta_factory_secondary')
            );
        }

        return $out;
    }

    /**
     * 全局咨询表单文案。
     * 客户类型选项支持后台逐行维护（留空回退翻译默认选项）。
     *
     * @return array<string,mixed>
     */
    public static function form(): array
    {
        $optionsRaw = app()->getLocale() === \App\Support\Localization\LocaleRegistry::default()
            ? trim((string) (self::s()['copy_form_type_options'] ?? ''))
            : '';
        if ($optionsRaw !== '') {
            $options = array_values(array_filter(array_map(
                static fn ($line) => trim($line),
                preg_split('/\r\n|\r|\n/', $optionsRaw) ?: []
            ), static fn ($line) => $line !== ''));
        } else {
            $options = [
                __('ui.form_type_opt_product'),
                __('ui.form_type_opt_solution'),
                __('ui.form_type_opt_channel'),
                __('ui.form_type_opt_tech'),
                __('ui.form_type_opt_media'),
                __('ui.form_type_opt_other'),
            ];
        }

        return [
            'fields' => [
                'name' => [
                    'label'       => self::val('copy_form_name_label', __('ui.form_name_label')),
                    'placeholder' => self::val('copy_form_name_placeholder', __('ui.form_name_placeholder')),
                    'error'       => self::val('copy_form_name_error', __('ui.form_name_error')),
                ],
                'phone' => [
                    'label'       => self::val('copy_form_phone_label', __('ui.form_phone_label')),
                    'placeholder' => self::val('copy_form_phone_placeholder', __('ui.form_phone_placeholder')),
                    'error'       => self::val('copy_form_phone_error', __('ui.form_phone_error')),
                    'invalid'     => self::val('copy_form_phone_invalid', __('ui.form_phone_invalid')),
                ],
                'customerType' => [
                    'label'       => self::val('copy_form_type_label', __('ui.form_type_label')),
                    'placeholder' => self::val('copy_form_type_placeholder', __('ui.form_type_placeholder')),
                    'error'       => self::val('copy_form_type_error', __('ui.form_type_error')),
                    'options'     => $options,
                ],
                'note' => [
                    'label'       => self::val('copy_form_note_label', __('ui.form_note_label')),
                    'placeholder' => self::val('copy_form_note_placeholder', __('ui.form_note_placeholder')),
                ],
            ],
            'submit'     => self::val('copy_form_submit', __('ui.form_submit')),
            'submitting' => self::val('copy_form_submitting', __('ui.form_submitting')),
            'privacy'    => self::val('copy_form_privacy', __('ui.form_privacy')),
            'success'    => self::val('copy_form_success', __('ui.form_success')),
            'error'      => self::val('copy_form_error', __('ui.form_error')),
        ];
    }

    /**
     * 404 页面文案。
     *
     * @return array{code:string,title:string,desc:string,primaryCta:string,secondaryCta:string}
     */
    public static function error404(): array
    {
        return [
            'code'         => '404',
            'title'        => self::val('copy_404_title', __('ui.404_title')),
            'desc'         => self::val('copy_404_desc', __('ui.404_desc')),
            'primaryCta'   => self::val('copy_404_primary', __('ui.404_primary')),
            'secondaryCta' => self::val('copy_404_secondary', __('ui.404_secondary')),
        ];
    }

    /** 页脚品牌列 slogan */
    public static function footerSlogan(): string
    {
        return self::val('copy_footer_slogan', __('ui.footer_slogan'));
    }
}
