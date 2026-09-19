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

        // 管理员账号：首次登录后请立即修改密码
        $user = User::updateOrCreate(
            ['email' => 'admin@example.test'],
            [
                'name'     => '管理员',
                'password' => Hash::make('Example@2026'),
            ]
        );

        $this->command->info(sprintf(
            '后台账号：%s，初始密码 Example@2026（登录后请立即修改）',
            $user->email
        ));
    }
}
