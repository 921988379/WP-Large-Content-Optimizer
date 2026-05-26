=== WP Large Content Optimizer ===
Contributors: yidianyouhua
Tags: performance, database, cleanup, optimization, large site, wordpress admin, postmeta
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.4.0
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
* 分批清理 revision、auto-draft、trash、孤儿 postmeta、孤儿分类关系、过期 transient
* 推荐数据库索引检测与手动添加
* 后台文章列表轻量化
* 后台大列表快速模式
* 采集站专项体检
* 重复草稿安全移动到回收站
* 已发布重复文章审查器
* 前台性能与缓存检测
* 数据库慢查询风险分析
* 数据库维护日志
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

= 支持导出报告吗？ =

支持，可在插件页面顶部点击“导出 JSON 诊断报告”。

== Changelog ==

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
