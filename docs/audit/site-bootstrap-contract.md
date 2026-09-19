# STEP 03 — Site Bootstrap Contract

> 契约：空环境 → `php artisan geo:install` → 可运行的 GEO OS 站点

## 1. 安装流程（app/Console/Commands/GeoInstall.php）

```
1. 环境检查    PHP >= 8.2 / PDO 驱动 / APP_KEY 存在且可加密自检
2. 数据库检查  连接可达
3. 迁移        migrate --force（幂等）
4. 站点创建    default site firstOrCreate + 可选 --site-name / --site-domain
5. 管理员      --admin-email（默认 admin@example.com）/ --admin-password
               （缺省随机生成，仅打印一次）；is_super_admin = true
6. 引导自检    核心表存在 + default site 存在
```

选项：`--site-name` `--site-domain` `--admin-email` `--admin-password`。
默认值全部为系统级通用值；幂等可重复执行。

## 2. 超管授权契约（C2 消解）

```text
旧：SystemAuthorization 按 admin@example.test 邮箱硬编码判定
新：users.is_super_admin 显式标记（新迁移）→ 安装器创建的首个管理员持有
    判定属于用户数据而非代码；任意邮箱皆可由安装器引导为超管
```

- `DatabaseSeeder` 的演示管理员同步持有该标记（测试兼容）。
- 守护测试：同邮箱无标记 → 拒绝跨站（`test_same_email_without_flag_cannot_cross_site`）。
- 安装器/建站迁移文件级扫描：业务默认值 0 命中
  （`test_no_business_defaults_in_install_paths`）。

## 3. 实测证据

### 3.1 测试环境（GeoInstallTest，3 用例）

- 引导后 default site = 'Default Site'（通用），admin@example.com 持超管标记；
- 选项注入幂等：site name/domain 生效、重跑无副作用（sites=1）；
- 安装文件零业务默认值。

### 3.2 真实零环境端到端（CLI，独立零字节 sqlite）

```
零字节 DB → geo:install（--site-name/--domain/--admin-email/--password）
  → exit 0：环境 ok / 连接 ok / 38 个迁移 / 站点 / 管理员 / 自检 ok
→ GET / = 200，<title> / <link rel="canonical"> / <meta name="robots"> 全部输出，
  站点名 "Example Site" 渲染
```

- 无效 APP_KEY（base64url 字符集）→ 安装器 exit 1 并给出明确修复指引
  （加密自检守卫，本次实测中真实拦截了一个坏 key）。
- 演示数据不参与安装：装载 DemoSeeder 才有业务内容（P-STEP 02 边界保持）。

## 4. 移交

- C3（public/ 业务图片资产）→ STEP 05 随 Theme 归属迁移。
- 业务 config 解耦（facts/copy/pages）→ STEP 04。
- Release Artifact 级全新目录安装 → P-STEP 08/10。
