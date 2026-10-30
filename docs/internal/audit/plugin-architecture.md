# STEP 06 — Plugin / Extension Architecture

> 四层边界（冻结）：Core（引擎） / Theme（视图） / Plugin（扩展） / Site Data（数据）

## 1. 插件模型（app/Support/Plugins/PluginManager.php）

```
plugins/{slug}/
  plugin.json      清单：name / version / description / provider（ServiceProvider 类名）
  providers/*.php  插件自有 ServiceProvider（未进 composer autoload，按清单路径装载）
```

- **发现**：扫描清单有效的插件目录。
- **启用**：站点设置 `plugins_enabled`（JSON 数组）持久化 + 即时 boot。
- **停用**：持久化移除；新请求进程不再注册（进程内路由生命周期自然结束）。
- **boot**：AppServiceProvider::boot 调用 `register()`（可重入，仅新增生效）；
  复位链清 `enabledMemo` 与 `booted` 标记。
- **容错**：清单无效 / provider 类缺失 → 跳过（视为未启用），不致命。

## 2. 扩展边界契约

| 规则 | 落地 |
|---|---|
| 插件只在自己路由前缀内注册（示例：`/plugins/hello/*`） | 示例插件 ServiceProvider |
| 插件不进入统一分发器 | catch-all `/{path}` 正则排除 `plugins/` 前缀（架构性保留，非点名插件） |
| 插件不修改 Core | Core 代码零插件引用——静态扫描 `test_core_code_has_no_plugin_references` 锁定（app/ 下除 PluginManager 外不得出现任何具体插件标识） |
| 插件不写 Core config/routes 文件 | 契约约定 + Code Review 纪律（无运行时沙箱） |
| 配置边界 | 插件自带 manifest 与 provider，不读写 `plugins_enabled` 以外的 Core 设置 |

## 3. 实测证据（PluginArchitectureTest，6 用例）

1. 发现示例插件清单（hello / provider 类名一致）。
2. 默认未启用：`/plugins/hello/ping` 404，Core 页面 200。
3. 启用 → ping 200（JSON 响应），Core 页面与 sitemap 不受影响。
4. 停用 → 启用态持久化为 false，Core 全部正常。
5. 启用幂等（不重复追加）；未知插件拒绝。
6. Core 源码静态扫描：具体插件标识 0 命中。

本轮调试中修复的关键点：catch-all 分发器曾吞掉 `plugins/...` 路径 →
按边界契约在分发器正则中排除插件命名空间；`booted` 静态标记跨请求残留 →
纳入 boot 复位链。

## 4. 移交

- 插件安装/卸载的文件级生命周期（下载/删除目录）→ P-STEP 08/09 Release 工具链。
- 插件迁移与队列边界：示例插件未含 migration；边界契约允许插件自带
  migration（随其 provider 注册），实际引入时需补充升级链测试。
