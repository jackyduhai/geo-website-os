<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 站点可运营文案取数层（Copy）。
 *
 * 底部 CTA、全局咨询表单、404、页脚 slogan 等「边角话术」此前散落在 config/copy.php
 * 与 Blade 硬编码里，后台无法修改。统一收口到此处：默认值取 config（自动生成产物），
 * 后台「系统 → 站点设置 → 文案话术」可逐句覆盖，留空即回退默认；零配置时前台与现状完全一致。
 *
 * 约定：本层只承载话术，不承载业务规则（字段是否必填、归因、校验正则仍在控制器/前端）。
 */
class Copy
{
    private static ?array $memo = null;

    /** 取设置值；空串视为未覆盖，回退默认 */
    private static function val(string $key, string $default): string
    {
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
        $base = config('copy.bottomCta', []);
        $out = [
            'title'        => self::val('copy_bcta_title', (string) ($base['title'] ?? '')),
            'desc'         => self::val('copy_bcta_desc', (string) ($base['desc'] ?? '')),
            'primaryCta'   => self::val('copy_bcta_primary', (string) ($base['primaryCta'] ?? '免费获取样品')),
            'secondaryCta' => self::val('copy_bcta_secondary', (string) ($base['secondaryCta'] ?? '获取定制方案')),
        ];

        if ($variant === 'factory') {
            $ov = $base['overrides']['factory'] ?? [];
            $out['primaryCta'] = self::val(
                'copy_bcta_factory_primary',
                (string) ($ov['primaryCta'] ?? $out['primaryCta'])
            );
            $out['secondaryCta'] = self::val(
                'copy_bcta_factory_secondary',
                (string) ($ov['secondaryCta'] ?? $out['secondaryCta'])
            );
        }

        return $out;
    }

    /**
     * 全局咨询表单文案。结构与 config('copy.form') 对齐，
     * 客户类型选项支持后台逐行维护（留空回退默认八类）。
     *
     * @return array<string,mixed>
     */
    public static function form(): array
    {
        $f = config('copy.form', []);
        $ff = $f['fields'] ?? [];

        $optionsRaw = trim((string) (self::s()['copy_form_type_options'] ?? ''));
        if ($optionsRaw !== '') {
            $options = array_values(array_filter(array_map(
                static fn ($line) => trim($line),
                preg_split('/\r\n|\r|\n/', $optionsRaw) ?: []
            ), static fn ($line) => $line !== ''));
        } else {
            $options = array_values((array) ($ff['customerType']['options'] ?? []));
        }

        return [
            'fields' => [
                'name' => [
                    'label'       => self::val('copy_form_name_label', (string) ($ff['name']['label'] ?? '称呼')),
                    'placeholder' => self::val('copy_form_name_placeholder', (string) ($ff['name']['placeholder'] ?? '')),
                    'error'       => self::val('copy_form_name_error', (string) ($ff['name']['error'] ?? '请填写称呼')),
                ],
                'phone' => [
                    'label'       => self::val('copy_form_phone_label', (string) ($ff['phone']['label'] ?? '联系电话')),
                    'placeholder' => self::val('copy_form_phone_placeholder', (string) ($ff['phone']['placeholder'] ?? '')),
                    'error'       => self::val('copy_form_phone_error', (string) ($ff['phone']['error'] ?? '请填写联系电话')),
                    'invalid'     => self::val('copy_form_phone_invalid', '手机号或微信号格式不正确。'),
                ],
                'customerType' => [
                    'label'       => self::val('copy_form_type_label', (string) ($ff['customerType']['label'] ?? '我是哪一类客户')),
                    'placeholder' => self::val('copy_form_type_placeholder', (string) ($ff['customerType']['placeholder'] ?? '请选择')),
                    'error'       => self::val('copy_form_type_error', (string) ($ff['customerType']['error'] ?? '请选择客户类型')),
                    'options'     => $options,
                ],
                'note' => [
                    'label'       => self::val('copy_form_note_label', (string) ($ff['note']['label'] ?? '需求简述（选填）')),
                    'placeholder' => self::val('copy_form_note_placeholder', (string) ($ff['note']['placeholder'] ?? '')),
                ],
            ],
            'submit'     => self::val('copy_form_submit', (string) ($f['submit'] ?? '提交，我要样品')),
            'submitting' => self::val('copy_form_submitting', (string) ($f['submitting'] ?? '提交中…')),
            'privacy'    => self::val('copy_form_privacy', (string) ($f['privacy'] ?? '')),
            'success'    => self::val('copy_form_success', (string) ($f['success'] ?? '已收到，我们会尽快联系你。')),
        ];
    }

    /**
     * 404 页面文案。默认以现网 errors/404.blade.php 文案为准（config 中的同名段落为历史死配置）。
     *
     * @return array{code:string,title:string,desc:string,primaryCta:string,secondaryCta:string}
     */
    public static function error404(): array
    {
        return [
            'code'         => '404',
            'title'        => self::val('copy_404_title', '没有找到这个页面'),
            'desc'         => self::val('copy_404_desc', '链接可能已失效，或地址输入有误。你可以从下面这些入口继续，或直接返回首页。'),
            'primaryCta'   => self::val('copy_404_primary', '返回首页'),
            'secondaryCta' => self::val('copy_404_secondary', '联系我们'),
        ];
    }

    /** 页脚品牌列 slogan */
    public static function footerSlogan(): string
    {
        return self::val(
            'copy_footer_slogan',
            (string) config('copy.footer.brandColumn.slogan', '用真诚心，做好每一份鸡肉')
        );
    }
}
