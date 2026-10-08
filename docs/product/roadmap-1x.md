# GEO Web OS 1.x Product Roadmap

- **状态**：PROPOSED（2026-10-08，基于 v1.0.0 = `504f71d` 实测盘点）
- **基线**：`v1.0.0` = 504f71d（immutable / released）· `main` = `f4d06a1`（post-release development baseline）
- **前置文档**：`docs/product/engineering-acceptance-principles.md`（四条不等式，本文所有验收标准均引用它）
- **既有蓝图**：`v1.1-product-maturity-gap-matrix.md`（2026-09-27，基于 `9f0f4c1`）
  —— 本文**不是**替代它，而是**用 v1.0.0 实测结果复核它**，并给出当前真实起点

---

## 一、核心定位（决定所有取舍）

> GEO Web OS 不是传统建站系统，
> 而是**面向 AI 时代的企业官网 / 企业公开知识入口**。

这条定位不是口号，它决定了产品该做什么、不该做什么。

```text
传统建站系统的价值：把页面做出来
GEO Web OS 的价值：让企业事实被 AI 正确读到、引用、信任
```

因此后续版本的方向应当是：

```text
企业信息建模 → 内容生产 → GEO 持续优化 → AI 可发现性 → 数据质量 → 运营闭环
```

**而不是**继续往 CMS 里堆普通后台功能。

### 已经建成的底座（v1.0.0 实测确认）

```text
Multi-site · Multi-language · CMS · SEO · GEO · GEOFlow
Template · Theme · Live Preview · Fresh Install · Upgrade · Rollback
AI Fact Contract（分层SoT + 跨出口一致性测试）
```

基础设施已成立，不需要再证明「能不能做出来」。
下一阶段的问题从「**能不能用**」转向「**企业为什么愿意长期用**」。

---

## 二、用 v1.0.0 实测复核既有v1.1 蓝图

`v1.1-product-maturity-gap-matrix.md` 列了 7 项 P0。我逐项核实了代码库，**结果与蓝图当时的判断基本一致，但有一处重要修正**：

| P0 项 | 蓝图判断（2026-09-27） | v1.0.0 实测（2026-10-08） | 判据 |
|---|---|---|---|
| Content Lifecycle | 🟡 需完整产品化 | 🟡 **确认仍缺** | 无 `trashed_at` / softdelete |
| Scheduled Publish | 🔴 缺失 | 🔴 **确认仍缺** | 无 `publish_at` / `unpublish_at` 迁移 |
| Trash / Recycle Bin | 🔴 缺失 | 🔴 **确认仍缺** | 无回收站相关字段 |
| Page Manager | 🟡 缺运营层 | ✅ **已具备** | `admin/pages/` 有 index/form/composer |
| Media Library Lite | 🟡 需资产中心 | 🟡 **确认仍缺** | 仅 `admin/display/media.blade.php`，无 Library 结构 |
| Inquiry Center | 🟡 缺运营闭环 | 🟡 **确认仍缺** | 仅 `inquiries/index.blade.php` |
| Attribution | 🟡 需完整 | ✅ **数据层已有** | `add_attribution_to_inquiries` 迁移含 utm_* / landing_url / referer |

**修正结论**：P0 中的 Page Manager 与 Attribution 已在 v1.0.0 落地，
实际待做项从 7 项收敛为 **5 项**。这是既有蓝图无法预见的（它写于 v1.0 之前）。

---

## 三、当前系统的真实强项与短板

### 强项（GEO Native，别人没有的）

```text
AI 可发现性出口  6个：sitemap.xml / llms.txt / feed.xml / geo.json / robots.txt
GEO 服务层        8 个：GeoGraphBuilder / LlmsBuilder / LlmsSanitizer / SchemaBuilder
                        / SitemapBuilder / GeoHealthService / EntityCoverageService / FactLabels
事实契约          分层 SoT + GeoFactConsistencyTest（跨出口 / 跨语言 / 跨站防泄漏）
实体图谱          Entity + EntityRelation + Registry
模板生态          Template Package（声明式）+ Theme（视觉层）+ Live Preview + 回收
```

**36 张表 / 56 个迁移 / 1476 Feature 测试**，底座扎实。

### 短板（企业长期运营的阻碍）

| 维度 | 缺什么 | 企业会怎么抱怨 |
|---|---|---|
| 内容生命周期 | 无定时发布、无回收站 | 「误删就没了」「想半夜发布做不到」 |
| 运营后台 | Media / Inquiry 都只有单页 | 「素材在哪？」「询盘怎么跟进？」 |
| 数据闭环 | 无 Analytics Dashboard | 「做了半年，涨了多少询盘？」 |
| AI 生产 | 无 Content Engine | 「AI 写出来的东西不敢发」 |

---

## 四、1.x 三阶段路线

### 阶段一：Content Operations（让企业敢用）

**目标**：内容资产不再会误丢、能按计划发布。

| 优先级 | 能力 | 为什么排这里 |
|---|---|---|
| **P0** | Publication Lifecycle Contract | 统一 Entity / Content / Page / Block 的生命周期，**不给 Content 单独造** |
| **P0** | Scheduled Publish | `publish_at` / `unpublish_at`；**PublicIndex / Sitemap / Schema / GEO / Search 必须同步跟随** |
| **P0** | Trash / Restore / Purge | Delete → Trash → Restore → Draft；Purge 时联动 Media / Relation / GEOGraph 清理 |
| **P1** | Media Library Lite | 核心是 **Usage Graph**（这张图正在被哪些实体 / 页面引用），比传统素材库更贴GEO |
| **P1** | Inquiry Center + Attribution 运营层 | 数据层已有，补运营闭环即可 |
| **P2** | RBAC（Owner / Admin / Editor / SEO / Content） | 只解决：谁能看 / 谁能改 / 谁能发布 / 谁能管理 |

> **这一阶段的关键判断**：Scheduled Publish 不是「加个定时器」。
> 在 GEO Native 架构里，「发布」改变的是**整个机器可读知识集合**，
> 不是只改一个页面的状态。这是与传统 CMS 的根本分野。

### 阶段二：GEO Operations（让企业提效）

**目标**：从「AI 能读到」升级为「AI 读到的是高质量、可信任的内容」。

| 优先级 | 能力 | 判据 |
|---|---|---|
| **P1** | GEO Content Engine | `Entity + Facts + Evidence + Question + Intent → Draft → Health → Coverage → Human Review → Publish`。**AI Content = GEO Knowledge Production**，不是 AI 写文章 |
| **P1** | Analytics Dashboard | 四维度：Traffic / Content / **GEO**（AI-readable pages、Entity coverage）/ Conversion |
| **P1** | Translation Workflow | Source → Translate → Review → Publish；源语言改动后自动标记译文 Outdated |
| **P1** | Release Evidence（Upgrade / Backup / Rollback E2E） | 从「有升级系统」变成「升级系统已被证明可靠」 |
| **P2** | FAQ Semantic Content | FAQ → Entity → Schema → GEO → Search，直接进实体图谱，不做 FAQ Table |
| **P2** | Storage Adapter | Local / S3 / OSS / COS / Custom，统一走 StorageContract |

> **这一阶段是 GEO Web OS 与所有 CMS 的分水岭**。
> 传统 AI 写手产出的是「文章」；这里产出的是**带证据、带覆盖度、可审计的知识**。
> 判据：每条 AI 产出必须能回答「这个结论的依据在哪」。

### 阶段三：Platform Operations（让企业规模化）

| 优先级 | 能力 |
|---|---|
| **P2** | Template Center增强（Compatibility 版本契约 / 升级） |
| **P2** | Visual Editing × Composer融合（结构化 Canvas，不退回自由 Canvas） |
| **P3** | Admin i18n |
| **P3** | Template Marketplace / Plugin SDK |

---

## 五、明确不做的事（保持纪律）

```text
❌ 为了「功能数量」堆功能       —— 判据是运营闭环，不是功能计数
❌ 无限级栏目树                 —— Category + Entity Graph 已能表达更丰富关系
❌ 传统友情链接Table            —— 未来用 ExternalReference
❌ 传统富文本中心（UEditor类）  —— 继续以 Blocks / Sections / Semantic Data 为基础
❌「AI 写文章」作为产品         —— AI 产物必须过 Evidence → Review 才能发布
❌ 传统 SEO Score               —— GEO Health 已有更真实的判据
❌ 为预览丰满度改模板包         —— 见第六节 Fidelity 原则
```

---

## 六、Template Preview Fidelity（已升格为工程原则）

RC-11 已确立并固化为契约测试：

> **预览站必须忠实反映模板包定义的 recipe，
> 不得为了视觉效果自行增加模板未声明的区块。**

当前状态：`commerce-pro` = recipe 6 / rendered 6，是**忠实预览**，
不是「预览不完整」。

`logo_cloud.items` 为空、`sys_*` 未定义，都属于**模板包内容**，
若将来要补，必须作为独立的**模板内容设计变更**提交，
且先回答一个问题：

> **这个模板的正式设计，到底应不应该包含这些区块？**

而不是「让预览看起来更丰满」。

---

## 七、下一正式版本的发布闭环（顺带解决 P2）

RC-11 发现：`v1.0.0` 的 `release` job 因`if: startsWith(refs/tags/v)`
在先推 main 后单独推 tag 的流程下从未执行（`conclusion=skipped`，远端无 Release 资产）。

**处置：不动 v1.0.0。** 下一正式版本按正确顺序自然闭环：

```text
下一正式版本
  ↓
先推 main（含全部 commit）
  ↓
创建正式 version tag
  ↓
tag-triggered workflow 触发
  ↓
test（PHP 8.4 全量）
  ↓
release（zip + manifest + SHA-256 + artifact）
  ↓
P2 自然消失，不污染 v1.0.0
```

---

## 八、验收标准（引用四条不等式）

本 Roadmap 每个能力交付时，必须按
`docs/product/engineering-acceptance-principles.md` 验收：

```text
□ Scheduled Publish：测的是「到点真的发布」，不是「publish_at 字段能存」
□ Trash：测的是「恢复后 Relation / GEOGraph 无残留」，不是「软删除标记存在」
□ Content Engine：测的是「AI 产出能追溯依据」，不是「调用了大模型」
□ Analytics：测的是「数字和真实数据对得上」，不是「页面能打开」
□ 每条新测试都有变异验证
□ 每条视觉相关读元素计算样式
```

### 判据示例（写在前面，防止后面自我宽容）

```php
// ✗ 控制流断言
$this->assertNotNull($content);

// ✓ 结果语义断言——错了就知道哪条链路断了
$this->assertSame(
    $expectedPublishedAt,
    $this->resolvePublicVisibility($content),
    '到点后前台必须可见，且 sitemap / llms / geo.json 同步可见'
);
```

---

## 九、版本号建议

| 版本 | 内容 | 时机 |
|---|---|---|
| `v1.1.0` | 阶段一 Content Operations（Lifecycle / Scheduled / Trash）+ Media / Inquiry 运营层 | 阶段一完成 |
| `v1.2.0` | 阶段二 GEO Operations（Content Engine / Analytics / Translation Workflow） | 阶段二完成 |
| `v1.3.0` | 阶段三 Platform Operations | 视生态进展 |

**不提前抢定**。每个版本打 tag 前按第七节走完整闭环，
用实际能力范围决定是 hotfix 还是 minor。

---

## 十、与既有文档的关系

```text
v1.1-product-maturity-gap-matrix.md   2026-09-27  蓝图（写于 v1.0 之前）
  ↓ 用 v1.0.0 实测复核
engineering-acceptance-principles.md  2026-10-08  验收原则（四条不等式）
  ↓ 约束
本 Roadmap                       2026-10-08  1.x 路线（真实起点）
```

纪律不变：

```text
新增能力 → 独立 Discovery → 架构影响评估
        → 必要时 Architecture Change Review → Implementation → Gate
```

不得因为「成熟 CMS 有某功能」或「Roadmap 写了某能力」就绕过架构评估。
