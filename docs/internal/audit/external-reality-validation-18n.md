# P-STEP 18N — External Reality Validation（外部真实验证）

- **阶段定位**：18M Final Product Hardening PASS 之后、19A Private GitHub + CI 之前的只读 Gate。验证"内部工程闭环 ≠ 外部用户真正可用"。
- **执行日期**：2026-09-26（周六，UTC+8）
- **方法论**：场景 1/2/3 由**全新、无会话历史、无内部知识**的子 Agent 执行，仅允许读取指定文档；所有结论来自真实命令、真实 HTTP 响应、真实浏览器操作，禁止凭文档描述下结论。
- **硬边界**：只读验证，未修改产品代码/模板包/主题；未配置 remote、未 push、未 Release、未移动 rc1 tag；业务污染扫描 13 词运行态全 0。
- **被测版本**：GEO Website OS v2.0.0（Laravel 12 / PHP 8.4.25 / SQLite / Vite+Tailwind），commit `a737abb`（18M 收尾）。

---

## 1. Executive Summary

**18N 的目的不是清点"还有多少优化空间"。** v1.1 / Marketplace / AI Builder 等演进项不是失败——它们本来就不在 v1.0 范围。18N 真正要证明的是：v1.0 是否已形成**清晰边界、受控扩展、AI 易正确使用、用户能完成核心任务、GEO/SEO 不因扩展失控**的稳定底座。

**六领域全部通过，无 Level 1 发布阻断。裁定：READY FOR 19A。**

核心证据：
- **用户能完成核心任务**：陌生开发者仅凭 4 份文档完成全流程安装（S1）；AI Agent 零代码完成品牌→模板→页面→表单→验证闭环（S2）；非技术运营完成主题切换/模板切换/区块编排/CTA 修改/制造业→出口企业改造（S4）。
- **AI 易正确使用**：AI Agent 全程走分层（Theme→Template→Page Composition），未改 Blade/CSS/Schema/插 HTML，未绕过 Registry，未破坏 GEO Semantic（S2）。
- **受控扩展**：第三方模板作者从零创建 --strict 通过的模板，V1 封闭（无 PHP/Blade/JS）成立；Validator 对结构错误定位精确（recipe key + block 序号 + type）（S3）。
- **GEO/SEO 不因扩展失控**：JSON-LD 7 类/llms.txt 双语/sitemap 27 URL/hreflang/语义 HTML 齐全；业务污染 13 词全 0；模板切换后 GEO 输出自动跟随（S5）。
- **安全边界运行时成立**：SafeUrl scheme 白名单（18L-4a TD-135）、HeadCodeSanitizer（18H-3）、受控动态 CSP（18H-3）已落地；18N S3 实测模板包夹带 hack.php 运行时不执行（校验层缺口记 TD-142，非 RCE）。
- **清晰边界**：Template Package→Validator→Composition Renderer→SEO/GEO 封闭方向正确。第三方"能力不足"的反馈只记为 SDK Enhancement（Level 2/3），**不放开安全限制**——V1 不允许模板包携带 Blade/PHP Renderer/可执行代码，此裁定不动摇。

**最严重的 3 个发现（均为 Level 2 发布债务，不阻断 19A）**：
1. **种子 CTA 链接硬编码 `http://localhost/` 死链**（TD-140）——全新安装后前台按钮指向不存在的地址。
2. **模板 bootstrap 只叠加不替换**（TD-141）——连续切换模板堆出 3 个 Hero 并撞 20 块上限。
3. **Template Validator 不强制 V1 文件类型封闭**（TD-142）——往包里塞 `hack.php` 干净通过（运行时不执行，非 RCE，但校验层失守）。

---

## 2. Reality Scorecard（六领域结果 + 是否阻塞）

| 领域 | 结果 | 最高等级 | 证据 |
|---|---|---|---|
| 安装体验 | **PASS** | L2 | S1 真实命令日志：composer install→空 sqlite→geo:install（43 迁移）→npm build→serve→前台 200→GEO 端点全 200→缓存 HIT；文档缺口（默认凭据/后台 URL）记 TD-144，不阻断 |
| 模板生态 | **PASS** | L2 | S3 真实 validate 结果：cozy-cafe --strict 退出码 0（3 recipes/10 blocks/0 error）；4 类错误拦截文本附原文；原仓库恢复 8 包全通过；规范示例不合规记 TD-143、Validator 不扫违禁文件记 TD-142 |
| AI 使用 | **PASS** | L2 | S2 真实建站：CloudPeak 品牌/模板/首页/表单全链路零代码完成，未改核心代码；走分层未改 Blade/CSS/Schema；zh/en/GEO 验证全 200；agent-guide 缺位置信息/分层决策树记 TD-144/149 |
| 运营体验 | **PASS** | L2 | S4 浏览器真实操作：6/6 零代码操作 4-5 分；制造业→出口企业改造 zh 站走通（换模板/换主题/改首页结构/改 CTA/加产品区块/调语言）；模板叠加记 TD-141、多语言不同步记 TD-147 |
| GEO 能力 | **PASS** | L2 | S5 真实抓取：JSON-LD 7 类/llms.txt 双语/sitemap 27 URL/hreflang/语义 HTML 齐全；盲读 4 问 3.5/4（转化目标因 localhost 死链半缺，TD-140）；业务污染 13 词全 0；多 AI 对拍因无 API 未执行，已记录限制 |
| 安全边界 | **PASS** | L2 | 攻击测试取证见下方；运行时安全成立，校验层缺口记 TD-142 |

### 安全边界攻击测试取证

| 攻击面 | 测试方式 | 结果 | 证据来源 |
|---|---|---|---|
| XSS（存储型/反射型） | 依赖 18L-4a TD-135 SafeUrlService + 18H-3 HeadCodeSanitizer 已落地；18N 未重复注入攻击 | **运行时拦截**：seo_head_code 仅 meta/link 白名单、script/javascript: 剔除标 invalid；所有用户输入经 Laravel escaping | 18H-3 Gate 实证（7 行混合样本 allow/deny）；18N 沿用结论 |
| URL injection（javascript:/data:） | 依赖 18L-4a TD-135 SafeUrl scheme 白名单已落地；CTA/hero/media_text/menu 所有 URL 入口统一净化 | **运行时拦截**：允许 http/https/mailto/tel/内部 route，拒绝 javascript:/data:/vbscript:/file: | 18L-4a Gate 实证；18N S4 修改 CTA 链接时仅用相对路径 /contact/，未触发危险 scheme |
| Template abuse（模板包夹带可执行文件） | 18N S3 实测：在 cozy-cafe 包根放 `hack.php`（`<?php echo 'x'; ?>`），跑 template:validate | **校验层通过但运行时不执行**：validator ERROR=0 WARNING=0 干净通过（TD-142）；但运行时管线只解析 manifest/recipes/defaults，不扫描也不执行包内 PHP，非直接 RCE | 18N S3 真实 validate 输出（§6.3 错误④） |
| Permission 越权 | v1.0 仅 super admin 单角色（TD-14 RBAC 三角色 DEFERRED v1.1），无跨角色权限可测 | **不适用 v1.0**：单角色下不存在越权面；多站隔离经 18F/18G 验证 Site-scoped | TD-14 已登记 v1.1；18N 不重开 |

**安全边界裁定**：运行时安全成立（SafeUrl + HeadCodeSanitizer + CSP + Site-scoped），Template Validator 校验层缺口（TD-142）为 Level 2 修复项，不构成 Level 1 阻断。

---

## 3. Blind Test Findings（非 BUG 的陌生者困惑点）

专门记录"系统没坏，但第一次用的人会卡住或误解"的体验问题：

1. **"激活"vs"初始化默认值(bootstrap)"**：模板卡片上两个按钮，没有 tooltip 或说明解释区别。陌生用户不知道该点哪个、是否都要点。（实际：activate 套结构，bootstrap 落中性默认菜单/SEO，不覆盖已有数据）
2. **仪表盘"一排 0"**：登录后看到 0 已发布/0 产品/0 留言，没有"下一步该做什么"的引导。决定网站长相的"激活模板"在侧边栏"模板生态"里，仪表盘没有 CTA。陌生运营 3 分钟内找不到该先点哪。
3. **主题管理 vs 主题样式**："主题管理"页只有 default/example 两套文件主题；"站点设置→主题样式"有 8 个行业视觉预设。两个入口都叫"主题"，运营人员不知道先改哪个。
4. **模板切换是叠加不是替换**：换模板后旧区块不消失，新模板又加一组，首页越来越长。用户以为"换模板"会换掉内容，实际是"追加"，连续切换堆出 3 个 Hero。
5. **改完中文以为英文也改了**：双语站点的 zh/en 首页是独立区块集，运营人员改了 zh 首页后以为 en 同步了，实际 en 还是旧内容。
6. **区块上限 20 报错在页面顶部**：添加区块时如果撞上限，错误提示在页面最上方，用户滚动到底点"添加"后看不到任何反馈，以为系统坏了。
7. **默认密码只打印一次**：geo:install 不传 --admin-password 时随机生成并只在安装日志里打印一次。关了终端就丢了，没有"忘记密码"重置入口文档。
8. **前台必须 npm run build**：后台能开、能登录，但前台没样式。新人以为"安装完就能看"，实际还要 build 前端资源，而文档没强调这是前置条件。
9. **规范示例过不了校验**：第三方作者照抄 SDK 规范里的"完整 manifest 示例"，直接 validation failed（缺顶层 industry、版本 1.0 非 1.0.0）。
10. **删除区块无确认**：点删除立即生效，没有"确定删除此区块？"对话框。
11. **统计卡与实际内容脱节**：模板 bootstrap 后首页内容满满，但仪表盘统计卡仍显示"0 已发布/0 产品"（统计只数 contents，不数组合页面）。
12. **Component 层无独立 UI**：agent-guide 把 Component 列为独立一层，但后台没有组件编辑器，新 AI 找不到对应入口，无法区分"区块变体"和"组件变体"。

---

## 4. Scenario 1 · 陌生企业用户安装测试

**执行人**：全新子 Agent，仅允许读 `README.md` / `DEPLOY.md` / `.env.example` / `.env.production.example`。
**工作目录**：`D:\Temp\geo18n-s1`，serve `http://127.0.0.1:8181`。

### 4.1 执行结果

8 步全部用真实命令 + 真实 HTTP 响应完成：

1. 复制源码到临时目录（原仓库未改动）
2. `composer install` — 依赖完整
3. 配置 `.env`（APP_URL→8181）+ 新建空 SQLite
4. `php artisan geo:install` — 43 条迁移、建站、建管理员，自检逐项打勾
5. `npm run build` + `php artisan serve --port=8181`
6. 登录后台 `/admin/login`，激活 **service-pro** 模板并 bootstrap
7. 前台 `GET /` → 200 正常渲染；二次访问 `X-Page-Cache: HIT`；`/geo.json`、`/sitemap.xml`、`/llms.txt`、`/feed.xml`、`/robots.txt` 全 200
8. 实测发布/下架机制并正确恢复原状

### 4.2 环境依赖透明度核查

| 依赖项 | 文档是否写明 | 评价 |
|---|---|---|
| PHP 版本（8.4+） | ✅ 清晰 | README Requirements + DEPLOY §1，含扩展清单 |
| Node/npm | ✅ 有写 | 但"npm run build 是前台渲染前置条件"写了不清晰——没说不 build 前台缺 CSS/JS，而后台不依赖 Vite 会误导人 |
| 数据库选择 | ⚠️ 部分 | 默认 SQLite、MySQL 有写；Postgres 没点名（只笼统说"Laravel 支持的任意库"） |
| 目录写权限 | ⚠️ 部分 | DEPLOY 有写，README 漏写 |
| 隐含步骤 | ❌ 多处 | `storage:link` README 漏写；`QUEUE_CONNECTION` 两份示例自相矛盾；`.env.example` 作为本地模板却 `APP_DEBUG=false` |

### 4.3 首次进入后台是否知道下一步

- 登录后仪表盘是"**一排 0 + 站点信息待补全提示 + 空留言/空日志**"，**没有分步向导**（无欢迎页、无"选模板→配品牌→生成→发布"引导路径）。
- 反直觉：模板 bootstrap 后首页内容满满，但统计卡仍显示"0 已发布/0 产品"。
- 模板命名自解释，但"**激活**"和"**初始化默认值(bootstrap)**"两个按钮**没解释区别**。
- 结论：陌生运营**3 分钟内找不到该先点哪**——决定网站长相的"激活行业模板"藏在三级菜单里，仪表盘没有任何 CTA 指向它。

### 4.4 关键发现

| # | 发现 | 严重度 | 分类 |
|---|---|---|---|
| 1.1 | 默认管理员密码完全未文档化；不传 `--admin-password` 时随机生成并只打印一次，丢了就进不去 | 高 | TD-144 |
| 1.2 | 后台 URL `/admin/login` 未在文档中写明，靠探测 `/admin`→302 才发现 | 高 | TD-144 |
| 1.3 | 原仓库不是干净源码树：自带 `vendor/`、`.env`（含 APP_KEY）、634KB 已播种 sqlite | 中 | 发布工程注意 |
| 1.4 | 发布/下架是两个方向相反的端点；对已草稿页再 POST `/unpublish` **静默返回 200 但什么都不做** | 中 | TD-146 |
| 1.5 | `geo:install` 非交互 flags README 只说"flags available"未列出 | 中 | TD-144 |
| 1.6 | 模板(templates)与主题(themes)的区别和操作顺序无文档说明 | 低 | TD-145 |

### 4.5 裁定

**PASS**。核心目标"从零安装并跑通一个网站"已达成。扣分项全是文档/引导问题，不是系统跑不起来。

---

## 5. Scenario 2 · 全新 AI Agent 使用测试

**执行人**：全新子 Agent，仅允许读 `README.md` / `docs/ai/agent-guide-v1.md` / `docs/audit/template-sdk-specification-18l4.md`。
**任务**：为虚构 SaaS 公司 "CloudPeak" 建站，全程不改核心代码。
**工作目录**：`D:\Temp\geo18n-s2`，serve `http://127.0.0.1:8182`。

### 5.1 执行结果

全链路 **100% 通过后台 UI / .env / 模板级定制完成**：

- 全新安装：composer install → geo:install（45 迁移）→ vite build
- 品牌：公司名 CloudPeak、主色 `#4F46E5`（前台 `:root` 自动派生全套色阶）、标语写入 Organization JSON-LD
- 模板：选 `saas-pro`（行业 saas-technology、受众 SaaS company、转化 demo），activate + bootstrap
- 首页定制：Hero 改为「小团队的项目管理，一座山坡就够了」，前台唯一 H1 验证通过
- 表单：新建 Demo Request 留资表单（4 字段双语），系统 contact 表单真实提交一条留言，后台"客户留言"已收到
- 验证：zh/en 首页、/contact/、geo.json、llms.txt、sitemap.xml 全部 200

### 5.2 是否走分层而非改 Blade/CSS/Schema/插 HTML

| 层 | 是否走分层 | 说明 |
|---|---|---|
| Theme（视觉主题） | ✅ 走分层 | 写一个 primary 自动派生 active/soft/on/hero 渐变，未手写 CSS |
| Component（组件变体） | ⚠️ 最模糊 | 后台无独立组件编辑器，无法区分"区块变体"和"组件变体"；未直接操作 |
| Template Package（行业模板包） | ✅ 走分层 | saas-pro activate + bootstrap，理解是结构配方而非视觉 |
| Page Composition（页面区块组合） | ✅ 走分层 | composer 改 Hero，理解是区块编排，未改 Blade |
| Semantic Layer（语义元数据） | ✅ 只读不写 | JSON-LD/geo.json 自动派生，未手动干预、未插 HTML |

**反模式诱惑**：唯一差点破戒的是表单创建两次 302 失败时，本能想去翻 `routes/web.php`/FormController——忍住了，改用读页面回显红字定位。**硬编码颜色、手改 SEO、破坏 JSON-LD、插 HTML 四项均未做。**

**四条建议路径的可操作性**：
- 创建页面：✅ `/admin/pages` → 新建 → 选模板 → 加 block，路径清晰
- 改模板：✅ `/admin/templates` → Compare → Activate → Bootstrap，路径清晰
- 扩组件：❌ 无独立入口，Component 层在运行时几乎无可操作 UI
- 报错修复：⚠️ 表单失败静默 302 回 create 页、错误埋在页面红字里，文档没说这一交互模式

### 5.3 agent-guide 不足

- 否定句（红线）充分，但缺少"想做 X 该进哪一层"的决策树。
- Component 层被列为独立一层，但运行时无对应 UI，新 Agent 找不到入口。
- 后台 URL 一个都没写，全部靠探测反推。

**最可能的犯错点**（不够谨慎的 AI 会）：把模板包当主题改（直接编辑 `resources/templates/saas-pro/theme.json`）、把 Composer 当文件改（手改 Blade）、往 `theme_custom_css` 硬编码色值、手贴 JSON-LD、改完 zh 以为双语完成。

### 5.4 关键发现

| # | 发现 | 严重度 | 分类 |
|---|---|---|---|
| 2.1 | 后台 URL 全部靠探测反推（/admin/login、/admin/settings/general、/admin/templates/{pack}/activate 等） | 高 | TD-144 |
| 2.2 | `geo:install` 非交互 flag 名缺失，必须跑 `help` 才知道 | 中 | TD-144 |
| 2.3 | 表单创建失败时静默 302 回 create 页、错误埋在页面红字里 | 中 | 观察项 |
| 2.4 | 译文页与主语言页不联动：改了 zh 首页 Hero，en 首页仍是模板默认文案 | 中 | TD-147 |
| 2.5 | `geo.json` 的 `site.description` 为空，而 JSON-LD Organization `description` 正常——不一致 | 中 | TD-148 |
| 2.6 | agent-guide 缺分层决策树；Component 层无独立运行时 UI | 低 | TD-149 |

### 5.5 裁定

**能闭环，AI 走分层未破坏系统。** 架构契约被尊重得很好，但文档把"位置信息"全省略了——agent-guide 更像给"已经认识后台的人"的纪律手册。

---

## 6. Scenario 3 · 第三方模板作者测试

**执行人**：全新子 Agent，仅允许读 `docs/audit/template-sdk-specification-18l4.md` + `resources/templates/manufacturing-pro/` 示例。
**任务**：从零创建 custom-template（社区咖啡店 cozy-cafe），跑 `template:validate`，故意制造错误看报错。

### 6.1 执行结果

- 模板仅含 `.json`/`.md`/`.webp`（无 PHP/Blade/JS），9 个 JSON 全部合法
- 干净校验：`template:validate cozy-cafe` 与 `--strict` 均退出码 0（3 recipes / 10 blocks / 0 error / 0 warning）
- 故意引入 3 个错误 + 1 个额外探测，全部记录真实报错文本
- 测试模板已从 `resources/templates/` 删除，原仓库回到 8 个官方包且全部校验通过

### 6.2 Manifest / Metadata / Recipe 理解度

| 维度 | 规范是否足够 | 说明 |
|---|---|---|
| Manifest 结构 | ⚠️ 部分 | 目录布局/字段表/requires 讲得清楚，但**自带示例过不了校验**（缺顶层 industry、版本 1.0 非 1.0.0） |
| Metadata（identity/purpose/conversion/entities） | ⚠️ 部分 | 字段名有，但**受控词表不在规范里**（8 行业/7 purpose/7 conversion 等只能读源码） |
| Recipe 结构 | ✅ 足够 | recipe 写法、target/template/slots/blocks 结构讲得清楚，示例可照抄形状 |
| defaults/preview/migration | ✅ 足够 | defaults 中性原则、migration 四类规则、preview 要求讲得清楚 |

### 6.3 Validator 错误定位质量（附真实文本）

| 错误类型 | 实际输出 | 定位精度 |
|---|---|---|
| 缺必填字段（删 conversion.primary） | `manifest 缺少 conversion.primary 字段` | ✅ 直接点名字段 |
| 非法受控值（purpose.primary=fly_to_moon） | `purpose.primary「fly_to_moon」不在受控业务目标内` | ⚠️ 指出错误值但**不列合法可选值** |
| 未注册 block（recipe block#0 type=dragon_bowl） | `recipe「homepage」block #0（dragon_bowl） type 未注册` | ✅ 给出 recipe key + block 序号 + 错误 type，定位成本极低 |
| 违禁文件（包根放 hack.php） | `ERROR=0 WARNING=0, All template packs valid.` | ❌ **不扫描文件类型** |

### 6.4 是否理解限制原因

第三方作者能理解 V1 封闭的原因（安全、可校验、声明式），但会感到：
- 行业词表/block 类型/槽位映射不在规范里，每次都要读源码——"限制有，但不告诉你边界在哪"。
- 规范示例本身不合规——"连官方示例都过不了校验，说明规范没跟上实现"。
- Validator 不扫描违禁文件——"说好了封闭，但校验器不查，那封闭靠自觉？"

**封闭性裁定**：这些"限制多"的反馈一律记录为 SDK Enhancement（Level 2/3），**不降低安全边界**。V1 不允许模板包携带 Blade/PHP/可执行代码——运行时管线不执行包内 PHP（非直接 RCE），但 Validator 应落实校验层封闭（TD-142）。

### 6.5 关键发现

| # | 发现 | 严重度 | 分类 |
|---|---|---|---|
| 3.1 | 规范自带的"完整 manifest 示例"过不了校验（缺顶层 industry 数组、版本 1.0 非 1.0.0） | 高 | TD-143 |
| 3.2 | 8 行业词表、block 类型/字段、系统页 key、槽位映射、主题名**都不在规范里**，读了 13 个源文件才填出合法 manifest | 高 | TD-143 |
| 3.3 | V1"禁止 PHP/Blade/JS"只是约定——往包里塞 `hack.php` 干净通过校验 | 高 | TD-142 |
| 3.4 | 受控词表错误不列可选值 | 中 | 观察项（并入 TD-143） |
| 3.5 | 模板必须物理放进 `resources/templates/{id}/` 才能被发现，无 `--path=` 参数 | 低 | 观察项 |

### 6.6 裁定

**靠"规范 + 一个示例"能做出合法模板，但纯靠规范本身不行。** 示例补上了规范漏写的形状信息；校验器对结构类错误反馈友好且定位精确，对安全封闭类（违禁文件）未强制。

### 6.7 R3 深度实证：模板夹带文件可达性（攻击测试取证）

S3 发现 `template:validate` 不扫描违禁文件后，R3 进一步实证：夹带的 `.php`/`.blade.php` 是否真的能进入执行链。**四条执行链全部实证关闭，定级 L2（运行时安全，供应链校验边界不完整）。**

| 执行链 | 实证方法 | 结果 |
|---|---|---|
| Composer autoload | `composer dump-autoload` 后检查 classmap/psr4 | `resources/templates` 命中=0，PSR-4 仅 `app/`；evil.php 不被加载 |
| Laravel view finder | tinker 测试 `view()` 5 个候选路径 | view.paths 仅 `resources/views`，无命名空间；evil.blade.php 全部 MISS |
| include/require | 全仓扫描动态包含 | 模板管线只 `file_get_contents` JSON；唯一动态 require 在 `plugins/` 系统（与 templates 无关） |
| web 直接访问 | curl 9 条 URL（含 `../`、`%2e%2e`、`vendor/../` 穿越） | docroot=`public/`，全部返回 Laravel 404 页，无 `RCE_TEST_` 输出 |

**附加防护确认**：`screenshot()` 预览接口已有 realpath + webp 白名单；pack-id 穿越被 `is_dir()` 守卫；recipe 无文件路径字段；activate/bootstrap 只写数据库、不复制文件到 `public/`。

**Evidence Chain（TD-142）**：
- 发现来源：S3 盲测（hack.php 干净通过 validate）→ R3 实证
- 复现步骤：① 在模板包根放 `evil.php`（`<?php echo "RCE_TEST"; ?>`）② `template:validate` → ERROR=0 ③ activate + bootstrap ④ curl 访问所有可能路径
- 实际结果：validate 通过；但 evil.php 不被 autoload、不被 view finder 命中、不被 include、web 不可达（全 404）
- 期望结果：validate 阶段应 fail-closed 拒绝违禁文件
- 影响：运行时无 RCE 风险；但供应链边界不完整——生态开放后恶意模板可夹带文件，依赖运行时隔离而非校验拦截
- Level：L2
- 是否阻断 19A：否

**建议**：v1.0 补 `TemplatePackageSecurityScanner`，在 `template:validate` 阶段 fail-closed 拒绝 `.php/.phtml/.phar/.blade.php/.js/.exe/.sh/.env/.htaccess` 及 `../`/封装协议；更优是白名单（仅 `*.json + preview/*.{webp,png}`）；`activate()` 前二次重扫防 TOCTOU。


---

## 7. Scenario 4 · "制造业官网 → 海外出口官网"零代码任务

**执行人**：子 Agent 浏览器自动化，在运行中 demo（http://127.0.0.1:8171）后台操作。
**任务**：全程零代码，把制造业官网改成海外出口企业官网。

### 7.1 执行步骤与结果

| 步骤 | 操作 | 结果 |
|---|---|---|
| 1 换模板 | Compare → Activate export-pro（International Export OS Pro）+ Bootstrap | ✅ 模板激活成功 |
| 2 换主题 | 切回 default 主题 → 应用"科技软件"行业视觉预设（靛蓝 #4F46E5） | ✅ 预设应用成功，确认对话框说明"仅覆盖外观" |
| 3 改首页结构 | 删除重复 Hero（模板叠加导致 3 个 Hero），新增 product_grid 产品展示区块 | ✅ 区块增删成功，product_grid 自动读取产品 |
| 4 改 CTA | 主 CTA 改为"获取出口报价"→/contact/、"联系海外销售"→/contact/，修正种子 localhost 死链 | ✅ CTA 保存成功，前台即时反映 |
| 5 加产品区块 | product_grid（数据源型，6 个产品，出口导向标题） | ✅ 产品展示区块渲染 |
| 6 调语言 | 双语已启用（zh-CN + en），导航主 CTA 改为"Get a Quote / 获取报价" | ✅ 设置保存成功 |
| 7 前台验证 | zh 首页 H1 收敛为 1 个、出口 CTA 全部上线、产品区块渲染、双语可访问 | ✅ zh 站完美；en 站为独立区块集未同步 |

### 7.2 基础零代码操作评分（6 项）

| 任务 | 评分(1-5) | 说明 |
|---|---|---|
| 切换主题 | 4 | 有确认对话框；但主题管理(2套)与主题样式(8预设)双入口易混 |
| 切换模板（Compare→Activate→Bootstrap） | 4 | Compare 页展示差异；但 bootstrap 是叠加非替换 |
| 增/删/排序区块 | 4 | 添加页类型丰富；删除无确认框；上移/下移可用 |
| 切换组件变体（Hero split→center） | 5 | 变体下拉一键切换，提示清晰 |
| 修改 CTA 文案/链接 | 4 | 字段明确；按钮需点"+添加按钮"才出现 |
| 退出后前台验证 | 5 | 所有改动即时反映 |

### 7.3 关键发现

| # | 发现 | 严重度 | 分类 |
|---|---|---|---|
| 4.1 | **种子 CTA 按钮链接硬编码 `http://localhost/contact/`、`/solutions/`、`/products/`**——全新安装后前台按钮是死链 | 高 | TD-140 |
| 4.2 | **模板激活只叠加不替换**：连续切换后首页堆出 **3 个 Hero**、3 个 FAQ，撞 **20 块上限** | 高 | TD-141 |
| 4.3 | 区块上限 20 报错在页面顶部、远离提交按钮 | 中 | 观察项（并入 TD-141） |
| 4.4 | **en 首页是独立区块集，zh 编辑不同步到 en**——出口改造后 zh 完美，en 仍累积 3 个模板的 Hero | 中 | TD-147 |
| 4.5 | 删除区块无确认对话框，即时生效 | 低 | 观察项 |

### 7.4 裁定

**zh 站零代码改造走通，6/6 基础操作 4-5 分。** 模板叠加和多语言不同步是出口/双语场景的实质体验缺口，但不阻塞核心任务完成。

### 7.5 R4 深度核查：Locale 一致性是"设计如此"还是"缺陷"

S2/S4 发现 zh 编辑不同步 en 后，R4 逐项排查 7 个维度。**判定：情况 A（设计如此），定级 L2（文档债务），非 L1。**

| 维度 | 结论 | 代码证据 |
|---|---|---|
| Page locale | zh/en 是两条 Page 行，同 `translation_group` | `2026_09_23_000010_create_pages_table.php:28-34`；实测 DB Page#1(zh)/Page#2(en) |
| Block locale | Block 无 locale 列，挂 `page_id`，事实 per-locale | `PageBlock.php:16-19`（无 Translatable）；`HomeRenderContext.php:39-43` 严格按 locale 取 Page |
| ContentRevision | 只挂 Content（per-locale），PageBlock 不产生修订 | `ContentRevision.php:25-28`；全仓 `ContentRevision::create` 仅 2 处，都不在 block 路径 |
| Locale fallback | 业务路径无回退，en 空则渲染中性欢迎屏 | `SetLocale.php:41-42` 注释「不做语言回退」；`HomeRenderContext.php:49-59` 构造空 Page |
| PageCache | key = sha1(host+path)，`/` 与 `/en` 独立 | `PageCache.php:72-79`；`forgetPage` 按 locale 加前缀只 invalidate 当前 path |
| Recipe locale | recipe 内文案是 `{zh-CN,en}` map，bootstrap 按 locale 循环写独立 block | `RecipeApplier.php:51-63,215-258` |
| Admin update flow | updateBlock 只写 route-bound 那一行 block，无兄弟同步 | `PageController.php:246-250`；**`BlockController.php:27` 写死 `LocaleRegistry::default()`（zh-CN）** |

**S2/S4 现象根因**：zh Hero 和 en Hero 是两条独立 PageBlock 行。后台改 zh Hero 只 UPDATE `page_id=1`；en Hero（`page_id=2`）停留在 RecipeApplier 从 locale map `"en"` 解包写入的模板文案。`Page::$sharedTranslatableColumns = ['template','is_home','is_system','system_key','status']` 明确**不含 blocks/title**。S4 连续切模板后两个 locale 本应都堆积，但侧边栏「首页装修」菜单硬编码跳 zh composer，运营只清了 zh，en 留了 3 个 Hero。

**L1 排除**：实测 DB `entities` 表空、`geo_org_name`/`geo_org_en_name` 均空、`site.name="GEO Website OS"`。en 页 JSON-LD Organization.name = `"GEO Website OS"`（通用平台名），无旧公司名、无跨行业产品名、无 zh 实体串到 en。属于「内容旧但企业信息正确/为空」→ L2。

**Evidence Chain（TD-147）**：
- 发现来源：S2（改 zh Hero 后 en 不变）+ S4（en 累积 3 个 Hero）→ R4 代码核查
- 复现步骤：① 双语站点激活模板 bootstrap ② 后台编辑 zh 首页 Hero ③ 访问 /en 首页
- 实际结果：en 首页 Hero 仍为模板默认文案，不随 zh 改动
- 期望结果：文档应明确说明 per-locale 独立编辑模型；后台应提供 en 入口
- 影响：运营人员改完中文以为英文同步了，实际 en 是旧内容；但企业实体信息正确，无错误 Schema
- Level：L2
- 是否阻断 19A：否

**新发现 TD-150**：`BlockController.php:27` 侧边栏「首页装修」菜单链接硬编码 `LocaleRegistry::default()`（zh-CN），en 管理员点击后进入 zh composer 而非 en。多语言站点运营体验缺陷。

**文档应补 5 条**：① 区块 per-locale，改 zh 不会翻译 en；② 后台「首页装修」菜单只跳 zh，en 需走「组合页面 → en 行 → 组合内容」；③ 切模板是叠加不是替换，残留 per-locale 需分别清理；④ en 区块为空时渲染欢迎屏，不回退 zh；⑤ PageCache 按 path 独立，改 zh 不污染也不刷新 en 缓存。


---

## 8. Scenario 5 · 多 AI GEO/LLM-readable 验证

**方法**：抓取首页/详情页/llms.txt/sitemap.xml/JSON-LD/语义 HTML，从"一个只看页面输出的全新 AI"视角评估。

### 8.1 多 AI 对拍限制说明

用户期望用 ChatGPT/Claude/Gemini/豆包/Kimi 多 AI 读取 homepage/llms.txt/sitemap/schema 对拍。**本环境无外部 AI API 密钥和登录态，无法真实调用。** 替代方案：由执行 Agent 模拟"只读页面输出的全新 AI"视角，逐项核查结构化数据完整性与语义可理解性。此限制不影响 GEO 信号本身的评估结论，但跨模型一致性未经验证。建议 19A 后 CI 环境具备时补做。

### 8.2 机器可读信号清单

| 信号 | 状态 | 说明 |
|---|---|---|
| JSON-LD | ✅ 7 类 | Organization / WebSite / Product / FAQPage / BreadcrumbList / LocalBusiness / HowTo |
| meta/OG | ✅ | title/description/OG title/OG description/OG image 齐全 |
| hreflang | ✅ | zh-CN / en / x-default 双向正确 |
| robots.txt | ✅ | 显式放行 GPTBot/ClaudeBot/Googlebot，声明双语 sitemap |
| llms.txt | ✅ 双语 | 含"引用须知/实体口径"，页面入口清单完整 |
| sitemap.xml | ✅ | 27 URL，带 lastmod/priority |
| 语义 HTML | ✅ | section/data-entity/data-conversion/data-purpose 语义属性输出 |
| geo.json | ✅ | site.name/organization.name 正确；site.description 为空（TD-148） |
| 业务污染 | ✅ 全 0 | 13 词在首页 zh/en、详情页、双语 llms.txt、全站 27 URL 均为 0 |

### 8.3 盲读四问（仅看公开页面输出）

| 问题 | 能否读出 | 证据 |
|---|---|---|
| ① 这是什么公司？ | ✅ 能 | JSON-LD Organization.name + 首页 H1 + llms.txt 标题 |
| ② 做什么产品/服务？ | ✅ 能 | Product JSON-LD + product_grid + 导航栏目 |
| ③ 服务谁/目标受众？ | ✅ 能 | 模板 manifest identity.audience + 首页文案定位 |
| ④ 转化目标是什么？ | 🟡 半能 | CTA 按钮存在，但种子链接是 `http://localhost/` 死链（TD-140），联系页缺电话/邮箱；AI 能看出"想让用户联系"但转化路径有断链 |

### 8.4 裁定

**GEO 引擎成立，信号齐全，污染零泄漏。** 唯一折扣是转化目标路径因 localhost 死链和缺直接联系方式而不完整（TD-140）。

---

## 9. Core Promise Validation（核心承诺验证）

### 9.1 对【企业用户】

| 承诺 | 结果 | 证据 |
|---|---|---|
| 可安装 | ✅ 满足 | S1：陌生开发者仅凭 4 份文档完成 composer install → .env → 空 sqlite → geo:install → serve → 前台 200 全流程 |
| 可运营 | ✅ 满足 | S4：6/6 零代码操作走通，评分 4-5；制造业→出口企业真实改造 zh 站完成 |
| 可切换主题/模板 | ✅ 满足 | S4：主题 default↔example 切换即时生效；模板 Compare→Activate→Bootstrap 一步完成；8 行业视觉预设一键应用 |
| 可维护升级 | ✅ 满足 | 18M 已通过 Fresh/Upgrade/Backup/Restore 测试；18N 沿用该结论 |

> 折扣项（Level 2，不阻断）：首次启动无引导（TD-145）、默认凭据未文档化（TD-144）、模板叠加（TD-141）、多语言不同步（TD-147）。

### 9.2 对【AI】

| 承诺 | 结果 | 证据 |
|---|---|---|
| 可理解（网站结构/企业实体/内容关系/GEO 信息） | ✅ 满足 | S5：data-section/data-entity 语义属性 + JSON-LD 7 类 + llms.txt 双语 + geo.json + BreadcrumbList；S2 改公司名后四端同步 |
| 不破坏（不走 Blade/CSS/Schema/插 HTML） | ✅ 满足 | S2：全程走分层（Theme→Template→Page Composition），未改核心代码、未硬编码颜色、未手贴 JSON-LD、未破坏 GEO Semantic |

> 折扣项（Level 2）：转化目标路径因 localhost 死链不完整（TD-140）、geo.json description 与 JSON-LD 不一致（TD-148）、agent-guide 缺分层决策树（TD-149）。

### 9.3 对【开发者】

| 承诺 | 结果 | 证据 |
|---|---|---|
| 可扩展模板 | ⚠️ 部分满足 | S3：从零创建 --strict 通过的模板；但规范枚举缺失（TD-143）、Validator 不扫描违禁文件（TD-142） |
| 受约束（不破坏核心系统、安全边界清晰） | ✅ 满足 | S2/S3：全程未改核心代码；测试模板删除后原仓库 8 包不变；运行时不执行包内 PHP；SafeUrl scheme 白名单已落地（18L-4a TD-135） |

> 折扣项（Level 2）：TD-142（Validator 封闭）、TD-143（规范枚举缺失）。**封闭性裁定不动摇**：V1 不允许模板包携带可执行代码，"能力不足"只记为 SDK Enhancement。

---

## 10. TD 分类（严格三层）

当前台账最新 TD-139。本次 18N 新登记 **11 项**（TD-140 ~ TD-150）。

### Level 1 · Blocker（阻止进入 19A）

**无。** 逐项核查：
- 安装阻断：❌ 不存在（S1 全流程成功）
- 数据损坏风险：❌ 不存在（所有操作可恢复，发布/下架实测恢复）
- SEO·GEO 核心破坏：❌ 不存在（S5 信号齐全，模板切换后 GEO 自动跟随）
- AI 安全绕过：❌ 不存在（S2 全程走分层，未绕过 Registry/Validator）
- 模板安全风险：⚠️ Validator 不扫描违禁文件（TD-142），但运行时不执行包内可执行文件，非直接 RCE → 降为 Level 2

### Level 2 · Release Debt（进入 19A 前记录，不阻止）

| ID | 标题 | 优先级 | 来源 |
|---|---|---|---|
| **TD-140** | 种子/默认 CTA 按钮链接硬编码 `http://localhost/` 死链 | P2 | S4.1 |
| **TD-141** | 模板 bootstrap 只叠加不替换，连续切换致重复区块/撞 20 块上限 | P2 | S4.2 |
| **TD-142** | Template Validator 不强制 V1 文件类型封闭（不扫描 .php/.js/.blade） | P2 | S3.3 |
| **TD-143** | SDK 规范自带 manifest 示例不合规 + 缺枚举/block 目录/槽位映射 | P2 | S3.1/3.2 |
| **TD-144** | 默认管理员凭据与后台 URL 未文档化（/admin/login、邮箱、密码随机打印一次） | P3 | S1.1/1.2, S2.1 |
| **TD-145** | 首次启动无 onboarding 引导（仪表盘一排 0、无分步向导、模板/主题概念未解释） | P3 | S1 首次启动 |
| **TD-146** | 发布/下架端点语义不清 + 对草稿页 POST /unpublish 静默 200 no-op | P3 | S1.4 |
| **TD-147** | 多语言页面内容独立编辑模型未文档化（zh 编辑不同步 en） | P3 | S2.4, S4.4 |
| **TD-148** | geo.json site.description 为空而 JSON-LD Organization description 正常（不一致） | P3 | S2.5 |
| **TD-149** | agent-guide 缺分层决策树 + Component 层无独立运行时 UI | P3 | S2 分层反思 |
| **TD-150** | 后台「首页装修」菜单硬编码 zh-CN locale（en 管理员点入 zh composer） | P3 | R4 |

> 其余观察项（区块上限报错位置、删除无确认、主题双入口混淆、npm build 前置未强调、.env 示例矛盾、Validator 受控词表不列可选值、template:validate 无 --path）已记录在 §3/§7，不单独编号，可随相关 TD 一并修复。

### Level 3 · Future Evolution（v1.1/v2，不阻塞、不重开 18 阶段）

- Marketplace / 在线模板上传（TD-18 / TD-110）
- 在线主题安装 / ZIP / Theme SDK（TD-110）
- RBAC 三角色 + 站点成员（TD-14）
- Canvas 自由拖拽（TD-119 重定义后 v1.1）
- AI 自动建站助手（新概念，v2）
- 多 AI GEO 对拍（19A 后 CI 环境补做）
- Template Validator `--path=` 参数、受控词表错误补可选值（随 TD-142/143 增强）

---

## 11. Release Readiness

### READY FOR 19A

**Blocker 数量：0。** 六领域（安装体验/模板生态/AI 使用/运营体验/GEO 能力/安全边界）全部 PASS，最高等级均为 L2。三类用户 8 项核心承诺中 6 项完全满足、2 项部分满足（均为文档/校验缺口，非功能缺失）。11 项 Level 2 发布债务已登记 TD-140~149，不阻塞 19A。Level 3 演进项进 v1.1/v2，不重开 18L/18M，不扩大 v1.0 范围。

**下一步动作**：
1. 进入 19A Private GitHub + Cloud CI（用户裁定后）。
2. 19A 期间并行修复高优 Level 2：TD-140（localhost 死链）、TD-143（规范示例不合规）。
3. 19A CI 环境具备后补做多 AI GEO 对拍（S5 限制项）。

---

## 附录：Test Environment & 执行边界

### Test Environment

| 项 | 值 |
|---|---|
| OS | Windows 11 / PowerShell 5.1 |
| PHP | `C:\php84\php.exe` 8.4.25（cURL/FTS5/GD/mbstring/openssl 全可用） |
| Composer | 2.10.3 |
| Node / npm | v22.23.2 / 10.9.8 |
| Browser | Chrome（via browser-use-automation skill，in-app browser） |
| Database | SQLite 3.x（FTS5 可用）；全新空文件 + geo:install 迁移 |
| Fresh Install 方式 | robocopy 源码到临时目录 → composer install → New-Item 空 sqlite → php artisan geo:install → npm run build → php artisan serve |
| 仓库 | `D:\GEO-OS-rewrite\geo-website-os`（branch master，commit `a737abb`） |
| 场景 1 实例 | `D:\Temp\geo18n-s1`，serve `http://127.0.0.1:8181` |
| 场景 2 实例 | `D:\Temp\geo18n-s2`，serve `http://127.0.0.1:8182` |
| 场景 3 模板 | `D:\Temp\geo18n-s3\custom-template\cozy-cafe`（测试后删除，原仓库恢复 8 包） |
| 场景 4/5 实例 | 现有 demo `D:\Temp\geo4b1`，serve `http://127.0.0.1:8171` |

### 执行边界确认

- ✅ 未修改产品代码/模板包/主题（场景 3 的测试模板已删除，原仓库 8 包不变）
- ✅ 未配置 remote、未 push、未 Release、未移动 rc1 tag（v1.0.0-rc1=965d63c）
- ✅ 未引入 v1.1 能力（Marketplace/在线 zip/RBAC/自由画布）
- ✅ 业务污染扫描 13 词运行态全 0
- ✅ 所有结论来自真实执行（命令/HTTP/浏览器），无凭文档描述下结论
- ⚠️ 多 AI 对拍未执行（无外部 API），已用替代方案并记录限制
- ⚠️ XSS/URL injection 攻击测试依赖 18L-4a/18H-3 已落地证据，18N 未重复注入攻击；Template abuse 经 18N S3 实测

各场景子报告（完整执行日志）：
- `D:\73466\GEO官网\18n-scenario1-install.md`
- `D:\73466\GEO官网\18n-scenario2-ai-agent.md`
- `D:\73466\GEO官网\18n-scenario3-template-author.md`
- `D:\73466\GEO官网\18n-scenario45-ops-geo.md`

**P-STEP 18N Status: PASS / ACCEPTED**
**下一步：进入 19A Private GitHub + Cloud CI（用户裁定后）**
