<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

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

        $this->command->info(sprintf(
            '后台账号：%s，初始密码 Example@2026（登录后请立即修改）',
            $user->email
        ));
    }
}
