<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * 纠错迁移：清空 v0.6（2026_09_15_000013_v06_ia_theme_blocks）写进 settings 的
 * 「显式外观默认值」。
 *
 * 背景：v0.6 旧首页装修架构把品牌蓝 / 辅色 / 圆角 / 容器等外观默认用
 * updateOrCreate 显式写入 settings。P-STEP 18D 起外观解析改为三层回落：
 *   站点显式定制（用户后台改） > 激活主题 tokens（行业预设 / example） > ThemePalette DEFAULTS。
 * v0.6 写入的默认值被误当成「站点显式定制」，永远压过激活主题（如 example 紫色 /
 * 圆角 16），导致「激活主题、前台视觉不变」。全新 geo:install 的 migrate 阶段同样受影响。
 *
 * 本迁移只把「仍等于 v0.6 默认值」的外观键重置为空（用户真实自定义、不等于默认的保留），
 * 使激活主题 tokens 与 ThemePalette DEFAULTS 能正确回落。历史迁移 v0.6 保持不变。
 */
return new class extends Migration
{
    public function up(): void
    {
        // v0.6 写入的外观键 => 其写入的默认值（小写、归一化形态）
        $reset = [
            'theme_primary'      => ['#2563eb'],
            'theme_primary_dark' => ['#1d4ed8'],
            'theme_accent'       => ['#0e9f6e'],
            'theme_bg'           => ['#ffffff'],
            'theme_surface'      => ['#ffffff'],
            'theme_text'         => ['#1f2937'],
            'theme_text_muted'   => ['#6b7280'],
            'theme_radius'       => ['8'],
            'theme_container'    => ['1200'],
        ];

        foreach (Setting::withoutSiteScope()->whereIn('key', array_keys($reset))->get() as $row) {
            $current = strtolower(trim((string) $row->value));
            if (in_array($current, $reset[$row->key], true)) {
                $row->value = '';
                $row->save();
            }
        }

        Setting::flush();
    }

    public function down(): void
    {
        // 不回滚：恢复 v0.6 显式默认会重新引入本问题；down 留空。
    }
};
