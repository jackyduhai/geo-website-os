<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Demo / 业务示例数据编排器（P-STEP 02：Demo 与 Core 隔离）。
 *
 * 这里的全部数据（事实库 / 栏目结构 / 站点设置 / 演示内容）都是
 * 「某个具体业务站点」的示例数据，不属于 GEO Website OS Core Runtime。
 *
 * Core Runtime 的启动路径是 geo:install / Migration（不含本 Seeder）；
 * 本 Seeder 只在显式调用（php artisan db:seed --class=DemoSeeder，或
 * DatabaseSeeder 兼容编排）时装载，用于演示 / 开发 / 测试环境。
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FactSeeder::class,        // 事实库（单一事实源，历史文件源）
            StructureSeeder::class,   // 栏目树 + 分组 + 首页区块
            SettingSeeder::class,     // 站点设置与主题变量
            ContentSeeder::class,     // 首批骨架内容（全部过 ContentGate，幂等可重跑）
            CatalogSeeder::class,     // Example 目录 → 站点隔离 Entity / EntityRelation（D.2）
        ]);

        // 组织结构化扩展：业务补充属性写入 sites.metadata['organization'] 通用扩展
        // （Core SchemaBuilder 只消费通用扩展，不直读业务事实库）。
        $company = \App\Support\Facts::company();
        \App\Models\Site::where('slug', \App\Models\Site::DEFAULT_SLUG)->update([
            'name'        => $company['name'] ?? 'Example Site',
            'description' => '示例制造有限公司专注工业涂料、结构胶粘剂与功能性助剂的研发与生产，提供从选型、打样到稳定供货的产品与定制化解决方案。',
            'metadata' => json_encode([
                'organization' => [
                    'legal_name'    => $company['name'] ?? '',
                    'telephone'     => $company['phone_tel'] ?? ($company['phone'] ?? ''),
                    'founding_date' => $company['founded'] ?? '',
                    'address'       => [
                        'street'   => $company['address']['street'] ?? '',
                        'locality' => $company['address']['city'] ?? '',
                        'region'   => $company['address']['province'] ?? '',
                        'country'  => $company['address']['country'] ?? 'CN',
                    ],
                    'area_served'   => collect(\App\Support\Facts::salesRegions())
                        ->map(fn ($r) => $r . '地区')->all(),
                    'knows_about'   => collect(\App\Support\Facts::productLines())
                        ->pluck('name')->take(4)->filter()->values()
                        ->push('定制化解决方案')->all(),
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
