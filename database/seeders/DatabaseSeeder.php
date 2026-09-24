<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 业务演示数据（Demo/Example 隔离，P-STEP 02）：Core Runtime 安装
        // （geo:install）不调用本 Seeder；仅演示/开发/测试环境显式装载。
        $this->call(DemoSeeder::class);

        // 默认中性联系表单（Core，P-STEP 18H-2 / D3）：geo:install 独立注入。
        // 必须在 SystemPageSeeder 之前调用，contact 页 form_reference block 才能
        // 解析到默认表单 id（否则 form_id 为 null、/inquiry 桥接找不到表单 404）。
        $this->call(DefaultFormSeeder::class);

        // 固定系统页（Core，与 Demo 无关，P-STEP 18G-2b）：产品 / 场景 / 知识总览、
        // About、工厂、合作、联系的页面身份 / SEO / 模板绑定。geo:install 独立注入；
        // DatabaseSeeder 模拟「install + Demo」完整站点，同样需要（幂等）。
        $this->call(SystemPageSeeder::class);

        // 管理员账号：首次登录后请立即修改密码
        $user = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'     => '管理员',
                'password' => Hash::make('Admin@123456'),
                'is_super_admin' => true,
            ]
        );

        $this->command->info(sprintf(
            '后台账号：%s，初始密码 Admin@123456（仅用于本地演示，登录后请立即修改）',
            $user->email
        ));
    }
}
