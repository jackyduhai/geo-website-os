# D5-7 裁决：Media Lifecycle（媒体生命周期）

- **状态**：**DECIDED（已冻结）**（2026-10-08）· 领域裁决，非实现设计
- **基线**：98f86f3（PROPOSED）→ 本版收紧冻结
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

## 七、冻结结论（P18 / G-12 最终版）

### P18（正式冻结）

```text
P18  Media 是资产对象（Asset）：
  ① Media 由二进制文件与其 DB 记录共同构成一个资产；
     文件本体与 Media 记录属于同一生命周期。
  ② Media 不承载业务事实、不具有知识权威性，
     不属于 PKS / discovery knowledge object。
  ③ Media 不使用 SoftDeletes，不设 Content Trash；
     删除即不可恢复的终态操作。
  ④ Media 删除必须执行引用保护：
     当前仍被任何有效引用方使用的 Media 不得删除。
  ⑤ 删除 Media 不得 mutate 引用方；
     引用关系由引用方拥有，Media 只接受/拒绝自身删除。
  ⑥ 未被引用的 Media 才允许进入删除流程；
     删除必须同时处理 Media DB 记录与其文件资产。
  ⑦ Media 缺失属于可降级状态：
     引用方不得因 Media 缺失产生 500、悬空对象或非法渲染状态。
  ⑧ Media 生命周期不得通过复制文件快照、
     stale metadata 或引用方状态来维持“已删除资产”。
```

### G-12（Implementation Gate · 正式冻结）：Media Lifecycle Integrity

```text
① Reference Protection（引用保护）
   Media 删除前必须通过统一 MediaReferenceScanner；
   当前有效引用存在时必须拒绝删除并产生 media.delete_blocked 审计；
   不得绕过扫描直接删除。
② Asset / DB Consistency（资产/记录一致性）
   Media 的 DB 记录与其文件资产属于同一生命周期；
   成功删除不得留下仍属于该 Media 的有效资产；
   文件删除失败不得无条件删除 DB 记录或宣告删除成功。
③ Derivative Cleanup（衍生物清理）
   Media 删除必须覆盖其受管理的衍生文件，包括 WebP sibling，
   禁止形成已知孤儿资产。
④ External-reference Isolation（外部引用隔离）
   Media 删除 / 拒绝删除不得 mutate 引用方：
   Content / Page / Entity / Fact / SEO 等均保持自身状态。
⑤ Graceful Degradation（优雅降级）
   被引用 Media 缺失、purge 或不可解析时，
   引用方必须安全降级为 null / fallback，
   不得 500、悬空对象或非法渲染。
⑥ Mutation Verification（变异验证）
   必须对引用保护、文件/DB 一致性、衍生物清理、
   外部引用隔离、缺失降级实施变异验证；
   移除关键护栏后对应测试必须 FAIL。
```

---

## 七·补、删除语义与一致性收紧（裁定冻结）

### 7.1 删除流程：引用保护是领域门禁，不是 UI 提示

```text
Reference Scan
  ├─ still referenced? YES → REJECT（审计 media.delete_blocked）
  └─ NO → DELETE ASSET
            ├─ binary / derivatives（含 WebP sibling）
            └─ DB record
任何绕过 MediaReferenceScanner 直接 delete() 成功的实现，均违反领域契约。
```

### 7.2 MediaReferenceScanner = 唯一删除资格判定入口

```text
不神化“七”这个数字：
  当前已发现的全部一等引用路径必须纳入统一删除资格判定。
未来新增 Media 引用类型，必须进入 Scanner / Contract；
  不得因 Scanner 仍只有七路而让新引用类型自动获得删除资格。
```

### 7.3 文件 / DB 一致性：结果性不变量（不冻结原子事务实现）

```text
工程现实：DB transaction 无法与 S3 / filesystem delete 组成真正跨系统原子事务。
领域层冻结结果性不变量，不冻结技术实现：
  成功终态：DB 记录不存在 + 属于该 Media 的文件/衍生文件已完成处置。
  失败终态：不得错误宣告为已删除 + 必须留下可重试 / 可审计状态。
具体采用 delete-first / record-first / pending-delete / retry /
  reconciliation，由 Architecture 阶段决定。
```

### 7.4 WebP 兄弟属于同一 Asset lifecycle

```text
Original + Derived WebP 同一生命周期：
  Media DELETE → Original → WebP sibling / generated derivative 同步处置。
DB 已删、Original 已删、WebP 残留 = 违反 G-12 的资产一致性。
```

### 7.5 引用保护 ≠ 反向 mutation

```text
引用方拥有“我要引用这个媒体”；Media 拥有“这个资产是否允许被删除”。
Media 删除时不得反向修改 Content / Page / Entity / Fact / SEO
  来“制造未引用状态”。
```

### 7.6 缺失 Media：运行时降级原则

```text
Media exists → render URL；Media missing → null / fallback。
missing ≠ 500 ≠ dangling object ≠ fake asset。
OG / Cover fallback：SeoMeta → og → cover → site logo。
与 D5-6 Block live recomputation 同一哲学：引用是动态关系，非死亡对象快照。
```

### 7.7 Media 无 Trash（与 Page 原因不同）

```text
Content → Soft Delete → Trash → Restore / Force Delete
Page    → Hard Delete（结构对象，删除自有结构）
Media   → Protected Hard Delete → No Trash
  （资产对象，删除前必须证明“不再被引用”；核心保护是
   Pre-delete Reference Protection，而非 Trash）
```

---

## 八、开放边界（显式记录，不污染 P18 领域定义）

```text
① 去重（Deduplication）：同一 binary 多次上传 → 多个 Media record。
   属 Asset Identity / Deduplication Policy，非生命周期正确性；
   不得把两个 Media 记录偷偷当成同一生命周期对象。
② 孤儿文件检测（Storage Integrity / Reconciliation）：
   DB ↔ Storage 可能漂移；检测与清理属 Architecture，
   不得静默自动删除（等同静默资产损失）。
③ S3 / 跨磁盘一致性（Storage Backend Architecture）：
   非本领域裁决；但冻结基础规则——Media 记录必须明确其所属存储资产，
   生命周期操作不得默认假定“当前默认 disk”。
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
❌ 不把去重 / 孤儿 / S3 后端问题写进 P18 领域定义
```

**D5 全部领域生命周期项收口**：D5-7 DECIDED
→ 下一步为 **D5 全量 Architecture Synthesis / Implementation Contract**
（汇总 P14–P18 与 G-7–G-12 为可执行架构契约），再进入 Implementation；
进入前仍须按 Gate 纪律等用户明确授权。
