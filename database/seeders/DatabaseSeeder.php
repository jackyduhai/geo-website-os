<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FactSeeder::class,        // 事实库（单一事实源）
            StructureSeeder::class,   // 栏目树 + 分组 + 首页区块
            SettingSeeder::class,     // 站点设置与主题变量
            ContentSeeder::class,     // 首批骨架内容（全部过 ContentGate，幂等可重跑）
        ]);

        // 管理员账号：首次登录后请立即修改密码
        $user = User::updateOrCreate(
            ['email' => 'admin@example.test'],
            [
                'name'     => '管理员',
                'password' => Hash::make('Example@2026'),
            ]
        );

        // 组织结构化扩展（STEP 05）：SchemaBuilder 不再直读业务事实库，
        // 组织补充属性经通用扩展 sites.metadata['organization'] 提供。
        // 业务值只存在于 Seeder / 后台，Core 层零业务硬编码。
        $company = \App\Support\Facts::company();
        \App\Models\Site::where('slug', \App\Models\Site::DEFAULT_SLUG)->update([
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
                    'knows_about'   => [
                        '中式Sample SnackSample Marinade', '鸡架Sample Marinade', 'Sample Breading撒料', '调理鸡肉制品', 'OEM/ODM 代工',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->command->info(sprintf(
            '后台账号：%s，初始密码 Example@2026（登录后请立即修改）',
            $user->email
        ));
    }
}
