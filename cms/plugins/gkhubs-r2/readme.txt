=== gkhubs R2 Offload ===

把 WordPress 媒体卸载到 Cloudflare R2（S3 兼容），含批量迁移、URL 改写、admin 设置页。零外部依赖（手写 SigV4，不引入 aws-sdk-php）。

== 架构 ==

```
gkhubs-r2/
├── gkhubs-r2.php           主入口（WP plugin header + bootstrap）
├── includes/
│   ├── class-plugin.php    Bootstrap：把 5 个 sub-controller 串起来
│   ├── class-client.php    R2/S3 SigV4 客户端（PUT/GET/HEAD/DELETE/LIST）
│   ├── class-settings.php  Admin 页：Settings → R2 Offload + 测试连接
│   ├── class-uploader.php  上传 hooks：wp_handle_upload + 子尺寸 + 删除
│   ├── class-rewriter.php  URL 改写：guid / source_url / srcset / the_content
│   └── class-migrator.php  批量迁移：每分钟一批的 cron + 立即一批的 admin button
└── readme.txt              本文档
```

== 扩展点（freemium 用）==

下列 hook 让"Pro 版插件"能在不改本插件源码的前提下加功能（参考 WP Offload Media 模型）：

= Filters =
- `gkhubs_r2_object_key($key, $local_path)` — 自定义 R2 对象 key（自定义命名规则、按月分桶等）
- `gkhubs_r2_public_url($url, $key, $cfg)` — 自定义对外公网 URL（签名 URL、自定义 CDN 域名等）

= Actions =
- `gkhubs_r2_before_upload($local_path, $key, $upload)` — 上传前 hook（图片优化、watermark、lazy 转 webp 等）
- `gkhubs_r2_after_upload($key, $local_path, $upload)` — 上传后 hook（CDN purge、telemetry、license 计数等）

= 设置扩展 =
- 加 admin 字段：`add_filter('admin_init', ...)` + `add_settings_field(... 'gkhubs_r2_settings', 'pro_section')`
- 加测试连接子检查：`add_action('admin_post_gkhubs_r2_test', ..., 11)` 在 free 之后再跑

== 当前限制（适合做 Pro 卖点）==

| 功能 | Free | Pro 候选 |
|------|------|---------|
| 单 bucket | ✅ | 多 bucket 路由（按文章类型/作者/MIME） |
| 一次性批量迁移 | ✅ | 增量同步、双向同步、定时校验 |
| 公网 URL（PutObject）| ✅ | 签名 URL（私有 bucket + 时效 token） |
| HTTP/HTTPS URL 改写 | ✅ | CDN 智能选择、多区域 failover |
| WP 管理页 | ✅ | 总览仪表板（带宽、对象数、按月图表）|
| 全站删除时清 R2 | ✅ | 软删除 + 回收站、版本化、跨账号备份 |
| 删本地开关 | ✅ | 智能保留策略（按访问频次） |
| — | — | 图片即时变换（resize/format/quality 通过 URL 参数）|
| — | — | 优先级技术支持 + license + auto-update channel |

== 跨域 / 公开访问注意 ==

R2 桶**默认不允许匿名公网读**。要让前端 `<img src="...r2.cloudflarestorage.com/...">` 真的能加载，需以下其一：
1. R2 控制台 → bucket → Settings → "Public access" 开 Allow Access，使用得到的 `pub-*.r2.dev` URL
2. R2 控制台 → bucket → Settings → "Custom domains" 绑自定义域名（如 `media.gkhubs.com`）
3. Pro 候选：通过 Cloudflare Worker 出签名 URL（不开公开访问也能受控读）

把得到的公网基址填到 admin 页 "Public URL Base" 字段，插件即把所有 attachment URL 改写到这个基址。
