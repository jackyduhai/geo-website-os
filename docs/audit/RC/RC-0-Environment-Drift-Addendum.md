# RC-0 · Environment Drift Addendum

> **本文件不改写历史快照**，只记录 RC-0 冻结之后发生的环境漂移。
> `RC-0-Freeze-Snapshot.md` 保持其原始取值不变（immutable）。

---

## 1. 漂移事实

| 项 | RC-0 快照（历史，不可变） | Current | 差异 |
|---|---:|---:|:--:|
| 开发库 `migrations` 行数 | **54** | **56** | +2 |
| 开发库 migration lag | **2** | **0** | −2 |

**漂移时间**：2026-10-05 07:27（开发库文件 mtime）
**发现时间**：2026-10-05 RC-5 收尾核验时
**发现方式**：只读 `SELECT count(*) FROM migrations` 与快照值比对不符 → 追因

---

## 2. 新增的两条迁移

```text
#55  batch=2  2026_10_02_000001_add_external_id_unique_to_contents
#56  batch=2  2026_10_03_000001_add_locale_to_facts
```

`MAX_BATCH` 由 1 变为 2 —— 说明这是**第二次** `artisan migrate`（首次为 batch=1）。

---

## 3. 归因

**ENV-002 · accidental migration**

同一条 shell 命令串中，独立库建库与探针执行共用一个 shell；
其中一次 `php artisan migrate` 调用**漏带 `DB_DATABASE`**：

```text
touch /d/Temp/rc5_min2.sqlite \
  && DB_CONNECTION=sqlite DB_DATABASE="D:/Temp/rc5_min2.sqlite" php artisan migrate --force
```

`DB_DATABASE` 缺失时，Laravel 回落到 `.env` 的
`DB_DATABASE=D:\GEO-OS-rewrite\geo-website-os\database\database.sqlite`，
于是迁移被施加到**开发库**。

**与 C-18 误判同源**：跨进程 / 跨命令改库路径时，库标识必须显式且**逐条**传递。
该纪律已在探针侧（RC-5 §1.5）记录，但 shell 串里漏了一次。

---

## 4. 影响评估

```text
Data loss           : 0
Product impact      : 0
Schema correctness  : OK（两个迁移均已正确应用）
```

实证：

| 检查 | 结果 |
|---|---|
| `sites` / `contents` / `facts` / `settings` / `categories` 行数 | 1 / 7 / 23 / 83 / 2（数据完整） |
| `facts.locale` 列 | `YES`（迁移 #56 产物） |
| `contents` 唯一索引 | `sqlite_autoindex_contents_3 = (site_id, external_id)`（迁移 #55 产物） |

两个迁移均为**增量且幂等**（加列 / 加唯一索引），不涉及数据删除或重写，
故**无需回滚**。

**对 RC-1~RC-5 结论的影响：无**

- PHPUnit 使用 `DB_DATABASE=:memory:`，与开发库无关；
- RC-2 ~ RC-5 的全部取证走项目外独立库（`D:/Temp/`）。

---

## 5. 新增纪律

1. 每一条 `php artisan` 调用都必须**显式带 `DB_DATABASE`**，不得依赖 `.env` 回落。
   批量命令串中每一段都要单独确认。
2. 核验开发库状态后**必须把值写入快照**；若与快照不符，即说明期间发生写入，
   必须追因并出具Addendum，而不是就地改写历史快照。
3. 开发库只读核验只用 `SELECT count(*)` 一类读操作，不触发迁移。

---

## 6. 分类

```text
ID       ENV-002
Class    Environment / Test Harness
入台账    否 —— 不是产品缺陷，不进 C 系列 Finding Ledger
```

与 `ENV-001`（Laravel file-cache 测试残留）同类：
都是测试环境问题，都不阻塞 v1.0.0。
