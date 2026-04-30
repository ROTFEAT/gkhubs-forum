# gkhubs.com 聚合站重建 — MVP 设计文档

- **日期**：2026-04-30
- **作者**：站长 + Claude
- **目标读者**：本项目实施者（站长本人）
- **状态**：设计阶段（待用户最终批准）

---

## 1. 项目目标与定位

### 1.1 业务定位

把 gkhubs.com 重建为 **GK 手办圈的内容/聚合站**，类 hpoi。承担纯粹的**内容生产 + SEO + 流量入口**角色，给即将上线的独立 B2C 站导流。

老站完全弃用：URL 全换、数据全弃、SEO 不保留。

### 1.2 MVP 必须达成

1. 每天自动抓取 favorgk + orzgk 新品并入库
2. 抓回的图片自动去除原站水印
3. 跨店"同款"由 LLM 自动归并到同一个 Figure 主体
4. 前端展示 Figure 详情 + 多店比价
5. 移动端友好，活泼风格（视觉 MVP 用 hpoi 风格占位）

### 1.3 非目标（明确不在 MVP）

- ❌ 论坛 / 用户讨论 / UGC
- ❌ 用户账号 / 注册 / 登录
- ❌ 二手 / 线下交易 / 市场
- ❌ 直接卖货（B2C 在另一独立站）
- ❌ 老站数据迁移 / SEO 保留

---

## 2. 总体架构

5 个独立服务，**每个一个 Dockerfile，部署到 Dokploy（152.53.53.84），禁止 docker-compose**。

```
                 ┌─────────────────────────────────────────────────┐
                 │                  Cloudflare CDN                 │
                 └────────────────────┬────────────────────────────┘
                                      │
                            ┌─────────▼──────────┐
                            │   gkhubs-web       │   Next.js 15 (ISR)
                            │   (公网入口)        │
                            └─────────┬──────────┘
                                      │ REST
                                      ▼
                            ┌────────────────────┐
                            │   gkhubs-cms       │   Headless WordPress
                            │   (内网/管理子域)   │
                            └─────┬──────────┬───┘
                                  │          │
                            ┌─────▼────┐ ┌───▼─────────┐
                            │ MySQL 8  │ │ R2 (Media   │
                            │ (gkhubs- │ │ Cloud 插件) │
                            │  cms-db) │ └─────────────┘
                            └──────────┘
                                  ▲
                                  │ REST (App Password)
                ┌─────────────────┴────────────────┐
                │       gkhubs-crawler              │   Python CLI（单次运行）
                │  ┌──────────────────────────────┐ │
                │  │ 1. Spider: 抓 favorgk/orzgk   │ │
                │  │ 2. → POST 水印服务            │ │
                │  │ 3. → LLM 匹配 / 创建 Figure   │ │
                │  │ 4. → upsert 到 WP             │ │
                │  └──────────────────────────────┘ │
                └─────────────┬─────────────────────┘
                              │ REST
                              ▼
                    ┌───────────────────┐
                    │ gkhubs-watermark  │   IOPaint FastAPI
                    │ (内网)            │   LaMa, CPU
                    └───────────────────┘
```

### 服务清单

| # | 服务名 | 角色 | 端口 | 暴露 |
|---|--------|------|------|------|
| 1 | `gkhubs-web` | Next.js 前端 | 3000 | 公网（gkhubs.com） |
| 2 | `gkhubs-cms` | Headless WP（PHP-FPM + Nginx） | 80 | 公网子域（cms.gkhubs.com，仅 wp-admin + REST） |
| 3 | `gkhubs-cms-db` | MySQL 8 | 3306 | 仅内网 |
| 4 | `gkhubs-crawler` | Python CLI（单次运行） | — | 仅内网（无入站） |
| 5 | `gkhubs-watermark` | IOPaint FastAPI | 8080 | 仅内网 |

**爬虫调度**：用户外部触发（Dokploy schedule / 系统 cron / GitHub Actions），脚本本身不内置定时器、不挂队列。

---

## 3. 数据模型

### 3.1 实体关系

```
Figure  1 ──────── N  Listing
   │                     │
   │                     ├── shop (taxonomy: favorgk / orzgk / ...)
   │                     └── price_current, stock_status, fetched_at
   │
   ├── ip          (taxonomy)
   ├── character   (taxonomy)
   └── studio      (taxonomy)
```

**核心约束**：每个 `Listing` 必须归属 0 或 1 个 `Figure`（孤儿态合法，由后续匹配补全）；同一 `shop_listing_url` 全局唯一。

### 3.2 `gk_figure` CPT

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| title | string | Y | 标准化中文名 |
| slug | string | Y | URL 标识符 |
| ip | taxonomy | N | 原作 IP（如《鬼灭之刃》） |
| character | taxonomy | N | 角色（如炭治郎） |
| studio | taxonomy | N | 厂商/原型师（Megahouse / Kotobukiya / 个人原型师） |
| scale | enum | N | 1/4 / 1/6 / 1/7 / 1/8 / 1/12 / 等比 / Other |
| material | enum | N | PVC 完成品 / GK 套件 / 树脂 / 金属 / Mixed |
| release_date | date | N | 发售日期（年月精度即可） |
| msrp | number + currency | N | 原价 |
| cover_image | media | N | 封面（去水印后版本） |
| status | enum | Y | 预订 / 在售 / 绝版 / 未公布 |
| description | rich text | N | 正文 |
| canonical_listing_id | ref | N | 用作主图/主信息源的 Listing |

### 3.3 `gk_listing` CPT

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| title | string | Y | 该店原始商品标题 |
| shop | taxonomy | Y | favorgk / orzgk / ... |
| shop_listing_url | string (unique) | Y | 商品页 URL（全局唯一键） |
| price_current | number | Y | 当前价 |
| price_currency | enum | Y | CNY / JPY / USD |
| stock_status | enum | Y | in_stock / pre_order / out_of_stock / sold_out |
| listing_type | enum | Y | new / second_hand |
| ship_from | string | N | 发货地 |
| listing_image | media | Y | 该店商品图（去水印后） |
| fetched_at | datetime | Y | 最近一次抓取成功时间 |
| gk_figure_ref | post_relation | N | 关联到的 Figure（LLM 匹配产物） |
| match_confidence | number | N | LLM 匹配置信度 0–1（用于人工审核排序） |
| raw_payload | JSON text | N | 原始抓取 JSON（调试用，非展示） |

### 3.4 价格历史

`gk_price_history`（轻量子表 / 自定义表）：每次抓取若 `price_current` 变化则插入一条 `(listing_id, price, currency, observed_at)`。前端用于绘制价格曲线（Phase 2 可省）。

---

## 4. 服务详设

### 4.1 `gkhubs-web` (Next.js 15)

**技术选择**：

- Next.js 15 App Router + React Server Components
- TypeScript，Tailwind CSS，Shadcn UI 组件库
- ISR（增量静态再生）：Figure 详情页 60 分钟重建，列表页 15 分钟
- next/image 接 R2 域名做图片优化
- Framer Motion 用于卡片动画

**核心页面**（MVP）：

| 路由 | 类型 | 说明 |
|------|------|------|
| `/` | ISR 15 分钟 | 首页，最新 Figures + 即将发售 |
| `/figures` | ISR 15 分钟 | Figure 列表（瀑布流，按 release_date / fetched_at） |
| `/figures/[slug]` | ISR 60 分钟 | Figure 详情，含多店 Listing 比价表 + 价格趋势 |
| `/sources/[shop]` | ISR 15 分钟 | 单店最近上架 |
| `/search` | 客户端 | 简单 LIKE 搜索 |

**数据来源**：直接打 `gkhubs-cms` 的 WP REST API（`/wp-json/wp/v2/gk_figure?_embed=1`）。复杂查询走自建 `/wp-json/gkhubs/v1/figures-with-listings`（自定义 REST 端点，在 CMS 里写）。

**测试范围**：

- 组件单测：Vitest + Testing Library，关键卡片/表格组件 ≥ 70% 行覆盖
- E2E：Playwright，跑 3 条关键路径——首页加载、Figure 详情页加载、搜索

### 4.2 `gkhubs-cms` (Headless WordPress)

**镜像**：基于官方 `wordpress:6.x-php8.2-fpm` + 自带 Nginx。

**装好的插件**（构建时通过 wp-cli 装入镜像）：

- **Custom Post Type UI**（或代码注册）— 注册 `gk_figure` / `gk_listing`
- **ACF (Advanced Custom Fields)** — 字段定义
- **Media Cloud (ILAB)** — R2 offload，免费版支持自定义 S3 endpoint
- **Application Passwords**（WP 6+ 内置）— 爬虫认证

**自定义 REST 端点**（在主题 `functions.php` 或 mu-plugin 中）：

- `GET /wp-json/gkhubs/v1/figures-with-listings`
- `POST /wp-json/gkhubs/v1/upsert-listing`（带 App Password 认证，幂等；**幂等键 = `shop_listing_url`**，而不是 WP post id）
- `POST /wp-json/gkhubs/v1/upsert-figure`（幂等键 = `slug`）

> **App Password**：必须创建一个**专用 bot 用户**（如 `crawler-bot`，role=editor），不要用 admin 账号生成。CMS 引导步骤里会专门处理。

**数据持久化**：

- WP 数据库 → `gkhubs-cms-db`
- 媒体文件 → R2（通过 Media Cloud 自动）
- 仅 `wp-content/uploads/` 临时目录挂卷（offload 后不需保留）

**测试范围**：

- 镜像构建烟雾测试：`docker run`，POST 一条 listing，GET 验证写入成功
- 备份/恢复演练：DB dump → 新容器 import → REST 数据可读

### 4.3 `gkhubs-cms-db` (MySQL 8)

**镜像**：`mysql:8.0`。

- 持久卷 `/var/lib/mysql`
- 每天 `mysqldump` 到 R2（用单独脚本，可放进爬虫调度的同一节奏）
- 仅暴露给同 Dokploy 网络内的服务

**测试范围**：恢复演练（半年至少一次）。

### 4.4 `gkhubs-crawler` (Python CLI)

**入口**：`python -m crawler.run --source <name>`

**支持的命令**：

```
crawler.run --source favorgk       # 跑 favorgk 一轮
crawler.run --source orzgk         # 跑 orzgk 一轮
crawler.run --source all           # 全部源
crawler.run --rematch-orphans      # 仅给孤儿 Listing 跑 LLM 匹配
crawler.run --refresh-prices       # 不抓新品，只刷新已知 Listing 价格
```

**单次运行流程**（每个 source）：

```
1. fetch_new_listing_urls(source)
        ├── 翻 N 页 "newest" 列表
        └── 拿到 url_list
2. for each url:
        ├── parse_listing(url) → raw fields + image_urls
        ├── for each image:
        │       └── POST gkhubs-watermark/inpaint → cleaned image bytes
        ├── upload cleaned images to gkhubs-cms via WP REST media endpoint
        │       (Media Cloud 自动转推 R2)
        ├── llm_match(listing_data) → figure_id | NEW
        │       ├── 候选检索：title 模糊 + ip/character 在已存在 Figures
        │       ├── 候选 ≤ 5 → Claude Haiku 4.5 做最终判断
        │       └── 输出 (figure_id, confidence) | (None, "create_new")
        ├── if NEW:
        │       └── extract_canonical_fields(listing) → POST upsert-figure
        └── POST upsert-listing (含 figure_ref + confidence)
3. 输出 run summary（json，含成功/失败/孤儿数）
```

**可插拔架构**（保扩展性）：

```
crawler/
├── run.py                # CLI 入口，dispatch by --source
├── core/
│   ├── pipeline.py       # 通用流水线：parse → watermark → upload → match → upsert
│   ├── matcher.py        # LLM 匹配
│   ├── watermark_client.py
│   ├── wp_client.py
│   └── schemas.py        # pydantic 模型
└── spiders/
    ├── base.py           # SpiderBase 接口（抽象类）
    ├── favorgk.py
    └── orzgk.py
```

**新增源 = 加一个 `spiders/<name>.py` 实现 `SpiderBase`**：

```python
class SpiderBase(ABC):
    name: str
    @abstractmethod
    def fetch_new_listing_urls(self) -> list[str]: ...
    @abstractmethod
    def parse_listing(self, url: str) -> ListingDraft: ...
```

不需要改 `pipeline.py`，不需要改部署。

**LLM 匹配细节**：

- 模型：`claude-haiku-4-5-20251001`（成本低，质量足够 yes/no 判断）
- 单次输入：候选 Figures 摘要 + 当前 listing 摘要 + listing 图片 URL
- 输出 schema：`{action: "match"|"create_new", figure_id?: int, confidence: float, reasoning: string}`
- 启用 prompt caching（候选列表通常稳定，可复用）
- 月调用估算：100–500 次，<$1

> **冷启动行为**：首轮抓取时 Figure 表为空，所有 Listing 都会进入 `create_new` 分支并基于 listing 数据反向生成 Figure。这是预期行为，不是 bug，无需额外兜底逻辑。

**测试范围**：

- 单测：每个 spider 用 fixture HTML 跑 `parse_listing`，断言字段提取
- 单测：`matcher.py` 用 mock LLM 响应，验证决策树
- 集成测：跑 `--source favorgk` 顶到 staging WP（独立 wp 实例 + 独立 DB），验证 1 条 Listing + 1 条 Figure 写入成功

### 4.5 `gkhubs-watermark` (IOPaint)

**镜像**：基于 `python:3.11-slim`，pip 装 `iopaint`。下载 LaMa 模型权重打入镜像（~200MB）避免冷启动下载。

**接口**：

```
POST /inpaint
  body: multipart (image: bytes, mask?: bytes)
  response: { cleaned_image: base64, processing_ms: int }

GET /healthz
```

**Mask 策略**：

- 已知源（favorgk / orzgk）：水印位置固定 → 预生成 mask 模板，按图片尺寸缩放
- 未知源：用 OCR 自动检测水印文本区域（pytesseract），生成 mask
- 模板存在 `/app/masks/<source>.json`，新源加 mask 即可

> **样图未到时的开发顺序**：服务先用占位 mask（图片右下角 20% 区域）打通端到端；favorgk / orzgk 真实样图到位后，把 `masks/favorgk.json` `masks/orzgk.json` 替换即可，**无需重构服务**。即水印服务实现不阻塞在样图收集上。

**资源**：CPU only，2 vCPU + 2GB RAM 起步。延迟 5–20s/张可接受（爬虫是离线流程）。

**测试范围**：

- 单测：喂带 favorgk 水印的样图 + favorgk mask 模板 → 输出图 OCR 不再含 "favorgk" 关键词；SSIM > 0.85
- 单测：喂带 orzgk 水印的样图 → 同上
- 健康检查：CI 启容器 → curl /healthz 返回 200

---

## 5. 关键数据流

### 5.1 一次完整爬取（favorgk 新品）

```
[外部 cron 触发]
  └──> docker run gkhubs-crawler --source favorgk
        ├── (1) GET favorgk.com/collections/newest 翻页
        ├── (2) 提取 url_list (e.g. 30 条)
        ├── for each url:
        │     ├── (3) GET listing 页 → parse 字段 + image URLs
        │     ├── (4) 下载图片 bytes
        │     ├── (5) POST http://gkhubs-watermark:8080/inpaint
        │     │       (image + favorgk mask)
        │     │     → cleaned_image bytes
        │     ├── (6) POST http://gkhubs-cms/wp-json/wp/v2/media
        │     │       (App Password auth, multipart)
        │     │     → media_id, url (Media Cloud 已经把它推到 R2)
        │     ├── (7) 在 CMS 里搜候选 Figure (REST 查询)
        │     ├── (8) Claude API: 候选 + listing → match decision
        │     ├── (9) 若 NEW → POST upsert-figure
        │     └──(10) POST upsert-listing (含 figure_ref)
        └── 输出 summary JSON
```

### 5.2 价格刷新（每天）

```
docker run gkhubs-crawler --refresh-prices
  └── for each Listing (where shop in [favorgk, orzgk]):
        ├── GET listing url
        ├── parse 当前价 / stock_status
        ├── 若 price 变化 → INSERT gk_price_history + UPDATE listing
        ├── 若 HTTP 404（确认下架） → UPDATE listing.stock_status = sold_out
        └── 若网络错误 / 5xx / 解析失败 → 重试 ≤ 3 次（指数退避）；
            连续 3 天失败再标记 stock_status = unknown，不要直接 sold_out

> 区分"明确下架（404）"与"瞬时故障"是必要的——把瞬时错误也当下架会导致大批 Listing 被错误归档。
```

### 5.3 ISR 重建触发

- 默认：Next.js 按 revalidate 时间被动重建
- 选配：爬虫成功后调 `POST https://gkhubs.com/api/revalidate?path=/&secret=...` 主动让首页/相关 Figure 页失效

---

## 6. 部署拓扑（Dokploy on 152.53.53.84）

### 6.1 Dokploy 应用清单

| Dokploy App | 镜像来源 | 域名 | 计划资源 |
|-------------|----------|------|----------|
| `gkhubs-web` | Git (本仓库 `web/Dockerfile`) | gkhubs.com (Cloudflare) | 1 vCPU / 1GB |
| `gkhubs-cms` | Git (本仓库 `cms/Dockerfile`) | cms.gkhubs.com | 1 vCPU / 1GB |
| `gkhubs-cms-db` | docker hub `mysql:8.0` | 内网 only | 1 vCPU / 1GB / 持久卷 |
| `gkhubs-crawler` | Git (本仓库 `crawler/Dockerfile`) | 无入站 | 1 vCPU / 512MB（按需启动） |
| `gkhubs-watermark` | Git (本仓库 `watermark/Dockerfile`) | 内网 only | 2 vCPU / 2GB |

**部署触发**：Dokploy 内置 git auto-deploy。每个 Dokploy 应用绑定本仓库对应子目录，`git push` 后 Dokploy 自动拉取 → build（测试在 Dockerfile 内）→ rolling deploy。**不引入 GitHub Actions / 第三方 CI**。

### 6.2 仓库结构

```
GKHUBS-forum/
├── web/                  # Next.js 项目
│   └── Dockerfile
├── cms/                  # WP 自定义镜像
│   ├── Dockerfile
│   ├── plugins/          # 提交进仓库的 mu-plugin（自定义 REST 端点）
│   └── theme/            # 极简 headless 主题
├── crawler/              # Python CLI
│   └── Dockerfile
├── watermark/            # IOPaint 服务
│   ├── Dockerfile
│   └── masks/
└── docs/
    └── superpowers/
        └── specs/
            └── 2026-04-30-gkhubs-aggregation-mvp-design.md
```

### 6.3 网络与 DNS

- gkhubs.com → Cloudflare → `gkhubs-web:3000`
- cms.gkhubs.com → Cloudflare → `gkhubs-cms:80`（仅自己用，可设 Cloudflare Access 限 IP）
- 内部服务通过 Dokploy 默认 Docker 网络互访（用服务名当 hostname）

### 6.4 关键环境变量

| 服务 | 变量 |
|------|------|
| `gkhubs-web` | `WP_BASE_URL=http://gkhubs-cms`, `REVALIDATE_SECRET=...` |
| `gkhubs-cms` | `WORDPRESS_DB_HOST=gkhubs-cms-db`, `R2_ACCESS_KEY=...`, `R2_SECRET=...`, `R2_BUCKET=...`, `R2_ENDPOINT=...` |
| `gkhubs-crawler` | `WP_BASE_URL=http://gkhubs-cms`, `WP_APP_PASSWORD=...`, `WATERMARK_URL=http://gkhubs-watermark:8080`, `ANTHROPIC_API_KEY=...` |
| `gkhubs-watermark` | `IOPAINT_MODEL=lama`, `IOPAINT_DEVICE=cpu` |

---

## 7. 测试策略总览

| 服务 | 单测 | 集成测 | E2E |
|------|------|--------|-----|
| web | Vitest（组件） | API 端点 mock | Playwright × 3 关键路径 |
| cms | — | 镜像启动后 REST CRUD 烟雾测 | — |
| cms-db | — | 备份恢复演练 | — |
| crawler | pytest（spider 解析、matcher 决策） | 跑 1 条到 staging WP | — |
| watermark | pytest（OCR + SSIM 校验） | curl /healthz | — |

**部署/质量门**：不引入独立 CI（不用 GitHub Actions 等）。**测试集成进 Dockerfile 多阶段构建**——`test` 阶段失败 → 镜像构建失败 → Dokploy 自动 deploy 失败。`git push` 由 Dokploy 内置 git auto-deploy 触发。

```Dockerfile
# 模板（每个服务套用）
FROM <base> AS builder
COPY . .
RUN <install deps>

FROM builder AS test
RUN <run tests>            # 失败即整个 build 失败

FROM <base> AS runtime     # 不依赖 test 阶段，但 BuildKit 默认仍会执行
COPY --from=builder /app /app
CMD ["..."]
```

构建命令需带 `--target test` 让 test 阶段实际执行，或用 BuildKit 的 `RUN --mount` + 默认目标依赖；具体语法在每个服务的 Dockerfile 里固化。

---

## 8. 风险与缓解

| 风险 | 影响 | 缓解 |
|------|------|------|
| 源站反爬升级 | 爬虫挂掉 | 每个 spider 独立熔断；失败时发钉钉，不阻塞其他源 |
| LLM 误匹配 | 错误聚合 | `match_confidence` 字段记录；CMS 后台加"低置信度待审"列表，人工复核（这是站长唯一定期手工活） |
| 水印模型对未知水印效果差 | 图片仍带水印 | 仅 MVP 两源都用预定义 mask；新源接入时同步配置 mask 模板 |
| R2 Media Cloud 插件兼容性 | 上传失败 | 部署前镜像里跑一次烟雾测；备选回退 Backblaze B2 + WP Offload Media Lite |
| WP 升级破坏自定义 REST 端点 | API 不可用 | 锁版本（`wordpress:6.5-php8.2-fpm`），CI 跑兼容性烟雾测 |
| Dokploy 单机故障 | 全站宕机 | MVP 接受单机风险；DB 每日备份到 R2；恢复演练每季度一次 |

---

## 9. 不在 MVP 但已预留的扩展点

| 后期功能 | 当前预留 |
|----------|----------|
| 加新抓取源 | `SpiderBase` 抽象类 + `--source` 参数 |
| 多语言 / 国际化 | Next.js i18n 路由结构留口（暂只 zh-CN） |
| 用户账号 / 收藏 | 后期接入独立 auth 服务（Supabase / 自建），不污染 MVP |
| 论坛 | 独立子域 + 独立 Dokploy app（不动现有 5 个） |
| 价格曲线 | `gk_price_history` 表 MVP 已建，前端图表后期加 |
| AI 摘要/标签 | `crawler.matcher` 已用 LLM，后期同管线扩展 |

---

## 10. 待用户确认 / Open Questions

1. **WP 镜像策略**：用 `wordpress:6.x-php8.2-fpm` + 自带 Nginx 装到一个镜像；还是 `wordpress:fpm` + 独立 Nginx 容器？
   - 推荐前者：单容器单 Dockerfile，更符合"5 个独立 Dockerfile"约束。
2. **域名 / DNS**：cms 子域是否要走 Cloudflare Access（限你的 IP）？还是 wp-admin 走内网+SSH 隧道？
3. ~~CI/CD~~ **已定**：不上独立 CI；Dokploy 内置 git auto-deploy 触发 build；测试嵌入 Dockerfile 多阶段构建做质量门。
4. **monitoring**：是否要加 Sentry / Uptime Kuma？还是先用 Dokploy 自带日志 + 钉钉告警足够？
5. **首批 mask 模板**：favorgk / orzgk 的水印位置我需要你提供 1–2 张样图（爬虫开发时再要也行）。

---

## 11. 实施分期建议

**Phase 0（本 spec）**：设计 + 评审 + 写实施 plan
**Phase 1**：CMS + DB（让数据层先跑起来）
**Phase 2**：水印服务（独立可测）
**Phase 3**：Crawler（接入水印 + WP）
**Phase 4**：Web 前端（接入 CMS API）
**Phase 5**：CI / 监控 / 上线
