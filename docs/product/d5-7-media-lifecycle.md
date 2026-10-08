# D5-7 裁决建议：Media Lifecycle（媒体生命周期）

- **状态**：**PROPOSED（待裁定）**（2026-10-08）· 领域裁决，非实现设计
- **上游**：D1（生命周期域模型）· D4（P6 禁 CASCADE）· D5-2 Entity Lifecycle
  · D5-4 Semantic Reference · D5-5 Fact Ownership · PKS Boundary
  · D5-1 Content Trash · D5-6 Page Lifecycle
- **章程**：`docs/product/stage1-discovery-charter.md`
- **硬约束**：本裁决**不重定义**已冻结的 PKS / Entity / Reference / Fact / Content / Page 规则。
- **纪律**：不改产品代码、不做 UI、只定义媒体生命周期契约。

---

## 零、取证：Media 的结构与引用

### 0.1 Media 表结构

```text
media 列：id, site_id, disk(默认 public), path, original_name,
          mime, size, width, height, alt, title, uploaded_by, timestamps
无 SoftDeletes（硬删）；无 DB 外键约束（被引用方无 FK）。
模型：Media::url() 解析 /storage/{path}，外链 http 原样返回。
```

### 0.2 媒体引用点（七路，无 DB FK，混合 ID / 字符串）

| 引用点 | 类型 |
|---|---|
| Content.cover_id | 整数 ID |
| Content.og_image_id | 整数 ID |
| Banner.image_id | 整数 ID |
| Entity.metadata（media_id / og_image） | JSON 整数 |
| Content.body（正文内联图） | /storage/{path} 字符串 |
| SeoMeta.og_image_path | /storage/{path} 字符串 |
| Settings（Logo / 默认 OG / 二维码） | 完整 URL 字符串 |
| 附查：ContentRevision 历史快照 | 仅告警，不阻断 |

### 0.3 MediaReferenceScanner（引用反查守卫）

```text
Media::删除前 → MediaReferenceScanner::for() 七路扫描
  阻断性引用（非 warning）→ 拒绝物理删除 + 审计 media.delete_blocked
  无引用 → 删文件 + 删记录
ContentRevision 历史快照 → warning（回滚可能复活破图），不阻断
```

### 0.4 MediaController::destroy（探针实测）

```text
$refs = MediaReferenceScanner::for($media)
有阻断引用 → 拒绝：文件 + 记录都保留，审计 delete_blocked
无引用 →
  Storage::disk($media->disk)->delete($media->path)   // 删文件
  $media->delete()                                   // 删记录
```

### 0.5 文件本体与 DB 记录

```text
上传：$file->store → Laravel 随机 hash 文件名（不冲突、不去重）
  → 同一文件重复上传 = 独立记录 + 独立文件
优化：ImageOptimizer 限宽/压缩（best-effort，不中断上传）
  → 为 JPEG/PNG 生成同目录 .webp 兄弟文件（progressive enhancement）
删除：仅删原始 path（不删 .webp 兄弟）
```

### 0.6 引用缺失时的渲染（探针实测）

```text
BlockRegistry：Media::find($id)?->url()  // 缺失 → null（不报错）
EntityRenderContext：Media::find($id)?->url() ?? '#'
OG 图 fallback 链：SeoMeta → Content.og_image_id → Content.cover_id → Site logo
```

### 0.7 外部存储 / 磁盘

```text
config/filesystems：public（local）在用；s3 已定义但未使用。
Media 以 disk 字段声明存储位置；path 字符串引用跨磁盘时反查更复杂。
```

---

## 一、Media 的领域定位：资产对象

```text
Media 既不是知识对象（不承载业务事实、无知识权威性），
也不是纯基础设施（它是被管理、可引用、带元数据的对象）。
定位：资产对象（Asset）。
  = 二进制文件 + DB 记录（path / mime / size / 尺寸 / alt）
文件本体与 DB 记录设计为同一生命周期（同建、同删）。
```

---

## 二、生命周期：无软删 + 引用保护删除

```text
Media 无 SoftDeletes / 无回收站：删除即终态。
但删除是「引用保护」的：
  被引用媒体不可删（七路扫描拒绝）；
  仅未引用媒体可删，且文件 + 记录一起删。
引用方（Content / Page / Entity / Banner / SeoMeta / Settings）拥有引用，
Media 不拥有它们 → Media 生命周期不 mutate 引用方。
```

---

## 三、引用归属与删除 / 重算语义

```text
所有权：
  引用方拥有「指向 Media 的引用」；Media 不拥有引用方。
Content / Page 删除：
  解除媒体引用但不删 Media（D5-1 / D5-6 已冻结）。
Media 不可用：
  渲染走 Media::find()?->url() ?? null / fallback，优雅降级，不破图、不报错。
OG / Cover：
  多级 fallback（SeoMeta → og → cover → site logo）。
```

---

## 四、特殊规则

```text
SVG 禁止上传（可内嵌脚本，存储型 XSS）。
WebP 兄弟：派生文件，仅作 <picture> 首选，原图保留回退。
alt：无障碍与图片检索依赖（媒体记录字段）。
```

---

## 五、一致性缺口（取证发现，需在契约中钉死）

```text
① WebP 兄弟在媒体删除时不清理 → 成为孤儿文件。
② 无「孤儿文件」检测：磁盘有文件、无 DB 记录。
③ 无「缺失文件」检测：DB 记录指向不存在的文件。
④ 无去重：同一文件重复上传 = 独立记录 + 独立文件。
⑤ 文件删除失败未检查：Storage::delete 返回 false 仍删记录 → 记录消失、文件残留
   （或反向：文件删了、记录残留）。
⑥ 外部存储（s3）：path 字符串引用跨磁盘一致性更复杂。
```

---

## 六、与已冻结规则的关系（硬约束，不重定义）

```text
PKS（P15/G-9）：媒体不直接是 PKS 成员；其 discovery 表现由引用方/出口决定。
Entity Lifecycle（D5-2）：实体配图随实体生命周期，不改变实体规则。
Semantic Reference（D5-4/G-7）：Media 不引入新语义引用。
Fact（P14/G-8）：媒体不承载事实。
Content Trash（P16/G-10）：内容软删保留媒体引用，不删媒体。
Page Lifecycle（P17/G-11）：Block 实时读媒体，缺失重算/null。
```

---

## 七、建议冻结结论

### P18（建议编号）

```text
P18  Media 是资产对象（Asset）：二进制文件 + DB 记录同一生命周期，
     不是知识对象、不是纯基础设施。
     Media 无软删 / 回收站；删除是引用保护的：
     被引用媒体不可删，仅未引用媒体可删，且文件 + 记录一起删。
     引用方拥有引用，Media 生命周期不 mutate 引用方。
     媒体不可用时渲染必须优雅降级（null / fallback），不得破图或报错。
     OG / Cover 走多级 fallback；WebP 兄弟是派生文件，
     不得在媒体删除后残留为孤儿。
```

### G-12（建议编号 · Implementation Gate）：Media Lifecycle Integrity

```text
① 媒体删除必须经七路引用扫描：有阻断引用 → 拒绝并审计，
   文件 + 记录都保留。
② 未引用媒体删除必须文件 + 记录一起删除。
③ 媒体删除不得 mutate 引用方（Content / Page / Entity / Banner / SeoMeta / Settings）。
④ 删除必须清理派生 WebP 兄弟（或显式纳入孤儿治理）。
⑤ 渲染必须对缺失媒体优雅降级（null / fallback），不得破图。
⑥ 须变异验证（短路引用扫描 / 绕过文件删除 / 跳过 webp 清理
   → 对应测试 FAIL）。
```

---

## 八、开放边界（显式记录，不静默解决）

```text
① 孤儿文件 / 缺失文件检测与清理（当前无）：属 Implementation，
   不得静默自动删除（等同静默资产损失）。
② 去重策略（hash 去重 / 引用计数）：留待产品决策。
③ 外部存储（s3）一致性与删除失败处理：留待架构决策。
④ Media 是否需要回收站（当前无）：若引入，与 D5-1 对齐。
⑤ alt 强制要求等无障碍规则：留待产品/Implementation。
```

---

## 九、本裁决不做的事

```text
❌ 不改产品代码、不做 UI、不加路由
❌ 不重定义 PKS / Entity / Reference / Fact / Content / Page 已冻结规则
❌ 不把 Media 变成知识对象或第二套 SoT
❌ 不静默自动清理媒体 / 孤儿文件
❌ 不自动进入下一阶段
❌ 本文件在裁定通过前不加入 check-discovery-docs.sh
```

**下一步**：D5-7 经用户裁定后，D5 系列全部 DECIDED
→ Architecture Decision → Implementation。
