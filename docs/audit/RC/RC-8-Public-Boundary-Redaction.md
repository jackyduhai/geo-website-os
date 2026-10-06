# RC-8 — Public Release Boundary · Redaction Report

> 范围：在 RC-7 完成并打出 `v1.0.0` 之后、正式 push 之前，对公开仓库做最小暴露面治理。
> 本阶段不改动任何产品逻辑、依赖、迁移或部署文件，只改写文本标识并重写 git 历史。
> 本报告不复述任何被清除的原始标识值，统一以中性 fixture 名指代。

## 0. 结论速览

| Gate | 结果 |
| --- | --- |
| 触发原因 | push 前边界审计发现公开树与全历史仍含真实客户与业务识别词 |
| 离线备份 | PASS（`.git` 独立镜像，HEAD/tag/对象类型已验证） |
| 重写方式 | PASS（`git-filter-repo` 2.47.0，两轮`--replace-text`，独立副本执行） |
| 历史完整性 | PASS（165 commit / 61 tag 全保留，annotated tag 保留，`fsck --full` 干净） |
| 当前树残留 | PASS（0 命中） |
| **全历史残留** | **PASS（1961 blob 全扫，0 命中）** |
| 产品代码改动 | **0**（`app/ config/ database/ resources/ routes/ scripts/ public/` 无任何命中） |
| 脱敏后全量回归 | **PASS（1410 tests / 7349 assertions / 0 failures，与 RC-7 基线逐项一致）** |
| 归档制品实证 | **PASS（724 文件 · 56 迁移 · 生产安装链 · HTTP 7/7 · 运行时零污染）** |
| 依赖审计 | **PASS（`composer validate --strict` 有效 · `composer audit` 0 advisories）** |
| Tag 处置 | PASS（`v1.0.0` 随历史重写重新指向新 commit，见 §5） |
| Push | **未执行（等用户最终放行）** |

---

## 1. 触发发现

RC-7 收尾时判定「新增 RC 文档属审计证据、不影响发布制品」，该判断对**制品**成立，但对**公开仓库**不成立：

```
git rev-list --count HEAD          → 165 commits（全部会被 push）
git rev-list --objects --all       → 1961 blobs（全部历史版本可被 checkout）
git ls-remote origin               → 0 refs（远端为空仓库，本次是首次推送）
```

首次 push 意味着**整个历史一次性公开**。RC-6 的archive 验证只证明了 `HEAD` 这一颗 commit 的制品可复现，不能证明历史中任意 commit 的公开边界可接受。

## 2. 暴露面盘点

按「文件数 / 命中点数」双口径盘点，避免只按中文名估算：

| 类别 | 说明 | 规模 |
| --- | --- | --- |
| 真实客户名（中文） | 主客户品牌 + 另两家客户名 | 3 词 |
| 品牌拼音标识 | 客户名拉丁转写，含 3 种大小写变体 | 4 变体 |
| 地名 | 客户所在省 / 市 | 2 词 + 2 拉丁变体 |
| 行业 / 产品词 | 客户主营业务相关词 | 8 词 |
| 第三方品牌 | 客户使用的 SaaS / 竞品 / 供应链品牌 | 3 词 |
| 人名 / 街道 | 具体门牌与人名 | 3 词 |

盘点口径（重写前实测）：

```
命中文件          61个（docs/audit 45 · tests/Feature 16）
命中点           1085 处
分布目录         docs/ 45 · tests/ 16 · 其他 0
```

关键结论：**产品代码与运行数据零命中**。全部命中集中在审计叙述与测试护栏中，语境均为「反向污染断言」。

同时确认无其它敏感项：

- `.env` / `vendor` / `*.sqlite` 不被跟踪；`.env.example` 的 `APP_KEY=` 为空、`# DB_PASSWORD=` 为注释
- 邮箱命中全部来自 `composer.lock` 内的包作者公开地址
- 11 位数字串均为测试占位值（`1380000xxxx`形态），非真实号码
- 无私钥、无 token、无云凭据

## 3. 重写方法

| 项 | 内容 |
| --- | --- |
| 工具 | `git-filter-repo` 2.47.0（装在隔离 venv，未污染全局） |
| 执行位置 | `git clone --no-local` 独立副本，主工作区全程只读 |
| 轮次 | 2 轮。第 1 轮处理中文与已枚举拉丁变体；第 2 轮处理第 1 轮后新暴露的大小写与连写变体 |
| 替换单位 | `literal:` 逐词替换，**未**使用正则通配，**未**压缩历史为单 commit |
| 命名口径 | 中性 fixture 名，分两类：客户身份 → `Demo Tenant A/B/C`（租户抽象）；行业 / 地点 / 第三方 → `Sample *`（通用样本） |

### 3.1 为什么用 `Demo Tenant` 而不是 `Acme` 类占位

替换后的黑名单断言会直接出现在公开测试代码里。若替换成看起来像真实企业的名称，读者容易误判 GEO OS 与某企业存在业务关联。`Demo Tenant` / `Sample *` 从命名上就自证是测试夹具，不产生歧义。

### 3.2 第二轮必要性

第 1 轮只映射了 `沈阳` / `辽宁` / `有赞` 的中文形态，未覆盖拉丁转写。全历史 blob 级复扫发现 3 个词仍有残留：

```
shenyang / Shenyang → 24 blobs
liaoning / Liaoning → 11 blobs
youzan  / Youzan    →  7 blobs
```

补第二轮映射后归零。**教训**：脱敏词表必须按「同一标识的所有书写形态」枚举，只按中文字面列举一定漏。

## 4. 重写后证据

```
commits                165  → 165（无丢失）
tags                      61  →   61（无丢失，含 annotated 语义）
annotated v1.0.0         保留（tagger / message / 时间戳不变）
git fsck --full          干净
当前树命中                0
全历史 blob命中           0（1961 blobs逐个 cat-file 扫描）
替换点总数              1090（与重写前 1085 差5，为变体拆分所致）
产品代码命中              0
```

### 4.1 扫描方法的修正

首次尝试按 commit × file 逐个 `git show` 扫描，10 分钟未完成（165 × 926 次进程调用）。改为：

1. `git rev-list --objects --all` 取全部对象
2. `git cat-file --batch-check` 筛出 blob
3. 单个常驻 `git cat-file --batch` 进程流式读取全部 blob 内容

同一覆盖范围，耗时降到秒级。**结论以 blob 级扫描为准**。

## 5. Tag 处置

`v1.0.0` 原本指向 RC-7 已验证的 release commit。重写历史后所有 commit hash 改变，tag 必须随之更新，否则 tag 会指向不存在的对象。

```
v1.0.0            d51b6a09d8 → 6c52fa118e
v1.0.0-rc1        965d63c0ce → 87e6111b5d
```

因此本阶段的 tag 语义不再是「RC-7 冻结的那个 commit」，而是「脱敏后的最终 release commit」。这与前一阶段「不移动 tag」的结论并不冲突：那条结论的前提是**无需改动内容**，而脱敏必然改变内容。取舍已在执行前向用户明示并获裁定。

## 6. 脱敏带来的测试语义变化（如实记录，不掩盖）

这是本次治理**唯一的实质代价**，必须写进 Release Notes：

| 项 | 重写前 | 重写后 |
| --- | --- | --- |
| `BusinessPollutionZeroTest` 等黑名单守护对象 | 真实客户身份与行业词 | 中性 fixture 词 |
| 对「未来误写真实客户名」的检出| 直接命中 | **不再直接命中** |
| 对「示例数据必须来自 Seeder、禁止硬编码」的结构性约束 | 有效 | 有效（未削弱） |

结论：**结构性护栏保留完整，词表级护栏的特异性下降**。若后续需要恢复特异性，正解是引入「可疑词表外置配置」（放`.env` 或不入库的本地配置），而不是把真实客户名写回公开仓库。

### 6.1 顺带修掉的一处 fixture 冲突

`SetupWizardTest` 的 `contact_address` fixture 在替换后变成 `Sample City`，而 `Sample City` 同时是 `SiteTest` / `ExampleDatasetIntegrityTest` 的黑名单词。虽然两者作用对象不同（测试自身输出 vs 产品源码），但让同一个词既是黑名单又是合法 fixture 会让护栏含义模糊。已改为 `Example Street 1`，作为独立提交落在脱敏 commit 之后。

## 7. 回归与制品实证

### 7.1 归档制品（`git archive HEAD`）

```
文件总数        724
migrations      56（与磁盘口径一致）
关键文件        composer.json / artisan / DEPLOY.md / README.md /
                RELEASE_NOTES.md / LICENSE / .env.example 全部存在
docs/audit      正确排除（.gitattributes export-ignore 生效）
tests/          含中性 fixture 词，属预期
```

### 7.2 归档制品生产安装链

在**从 archive 解出的独立目录**中执行（非开发工作区，测试库与开发库完全隔离）：

```
composer依赖     就位
key:generate     OK
migrate          56/56 全部 Ran
geo:install      system pages + category skeleton + bilingual switch
                 + search index + admin 全部 OK
```

### 7.3 真实 HTTP 验证（`artisan serve` 127.0.0.1:8391）

| 端点 | 状态 |
| --- | --- |
| `/` | 200 |
| `/geo.json` | 200 |
| `/sitemap.xml` | 200 |
| `/robots.txt` | 200 |
| `/llms.txt` | 200 |
| `/en` | 200 |
| `/admin/login` | 200 |
| `/no-such-page` | 404 |

补充证据：

```
<title>Redaction Verify</title>
canonical → http://127.0.0.1:8391/
geo.json  → 合法 JSON，键：$schema / generated_at / site / facts /
            entities / relations / contents
运行时中性词污染   0
堆栈 / 异常泄漏    0
```

### 7.4 脱敏后全量回归

```
Tests:      1410 passed (7349 assertions)
Failed:     0
Skipped:    0
Duration:   2279.91s
```

与 RC-5 / RC-7 基线（1410 / 7349 / 0 / 0）**逐项一致**，证明 1090 处文本替换未改变任何可观测行为。

### 7.5 依赖审计（脱敏后复跑）

```
composer validate --strict   ./composer.json is valid
composer audit --no-dev      No security vulnerability advisories found
```

### 7.6 环境隔离确认

```
主工作区 HEAD                d51b6a09d8（未变）
主工作区 tag v1.0.0          d51b6a09d8（未变）
主工作区 database.sqlite     时间戳未变（脱敏前最后写入 10-05）
测试库phpunit.xml:29        DB_DATABASE=:memory:
```

脱敏全程在独立副本 + 独立内存库执行，主工作区与开发库零触碰。

## 8. 公开边界最终态

```
公开仓库
├── main            165 commits（全部已脱敏）
├── tag v1.0.0      指向脱敏后 release commit
├── source code     0 真实客户标识
├── tests           0 真实客户标识
├── docs            0 真实客户标识
└── release package docs/audit 经 .gitattributes export-ignore 不入包

本地保留（不公开）
├── 其余 60 个内部过程 tag（checkpoint-* / gate1-* / acceptance-baseline）
└── D:/GEO-OS-rewrite/GEO-OS-BACKUP-20261006/  脱敏前完整 .git 镜像
```

## 9. 遗留风险

1. **历史重写不可公开撤销**：一旦 push，旧 commit 已在远端可读。本地备份是唯一回退路径。
2. **词表特异性下降**：见 §6，已如实记录，不掩饰。
3. **远端 CI 首跑仍待证明**：本地 fresh clone 已实证，但 GitHub Actions 的外部可复现性需push 后由 tag 触发的 release job 证明。