=== WP Large Content Optimizer ===
Contributors: yidianyouhua
Tags: performance, database, cleanup, optimization, large site, wordpress admin, postmeta
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 3.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Author: 一点优化
Author URI: https://www.seoyh.net

针对文章量大、采集站、后台文章列表变慢、数据库垃圾数据膨胀等问题的 WordPress 大站性能优化工具。

== Description ==

WP Large Content Optimizer 面向文章量较大的 WordPress 网站，特别是采集站、内容站、资讯站、SEO 站点。

插件默认以检测和建议为主，所有清理、加索引、移动文章等操作均需要管理员手动确认。

主要能力：

* 数据库体检与性能评分
* 安全优化向导
* 数据表大小 TOP
* postmeta 热点字段 TOP
* autoload 大对象分析
* 分批清理 revision、auto-draft、trash、失效 postmeta、失效分类关系、过期 transient
* 推荐数据库索引检测与手动添加
* 后台文章列表轻量化
* 后台大列表快速模式
* 采集站专项体检
* 重复草稿安全移动到回收站
* 已发布重复文章审查器
* 前台性能与缓存检测
* 数据库慢查询风险分析
* 数据库维护日志
* WP-Cron 与采集任务检测
* 后台文章列表查询缓存
* 后台列表 AJAX 延迟统计
* postmeta 深度治理
* autoload 优化器
* 轻量页面缓存（默认关闭）
* Redis/Object Cache 深度检测
* REST/XML-RPC/feed 轻量控制
* 趋势记录清空工具
* 媒体库问题附件样本审查
* Cron Hook 暂停/恢复与按 Hook 删除计划事件
* 页面缓存手动预热与排除规则
* Cron Hook 来源/回调识别
* 诊断页轻量 Profiling
* 核心数据表健康检查
* 启用插件目录体积 TOP
* 媒体大文件 TOP 与缺少缩略图样本
* Transient 深度概览与 wp_options 前缀体积 TOP
* 媒体文件存在性抽样
* Action Scheduler 失败/超时任务样本
* Multisite 兼容检测
* 媒体库 MIME 与缺失文件路径检测
* 慢查询 EXPLAIN 增强采样
* Action Scheduler 任务状态分布与手动清理
* Object Cache 能力与缓存分组检测
* 页面缓存 HIT/MISS/BYPASS 命中统计
* 后台列表筛选器精简模式
* 慢查询 EXPLAIN 采样
* 高级缓存 Drop-in 安全安装/卸载
* WooCommerce/Action Scheduler 检测
* 性能趋势记录
* 媒体库只读体检
* 高级缓存就绪检查
* Heartbeat 控制与后台 AJAX 诊断
* JSON 诊断报告导出

== Safety ==

插件设计原则：

* 默认只检测，不自动删除
* 所有清理动作需要管理员手动确认
* 分批处理，避免大数据量站点一次性操作卡死数据库
* 已发布重复文章只审查展示，不自动删除
* 重复草稿只移入回收站，不永久删除
* 添加数据库索引前会检测是否已存在
* 维护日志最多保留最近 100 条，避免无限增长

== Installation ==

1. 上传 `wp-large-content-optimizer` 文件夹到 `/wp-content/plugins/`
2. 在 WordPress 后台启用 `WP Large Content Optimizer`
3. 进入 `工具 -> 大站优化`
4. 先查看诊断报告，再按“安全优化向导”建议逐步处理

== Frequently Asked Questions ==

= 会自动删除文章吗？ =

不会。默认只检测。清理动作需要管理员手动点击确认。

= 会删除已发布文章吗？ =

不会自动删除已发布文章。已发布重复文章审查器只展示数据，不自动处理。

= 添加索引安全吗？ =

插件会先检测索引是否已存在，避免重复添加。但大表添加索引会占用数据库资源，建议先备份并在低峰期执行。

= 为什么报告会缓存？ =

大站统计 postmeta 热点、表大小、慢查询风险可能比较重。插件默认缓存诊断报告 10 分钟，可手动刷新。

= 3.7.0 的目录体积扫描会修改插件或主题文件吗？ =

不会。插件/主题体积扫描只读统计启用插件目录和当前主题目录，用于发现日志、缓存、备份包或临时文件堆积；不会删除、移动或改写任何文件。

= 3.6.0 会自动 OPTIMIZE 数据表或删除媒体文件吗？ =

不会。核心数据表健康检查只读取 information_schema；媒体文件存在性只是抽样检测最近附件路径是否存在，不删除文件、不删除附件记录。

= 3.5.0 的 EXPLAIN 采样安全吗？ =

安全。只执行固定的 SELECT 样本的 EXPLAIN，不读取慢查询日志，不执行 UPDATE/DELETE/ALTER，也不会跨站点扫描。

= 3.4.0 会自动清理 Action Scheduler 任务吗？ =

不会。只提供统计和手动按钮，且只处理 30 天前的已完成/失败记录，单次最多 500 条。失败任务建议先确认原因后再清理。

= 3.3.0 的页面缓存统计会不会拖慢网站？ =

默认关闭。开启后只记录聚合计数、原因 TOP 和少量 URL 样本，适合短期诊断缓存命中情况；如果已有服务器级缓存或不需要观测，可以保持关闭。

= 3.2.0 的 Cron 暂停会删除任务吗？ =

不会。暂停 Hook 只会阻止后续新调度，不会删除当前已经计划的事件。如果确实需要删除当前事件，可以在 Hook 行内手动点击“删除此 Hook 事件”，该操作需要确认。

= 3.1.0 的高级缓存 Drop-in 会不会覆盖现有缓存？ =

不会。如果已存在第三方 `advanced-cache.php`，插件会拒绝覆盖。卸载时也只删除带 WLCO 标识的 drop-in。高级缓存默认不安装，需要管理员手动确认；并且仍需 `WP_CACHE=true` 才会生效。

= 3.0.0 补齐了哪些剩余优化？ =

3.0.0 新增 Heartbeat 控制、admin-ajax 诊断、高级缓存就绪检查、媒体库只读体检、插件/主题体检、性能趋势记录、WooCommerce/Action Scheduler 检测，以及 feed/REST/XML-RPC 轻量控制。高级缓存 drop-in 只检测不自动写入，避免和服务器级缓存冲突。

= 2.9.0 的数据库深度治理做了什么？ =

新增 postmeta 深度治理和 autoload 优化器。postmeta 模块可检测并分批清理低风险缓存/编辑痕迹字段（如 _edit_lock、_edit_last、_wp_old_slug、_oembed_*），检测低风险重复 postmeta 和超大 meta_value。autoload 优化器展示 autoload 总体积和大项，支持将非保护项改为不自动加载，并提供回滚记录。

= 后台列表 AJAX 延迟统计有什么用？ =

2.8.0 新增后台文章列表 AJAX 延迟统计：月份筛选和精确总数可先从首屏移除，页面打开后异步计算并缓存，减少大内容站打开文章列表时的同步阻塞。建议配合“禁用月份下拉统计”和“禁用精确总数统计”使用。

= 2.7.1 页面缓存做了哪些稳定增强？ =

2.7.1 会在保存设置后即时刷新诊断缓存，缓存目录自动写入 index.html 和 .htaccess 保护文件，后台显示最后生成/清理时间，并增强输出缓冲层级判断，减少与主题/插件 buffer 冲突。

= 页面缓存会默认开启吗？ =

不会。页面缓存默认关闭，需要管理员在设置中手动开启。如果服务器已有 Nginx FastCGI Cache、LiteSpeed Cache、WP Rocket 等页面缓存，建议不要重复开启。

= 页面缓存会缓存哪些页面？ =

可选缓存首页、文章页/页面、分类/标签/归档页。不会缓存登录用户、后台、搜索、预览、REST/AJAX、带查询参数 URL。发布/删除文章或评论状态变化时会自动清空缓存。

= 支持导出报告吗？ =

支持，可在插件页面顶部点击“导出 JSON 诊断报告”。

== Changelog ==

= 2.6.0 =
* 新增后台文章列表查询缓存，可缓存相同筛选条件下的文章 ID 列表，并在文章更新/删除后自动清理。
* 新增 Redis/Object Cache 深度检测，检测 drop-in、缓存类、Redis 插件和缓存可写性。

= 2.5.0 =
* 新增 WP-Cron 与采集任务检测模块。
* 支持识别过期、高频、重复、采集相关 cron 事件，并可安全清理完全重复事件。

= 2.4.1 =
* 优化中文文案：统一使用“失效”表述，更适合站长理解。

= 2.4.0 =
* 新增后台 AJAX 队列清理与进度条。
* 支持选择多个清理项目自动分批执行，可暂停/继续，并写入维护日志。

= 2.3.0 =
* 新增 GitHub Release 插件内自动更新支持。
* 支持 WordPress 后台检测 GitHub 最新版本、查看更新说明并下载 release zip。

= 2.2.0 =
* 后台界面改为 Tab 分组展示：概览、数据库、采集站、前台优化、日志、设置。
* 支持记住上次打开的 Tab。

= 2.1.0 =
* 优化后台 UI：新增顶部 Hero 概览、健康评分卡片、快捷导航、模块折叠、卡片视觉层级、表格样式和移动端适配。

= 2.0.0 =
* 新增 JSON 诊断报告导出。
* 导出内容包含完整诊断、设置与维护日志。

= 1.9.0 =
* 新增数据库维护日志。
* 记录清理、索引、重复草稿移动等操作。

= 1.8.0 =
* 新增数据库慢查询风险分析。
* 分析 post_type、post_status、taxonomy、autoload、低选择性 meta_key。

= 1.7.0 =
* 新增前台性能与缓存检测。
* 新增前台轻量优化开关。

= 1.6.0 =
* 新增后台大列表快速模式。
* 优化后台文章/页面列表查询压力。

= 1.5.0 =
* 新增已发布重复文章审查器。

= 1.4.0 =
* 新增重复文章处理工具。
* 支持重复草稿移动到回收站。

= 1.3.0 =
* 新增采集站专项体检。

= 1.2.0 =
* 新增安全优化向导与缓存环境检查。

= 1.1.0 =
* 新增性能评分、数据表大小、postmeta 热点、autoload 大对象分析。

= 1.0.0 =
* 初始版本。
