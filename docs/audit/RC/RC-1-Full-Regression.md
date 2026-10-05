RC-1 已正式闭环：

```text
RC-1
Tests       1401
Assertions  7290
Failures    0
Errors      0
Skipped     0
Exit Code   0
Duration    1857.26s
STATUS      ✅ PASS
```

因此现在正式进入 **RC-2 Final Fresh / Upgrade / Rollback**。

### RC-2 必须遵守的边界

```text
工作树：保持 RC-1 通过后的同一状态
产品代码：0 修改
开发库：只读
临时库：全部置于项目目录外
旧 20G-6：仅参考，不复用 PASS 结论
```

### RC-2 三条证据链

**RC2-FRESH**

```text
空数据库
→ 56/56 migrations
→ install
→ Site
→ zh-CN / en
→ Content
→ GEO outputs
```

重点证明新版本从零开始就是完整的。

**RC2-UPGRADE**

```text
上一版本数据
→ 当前 RC migrations
→ 业务数据保留
→ Site × Locale 保留
→ GEO 输出语义保持
```

尤其检查：

```text
contents.locale
contents.translation_group
entities.locale
seo_metas.locale
facts.locale
UNIQUE(site_id, key, locale)
```

**RC2-ROLLBACK**

这是本轮最重要的：

```text
RC schema/data
→ rollback
→ previous schema/data
```

必须再次证明：

```text
locale 不丢
translation_group 不丢
Facts i18n 不丢
contents i18n 不丢
UNIQUE / FK / INDEX 不丢
Site isolation 不丢
Derived output 可重新生成
```

并再次锁死已经修过的：

```text
C-16  CTAS 重建导致约束消失
C-17  错误 index name 导致 rollback 失败
```

### 另外一个 RC-2 新增要求

这次已经不是单纯 `Site × Locale`，所以建议最终构造四象限数据：

```text
A / zh-CN
A / en
B / zh-CN
B / en
```

使用：

> **same slug + different content**

做 canary。

然后在 Fresh / Upgrade / Rollback 三条链路之后都验证四象限仍然成立。

---

### RC-2 PASS 标准

```text
FRESH       ✅
UPGRADE     ✅
ROLLBACK    ✅

Schema      ✅
Data        ✅
i18n        ✅
Multi-site  ✅
GEO         ✅
Cache       ✅
C-16        ✅
C-17        ✅

开发库污染  0
产品代码修改 0
Unexpected 500  0
OPEN        0
VERIFY      0
```

有一个小地方建议在最终 RC-2 文档里修正：你贴出的完整版报告中有多处空的代码块/表格，这是粘贴格式问题，不影响 RC-1 结果，但最终进入 Release Artifact Audit 时要确保 `RC-1-Full-Regression.md` 的实际文件内容完整，不要让最终发布证据出现这种缺失。

**RC-2 现在可以直接开始。**
