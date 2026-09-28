# P-STEP 20A-FBS — Feature Smoke Test Matrix

- **日期**：2026-09-28
- **HEAD**：`e98a803`
- **权威仓**：`D:\GEO-OS-rewrite\geo-website-os`
- **说明**：逐项编号 FT-001 起，每个写操作四面对拍（UI/HTTP/DB/前台）。状态：PASS / FAIL / BLOCKED / NOT-COVERED。

## Feature Inventory 汇总

| 来源 | 数量 |
|---|---|
| 路由总数 | 194（Admin 142 + Frontend 47 + API 5） |
| 后台导航项 | 30（8 直达 + 22 分组链接） |
| 测试功能项（FT） | 190（Admin 161 + Frontend 29） |
| 前台页面类型 | 18 类 |
| Entity 类型 | 8 种 |
| 后台设置组 | 8 组 |

---

## 一、Dashboard / Auth（FT-001 ~ FT-005）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-001 | Dashboard | 统计面板 | GET /admin/ | 查看统计数字 | 与 DB counts 一致 |
| FT-002 | Auth | 登录 | GET/POST /admin/login | 输入凭据登录 | 302→/admin/ |
| FT-003 | Auth | 退出 | POST /admin/logout | 点击退出 | 302→login |
| FT-004 | Auth | 修改密码 | GET/PUT /admin/password | 改密码 | 成功，新密码可登录 |
| FT-005 | Dashboard | 查看官网链接 | /admin/ topbar | 点击"查看官网" | 新窗口打开前台 |

## 二、Setup Wizard（FT-006 ~ FT-012）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-006 | Wizard | 访问向导 | GET /admin/wizard | 查看 step1 | 200 显示基础信息表单 |
| FT-007 | Wizard | Step1 保存 | POST /admin/wizard/1 | 填企业信息 | 302→step2，site updated |
| FT-008 | Wizard | Step2 品牌 | POST /admin/wizard/2 | 填品牌信息 | 302→step3 |
| FT-009 | Wizard | Step3 产品（中文 slug） | POST /admin/wizard/3 | 建中文产品 | slug 非空 hash 回退 |
| FT-010 | Wizard | Step4 关系 | POST /admin/wizard/4 | 建关系 | 302→step5 |
| FT-011 | Wizard | Step5 模板 | POST /admin/wizard/5 | 选模板 | 302→step6 |
| FT-012 | Wizard | Step6 完成 | POST /admin/wizard/6 | 发布检查 | 302→/admin，wizard_completed |

## 三、Site 管理（FT-013 ~ FT-019）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-013 | Sites | 列表 | GET /admin/sites | 查看站点列表 | 200 列出所有站点 |
| FT-014 | Sites | 创建 | GET/POST /admin/sites | 新建站点 | 302，site created |
| FT-015 | Sites | 编辑 | GET /admin/sites/{id}/edit | 查看编辑表单 | 200 |
| FT-016 | Sites | 更新 | PUT /admin/sites/{id} | 改站点信息 | 302，updated |
| FT-017 | Sites | 删除 | DELETE /admin/sites/{id} | 删除站点 | 302，deleted |
| FT-018 | Sites | 切换 | POST /admin/sites/switch | 切换管理站点 | 302，session updated |
| FT-019 | Sites | 设默认 | POST /admin/sites/{id}/make-default | 设默认站 | 302 |

## 四、Content 管理（FT-020 ~ FT-030）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-020 | Content | 列表 | GET /admin/contents/article | 查看文章列表 | 200 |
| FT-021 | Content | 创建表单 | GET /admin/contents/create/article | 查看创建表单 | 200 |
| FT-022 | Content | 保存草稿 | POST /admin/contents | 填内容保存 | 302，draft created |
| FT-023 | Content | 编辑表单 | GET /admin/contents/{id}/edit | 查看编辑表单 | 200 |
| FT-024 | Content | 更新 | PUT /admin/contents/{id} | 改内容 | 302，updated |
| FT-025 | Content | 删除 | DELETE /admin/contents/{id} | 删除（软删） | 302，deleted_at set |
| FT-026 | Content | 发布 | POST /admin/contents/{id}/publish | 发布 | 302，status=published |
| FT-027 | Content | 下线 | POST /admin/contents/{id}/unpublish | 下线 | 302，status=draft |
| FT-028 | Content | 门禁检查 | POST /admin/contents/check | 检查内容完整性 | JSON 返回检查结果 |
| FT-029 | Content | Markdown 预览 | POST /admin/contents/md-preview | 预览 MD | JSON 返回 HTML |
| FT-030 | Content | 版本历史 | GET /admin/contents/{id}/revisions | 查看修订 | 200 列出 revisions |

## 五、Entity 管理（FT-031 ~ FT-045）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-031 | Entity | 列表 | GET /admin/entities | 查看实体列表 | 200 |
| FT-032 | Entity | 创建 Organization | GET /admin/entities/create/organization | 查看表单 | 200 |
| FT-033 | Entity | 创建 Product | GET /admin/entities/create/product | 查看表单 | 200 |
| FT-034 | Entity | 创建 Service | GET /admin/entities/create/service | 查看表单 | 200 |
| FT-035 | Entity | 创建 Person | GET /admin/entities/create/person | 查看表单 | 200 |
| FT-036 | Entity | 创建 Location | GET /admin/entities/create/location | 查看表单 | 200 |
| FT-037 | Entity | 创建 Topic | GET /admin/entities/create/topic | 查看表单 | 200 |
| FT-038 | Entity | 创建 CaseStudy | GET /admin/entities/create/case_study | 查看表单 | 200 |
| FT-039 | Entity | 创建 DownloadAsset | GET /admin/entities/create/download_asset | 查看表单 | 200 |
| FT-040 | Entity | 保存 | POST /admin/entities | 填实体保存 | 302，created |
| FT-041 | Entity | 编辑 | GET /admin/entities/{id}/edit | 查看编辑表单 | 200 |
| FT-042 | Entity | 更新 | PUT /admin/entities/{id} | 改实体 | 302，updated |
| FT-043 | Entity | 删除 | DELETE /admin/entities/{id} | 删除 | 302，deleted + cascade |
| FT-044 | Entity | 发布/下线 | POST /admin/entities/{id}/publish,unpublish | 状态切换 | 302，status changed |
| FT-045 | Entity | 示例种子 | POST /admin/entities/seed-examples | 生成示例 | 302，examples created |

## 六、Entity Relation（FT-046 ~ FT-051）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-046 | Relation | 列表 | GET /admin/relations | 查看关系列表 | 200 |
| FT-047 | Relation | 创建表单 | GET /admin/relations/create | 查看表单 | 200 |
| FT-048 | Relation | 保存 | POST /admin/relations | 建关系 | 302，created |
| FT-049 | Relation | 编辑 | GET /admin/relations/{id}/edit | 查看表单 | 200 |
| FT-050 | Relation | 更新 | PUT /admin/relations/{id} | 改关系 | 302 |
| FT-051 | Relation | 删除 | DELETE /admin/relations/{id} | 删除 | 302 |

## 七、SEO Meta（FT-052 ~ FT-057）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-052 | SEO | 列表 | GET /admin/seo-metas/all | 查看所有 SEO | 200 |
| FT-053 | SEO | 创建表单 | GET /admin/seo-metas/create | 查看表单 | 200 |
| FT-054 | SEO | 保存 | POST /admin/seo-metas | 保存 SEO | 302 |
| FT-055 | SEO | 编辑 | GET /admin/seo-metas/{id}/edit | 查看表单 | 200 |
| FT-056 | SEO | 更新 | PUT /admin/seo-metas/{id} | 改 SEO | 302 |
| FT-057 | SEO | 删除 | DELETE /admin/seo-metas/{id} | 删除 | 302 |

## 八、Narrative（FT-058 ~ FT-061）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-058 | Narrative | 列表 | GET /admin/narrative | 查看文案列表 | 200 |
| FT-059 | Narrative | 编辑 | GET /admin/narrative/{key}/edit | 查看编辑表单 | 200 |
| FT-060 | Narrative | 更新 | PUT /admin/narrative/{key} | 改文案 | 302 |
| FT-061 | Narrative | 重置 | DELETE /admin/narrative/{key} | 恢复默认 | 302 |

## 九、Category / Group（FT-062 ~ FT-073）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-062 | Category | 列表 | GET /admin/categories | 查看栏目 | 200 |
| FT-063 | Category | 创建 | GET/POST /admin/categories | 建栏目 | 302 |
| FT-064 | Category | 编辑 | GET /admin/categories/{id}/edit | 查看表单 | 200 |
| FT-065 | Category | 更新 | PUT /admin/categories/{id} | 改栏目 | 302 |
| FT-066 | Category | 删除 | DELETE /admin/categories/{id} | 删除 | 302 |
| FT-067 | Category | 启停 | POST /admin/categories/{id}/toggle | 切换状态 | 302 |
| FT-068 | Group | 列表 | GET /admin/groups | 查看分组 | 200 |
| FT-069 | Group | 创建 | POST /admin/groups | 建分组 | 302 |
| FT-070 | Group | 更新 | PUT /admin/groups/{id} | 改分组 | 302 |
| FT-071 | Group | 删除 | DELETE /admin/groups/{id} | 删除 | 302 |
| FT-072 | Blocks | 首页装修入口 | GET /admin/blocks | 查看 | 200/redirect |
| FT-073 | Narrative | 快速验证 | — | 验证 Narrative 持久化 | 保存后前台更新 |

## 十、Menu（FT-074 ~ FT-079）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-074 | Menu | 列表 | GET /admin/menus | 查看菜单 | 200 |
| FT-075 | Menu | 创建/保存 | POST /admin/menus | 建菜单项 | 302 |
| FT-076 | Menu | 更新 | PUT /admin/menus/{id} | 改菜单 | 302 |
| FT-077 | Menu | 删除 | DELETE /admin/menus/{id} | 删除 | 302 |
| FT-078 | Menu | 覆盖 | POST /admin/menus/override | 改名/排序/显隐 | 302 |
| FT-079 | Menu | 重置覆盖 | DELETE /admin/menus/override/{key} | 恢复默认 | 302 |

## 十一、Page 管理（FT-080 ~ FT-097）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-080 | Page | 列表 | GET /admin/pages | 查看页面 | 200 |
| FT-081 | Page | 创建表单 | GET /admin/pages/create | 查看表单 | 200 |
| FT-082 | Page | 保存 | POST /admin/pages | 建页面 | 302 |
| FT-083 | Page | 编辑 | GET /admin/pages/{id}/edit | 查看表单 | 200 |
| FT-084 | Page | 更新 | PUT /admin/pages/{id} | 改页面 | 302 |
| FT-085 | Page | 删除 | DELETE /admin/pages/{id} | 删除 | 302 |
| FT-086 | Page | 发布/下线 | POST /admin/pages/{id}/publish/{action} | 状态切换 | 302 |
| FT-087 | Page | Composer | GET /admin/pages/{id}/composer | 可视化编辑 | 200 |
| FT-088 | Page | 预览 | GET /admin/pages/{id}/preview | 预览 | 200 |
| FT-089 | Page | 添加 Block | GET/POST /admin/pages/{id}/blocks/add | 加区块 | 302 |
| FT-090 | Page | 编辑 Block | GET/PUT /admin/pages/{id}/blocks/{block} | 改区块 | 302 |
| FT-091 | Page | 删除 Block | DELETE /admin/pages/{id}/blocks/{block} | 删区块 | 302 |
| FT-092 | Page | 移动 Block | POST /admin/pages/{id}/blocks/{block}/move/{dir} | 排序 | 302 |
| FT-093 | Page | 启停 Block | POST /admin/pages/{id}/blocks/{block}/toggle | 切换 | 302 |
| FT-094 | Page | 变体 | POST /admin/pages/{id}/blocks/{block}/variant | 切变体 | 302 |
| FT-095 | Page | 复制 Block | POST /admin/pages/{id}/blocks/{block}/duplicate | 复制 | 302 |
| FT-096 | Page | Page≠Content 验证 | — | 确认 Page 独立 | 仅 Page 模型 |
| FT-097 | Page | 模板切换对拍 | — | A→B→A 切换 | 内容/SEO/GEO 不丢 |

## 十二、Media（FT-098 ~ FT-102）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-098 | Media | 列表 | GET /admin/media | 查看媒体库 | 200 |
| FT-099 | Media | 上传 | POST /admin/media | 上传文件 | 302，file stored |
| FT-100 | Media | 内联上传 | POST /admin/media/inline | MD 编辑器上传 | JSON 返回 URL |
| FT-101 | Media | 更新 | PUT /admin/media/{id} | 改 alt/name | 302 |
| FT-102 | Media | 删除（守卫） | DELETE /admin/media/{id} | 有引用拒/无引用删 | 302 + Audit |

## 十三、Fact（FT-103 ~ FT-108）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-103 | Fact | 列表 | GET /admin/facts | 查看事实 | 200 |
| FT-104 | Fact | 创建 | GET/POST /admin/facts | 建事实 | 302 |
| FT-105 | Fact | 编辑 | GET /admin/facts/{id}/edit | 查看表单 | 200 |
| FT-106 | Fact | 更新 | PUT /admin/facts/{id} | 改事实 | 302 |
| FT-107 | Fact | 删除 | DELETE /admin/facts/{id} | 删除 | 302 |
| FT-108 | Fact | 前台验证 | — | 确认 fact 影响前台 | 前台更新 |

## 十四、Inquiry / Form（FT-109 ~ FT-122）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-109 | Inquiry | 列表 | GET /admin/inquiries | 查看留言 | 200 |
| FT-110 | Inquiry | 处理 | PUT /admin/inquiries/{id} | 改状态 | 302 |
| FT-111 | Inquiry | 删除 | DELETE /admin/inquiries/{id} | 删除 | 302 |
| FT-112 | Form | 列表 | GET /admin/forms | 查看表单 | 200 |
| FT-113 | Form | 提交列表 | GET /admin/forms/submissions | 查看提交 | 200 |
| FT-114 | Form | 创建 | GET/POST /admin/forms | 建表单 | 302 |
| FT-115 | Form | 编辑 | GET/PUT /admin/forms/{id} | 改表单 | 302 |
| FT-116 | Form | 删除 | DELETE /admin/forms/{id} | 删除 | 302 |
| FT-117 | Form | 启停 | POST /admin/forms/{id}/toggle | 切换 | 302 |
| FT-118 | Form | 表单提交详情 | GET /admin/forms/{id}/submissions | 查看提交 | 200 |
| FT-119 | Form | 字段创建 | GET/POST /admin/forms/{id}/fields | 加字段 | 302 |
| FT-120 | Form | 字段编辑 | GET/PUT /admin/forms/{id}/fields/{field} | 改字段 | 302 |
| FT-121 | Form | 字段删除 | DELETE /admin/forms/{id}/fields/{field} | 删字段 | 302 |
| FT-122 | Form | 前台提交 | POST /forms/{slug}/submit | 访客提交 | 302 |

## 十五、Redirect（FT-123 ~ FT-126）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-123 | Redirect | 列表 | GET /admin/redirects | 查看跳转 | 200 |
| FT-124 | Redirect | 创建 | POST /admin/redirects | 建跳转 | 302 |
| FT-125 | Redirect | 更新 | PUT /admin/redirects/{id} | 改跳转 | 302 |
| FT-126 | Redirect | 删除 | DELETE /admin/redirects/{id} | 删除 | 302 |

## 十六、Theme / Template / Plugin（FT-127 ~ FT-139）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-127 | Theme | 列表 | GET /admin/themes | 查看主题 | 200 |
| FT-128 | Theme | 激活 | POST /admin/themes/{name}/activate | 切主题 | 302 |
| FT-129 | Theme | 预览 | GET /admin/themes/{name}/preview | 预览 | 200 |
| FT-130 | Template | 列表 | GET /admin/templates | 查看模板 | 200 |
| FT-131 | Template | 激活 | POST /admin/templates/{pack}/activate | 切模板 | 302 |
| FT-132 | Template | 停用 | POST /admin/templates/deactivate | 停用 | 302 |
| FT-133 | Template | Bootstrap | POST /admin/templates/{pack}/bootstrap | 初始化 | 302 |
| FT-134 | Template | 预览 | GET /admin/templates/{pack}/preview | 预览 | 200 |
| FT-135 | Template | 截图 | GET /admin/templates/{pack}/preview/{view} | 截图 | 200 |
| FT-136 | Template | 对比 | GET /admin/templates/{pack}/compare | 对比 | 200 |
| FT-137 | Plugin | 列表 | GET /admin/plugins | 查看插件 | 200 |
| FT-138 | Plugin | 启用 | POST /admin/plugins/{slug}/enable | 启用 | 302 |
| FT-139 | Plugin | 停用 | POST /admin/plugins/{slug}/disable | 停用 | 302 |

## 十七、Settings（FT-140 ~ FT-150）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-140 | Setting | 公司信息 | GET /admin/settings/general | 查看 | 200 |
| FT-141 | Setting | 联系方式 | GET /admin/settings/contact | 查看 | 200 |
| FT-142 | Setting | 全站文案 | GET /admin/settings/copy | 查看 | 200 |
| FT-143 | Setting | 外观主题 | GET /admin/settings/theme | 查看 | 200 |
| FT-144 | Setting | SEO 设置 | GET /admin/settings/seo | 查看 | 200 |
| FT-145 | Setting | GEO 设置 | GET /admin/settings/geo | 查看 | 200 |
| FT-146 | Setting | Analytics | GET /admin/settings/analytics | 查看 | 200 |
| FT-147 | Setting | Sync | GET /admin/settings/sync | 查看 | 200 |
| FT-148 | Setting | 更新 | PUT /admin/settings/{group} | 保存设置 | 302 |
| FT-149 | Setting | 预设 | POST /admin/settings/theme/preset | 应用预设 | 302 |
| FT-150 | Setting | 重新生成 Token | POST /admin/settings/sync/regenerate-token | 新 token | 302 |

## 十八、GEO 工具（FT-151 ~ FT-155）

| ID | 模块 | 功能 | 入口 | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-151 | GEO | 工具 | GET /admin/geo/tools | 查看工具 | 200 |
| FT-152 | GEO | 预览 | GET /admin/geo/preview/{kind} | 预览产出 | 200 |
| FT-153 | GEO | 同步日志 | GET /admin/geo/sync-logs | 查看日志 | 200 |
| FT-154 | GEO | Health | GET /admin/geo/health | 查看健康 | 200 |
| FT-155 | GEO | Coverage | GET /admin/geo/coverage | 查看覆盖 | 200 |

## 十九、Frontend 页面（FT-156 ~ FT-184）

| ID | 模块 | 功能 | URL | 操作 | 预期 |
|---|---|---|---|---|---|
| FT-156 | Frontend | 首页 | / | GET | 200 |
| FT-157 | Frontend | 产品列表 | /products/ | GET | 200 |
| FT-158 | Frontend | 产品详情 | /products/{slug} | GET | 200 |
| FT-159 | Frontend | 方案列表 | /solutions/ | GET | 200 |
| FT-160 | Frontend | 方案详情 | /solutions/{slug}/ | GET | 200 |
| FT-161 | Frontend | 案例列表 | /cases/ | GET | 200/404 |
| FT-162 | Frontend | 案例详情 | /cases/{slug} | GET | 200/404 |
| FT-163 | Frontend | 工厂 | /factory/ | GET | 200 |
| FT-164 | Frontend | 合作 | /cooperation/ | GET | 200 |
| FT-165 | Frontend | 知识列表 | /knowledge/ | GET | 200 |
| FT-166 | Frontend | 知识频道 | /knowledge/{channel}/ | GET | 200 |
| FT-167 | Frontend | 文章详情 | /knowledge/{slug} | GET | 200 |
| FT-168 | Frontend | 关于-简介 | /about/profile/ | GET | 200 |
| FT-169 | Frontend | 关于-历史 | /about/history/ | GET | 200 |
| FT-170 | Frontend | 关于-文化 | /about/culture/ | GET | 200 |
| FT-171 | Frontend | 联系 | /contact/ | GET | 200 |
| FT-172 | Frontend | 搜索 | /search?q= | GET | 200 |
| FT-173 | Frontend | Sitemap | /sitemap.xml | GET | 200 |
| FT-174 | Frontend | llms.txt | /llms.txt | GET | 200 |
| FT-175 | Frontend | Feed | /feed.xml | GET | 200 |
| FT-176 | Frontend | geo.json | /geo.json | GET | 200 |
| FT-177 | Frontend | robots.txt | /robots.txt | GET | 200 |
| FT-178 | Frontend | 旧场景重定向 | /scenarios/ | GET | 301 |
| FT-179 | Frontend | 404 | /nonexistent | GET | 404 |
| FT-180 | Frontend | 语言切换 | /en/ | GET | 200 |
| FT-181 | Frontend | 主题切换 | 客户端 toggle | Light/Dark | 不破坏内容 |
| FT-182 | Frontend | 留言提交 | POST /inquiry | 真实填写 | 302 |
| FT-183 | Frontend | 表单提交 | POST /forms/{slug}/submit | 真实填写 | 302 |
| FT-184 | Frontend | Catch-all 页面 | /{path} | GET | 200/404 |

## 二十、Golden User Journey（FT-185 ~ FT-190）

| ID | 模块 | 功能 | 操作 | 预期 |
|---|---|---|---|---|
| FT-185 | Golden | 完整建站 | 安装→初始化→Organization | 全流程 |
| FT-186 | Golden | 业务实体 | Product/Service/Case/Media | 全部创建发布 |
| FT-187 | Golden | Page/Template/Composition | 建 Page→选模板→配 Block | 前台可访问 |
| FT-188 | Golden | 前台浏览+搜索+Inquiry | 访客全链路 | 全部 200，inquiry 提交 |
| FT-189 | Golden | 后台验证 | Health/Coverage/English/Theme | 全部正确 |
| FT-190 | Golden | 编辑→Cache→Unpublish→验证 | 改内容→确认缓存→下线 | 即时更新，无旧壳 |

---

## 执行批次分配

| 批次 | 覆盖 FT | 临时库 | 端口 |
|---|---|---|---|
| Batch 1 Entity/Content/Category | FT-020~071 | 20fbs_1.sqlite | 8160 |
| Batch 2 Page/Template/Media | FT-080~108 | 20fbs_2.sqlite | 8161 |
| Batch 3 Frontend/Menu/Setting | FT-001~019,074~079,140~155 | 20fbs_3.sqlite | 8162 |
| Batch 4 Inquiry/Search/SEO/GEO | FT-109~139,156~184 | 20fbs_4.sqlite | 8163 |
| Batch 5 Golden Journey | FT-185~190 | 20fbs_5.sqlite | 8164 |
