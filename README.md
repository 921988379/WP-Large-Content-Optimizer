# WP Large Content Optimizer

WordPress 大文章量站点性能优化插件。

## 作者

一点优化  
https://www.seoyh.net

## 当前版本

`3.6.0`

## 后台入口

`工具 -> 大站优化`

## 核心功能

- 数据库体检与性能评分
- 安全优化向导
- 分批清理数据库垃圾
- 推荐索引检测与添加
- 后台文章列表快速模式
- 采集站专项体检
- 重复草稿安全处理
- 已发布重复文章审查
- 前台性能与缓存检测
- 数据库慢查询风险分析
- 数据库维护日志
- JSON 诊断报告导出
- 后台 AJAX 队列清理与进度条
- WP-Cron 与采集任务检测
- 后台文章列表查询缓存
- 后台列表 AJAX 延迟统计
- postmeta 深度治理
- autoload 优化器
- 轻量页面缓存（默认关闭）
- Redis/Object Cache 深度检测
- REST/XML-RPC/feed 轻量控制
- WooCommerce/Action Scheduler 检测
- 趋势记录清空工具
- 媒体库问题附件样本审查
- 页面缓存手动预热与排除规则
- Cron Hook 来源/回调识别
- 诊断页轻量 Profiling
- 核心数据表健康检查
- 媒体文件存在性抽样
- Action Scheduler 失败/超时任务样本
- Multisite 兼容检测
- 媒体库 MIME 与缺失文件路径检测
- 慢查询 EXPLAIN 增强采样
- Action Scheduler 任务状态分布与手动清理
- Object Cache 能力与缓存分组检测
- 页面缓存 HIT/MISS/BYPASS 命中统计
- Cron Hook 暂停/恢复与按 Hook 删除已计划事件
- 后台列表筛选器精简模式
- 慢查询 EXPLAIN 采样
- 高级缓存 Drop-in 安全安装/卸载
- 插件/主题体检
- 性能趋势记录
- 媒体库只读体检
- 高级缓存就绪检查
- Heartbeat 控制与后台 AJAX 诊断

## 3.6.0 安全维护与可操作诊断增强

3.6.0 继续把诊断从“看总数”推进到“能定位样本”，但仍保持保守只读/手动确认：

- Action Scheduler 新增最近失败任务样本，展示 Hook、Group、计划时间、尝试次数和最近日志摘要
- Action Scheduler 新增超时待执行任务样本，便于定位队列堵塞来源
- 媒体库新增最近附件实际文件存在性抽样，帮助排查迁移、对象存储或上传目录不同步
- 新增核心数据表健康检查，展示核心表引擎、估算行数、数据/索引体积、碎片、Auto Increment 和 Collation
- 数据表健康检查只读取 `information_schema`，不自动执行 `OPTIMIZE`、`ALTER` 或任何写操作

Action Scheduler 清理仍需管理员手动确认，且只清理 30 天前已完成/失败记录；媒体与数据库增强均不自动删除或修改数据。

## 3.5.0 慢查询、媒体库与多站点诊断增强

3.5.0 继续补齐剩余诊断能力，重点是更细的只读分析：

- 慢查询 EXPLAIN 采样增加深分页、meta_key、meta_key/meta_value、附件列表等固定安全 SELECT 样本
- EXPLAIN 表格新增 possible_keys、Extra 和针对每个样本的优化建议
- 媒体库体检新增缺少 `_wp_attached_file`、大附件元数据、MIME 类型分布和更完整的问题样本
- 新增 Multisite 兼容检测，明确当前站点表前缀、Blog ID、站点数量和网络启用风险
- 新增诊断页轻量 Profiling，展示诊断报告生成耗时、SQL 数、PHP 峰值内存和缓存状态

本版仍然不读取慢查询日志、不执行写 SQL、不删除媒体、不跨站点自动治理。

## 3.4.0 Object Cache 与任务队列治理

3.4.0 继续补齐缓存与任务队列治理能力：

- Redis/Object Cache 检测增加 drop-in 体积、批量读取能力、flush_runtime / flush_group 能力和缓存分组可见性
- WP-Cron Hook 表新增来源/回调识别，暂停或删除 Hook 前更容易判断影响范围
- WooCommerce / Action Scheduler 增加状态分布、分组 TOP、超时待执行、30 天前已完成/失败记录统计
- 新增手动清理 30 天前 Action Scheduler 已完成/失败记录，单次最多 500 条，需管理员确认
- 性能趋势记录增加关键指标变化卡片，便于观察优化前后变化

所有新增治理操作均为手动触发，不自动删除业务任务，不修改 Redis/服务器缓存配置。

## 3.3.0 页面缓存观测与预热

3.3.0 继续完善页面缓存可观测性和安全控制：

- 新增页面缓存 HIT / MISS / BYPASS / 写入 / 跳过写入统计
- 新增命中率、最近缓存 URL 样本、MISS/BYPASS 原因 TOP
- 新增手动缓存预热，覆盖首页、最新文章/页面和热门分类/标签
- 新增缓存排除路径/模式，支持每行一条与 `*` 通配符
- 新增清空命中统计工具，不删除缓存文件

缓存统计默认关闭，开启后只记录少量聚合计数和最近 URL 样本；页面缓存本身仍默认关闭，避免与服务器级缓存冲突。

## 3.2.0 Cron 与媒体治理

3.2.0 继续补齐安全治理能力：

- WP-Cron 表格新增 Hook 状态与操作列
- 支持按 Hook 暂停/恢复后续新 Cron 调度
- 支持手动删除指定 Hook 当前已计划事件，需管理员确认
- 保护本插件维护 Hook，避免误暂停/误删除自身维护任务
- 媒体库体检新增待审查附件样本，展示未挂载/缺少元数据附件及编辑入口

注意：Cron 暂停只阻止后续新调度，不自动删除已存在事件；删除指定 Hook 事件属于高影响操作，应先确认 Hook 来源。

## 3.1.0 高级治理

3.1.0 继续补齐高收益但需要谨慎启用的优化：

- 高级缓存 Drop-in 管理：可安装/卸载 WLCO 自有 `advanced-cache.php`，不会覆盖或删除第三方 drop-in
- WLCO drop-in 只服务匿名 GET、无查询参数、无登录/评论/电商 Cookie 的缓存页面
- Drop-in 固定 1 小时保守过期，并依赖插件原有发布/评论变更自动清空缓存
- 慢查询 EXPLAIN 采样：对固定安全 SELECT 样本执行 EXPLAIN，辅助判断索引使用情况
- 后台列表筛选器精简模式：减少日期/分类/作者等重筛选器误触造成的重查询
- 性能趋势记录新增清空工具

注意：安装 `advanced-cache.php` 后仍需自行确认 `wp-config.php` 中 `WP_CACHE` 为 true；如果已有 Nginx FastCGI Cache、LiteSpeed Cache、WP Rocket、Cloudflare APO 等页面缓存，不建议叠加启用。

## 3.0.0 综合治理

3.0.0 补齐剩余低风险优化与诊断能力：

- Heartbeat 控制：可保持默认、降频，或在非编辑页禁用
- 前台瘦身：可移除 feed/REST 发现链接，可选禁用 XML-RPC、限制访客 REST API
- admin-ajax 诊断：统计登录/访客 AJAX Hook，提示访客 admin-ajax 压力
- 高级缓存就绪检查：检测 `WP_CACHE` 和 `advanced-cache.php`，不自动写入 drop-in
- 媒体库体检：附件总数、未挂载附件、缺少附件元数据，只读展示
- 插件/主题体检：识别缓存/优化类插件，提示功能冲突风险
- 性能趋势记录：刷新诊断时保留最近 30 次关键指标
- WooCommerce/Action Scheduler 检测：任务表、待执行/失败任务和 Hook TOP

高级缓存 drop-in 仍保持保守策略：只检测、不自动接管，避免和服务器级缓存或现有缓存插件冲突。

## 数据库深度治理

2.9.0 新增 postmeta 深度治理和 autoload 优化器：

- 检测并分批清理低风险 postmeta：`_edit_lock`、`_edit_last`、`_wp_old_slug`、`_oembed_*`
- 检测低风险重复 postmeta，每组保留最早一条
- 展示超大 `meta_value`，只读提示，不自动删除
- 统计 autoload 总体积，展示大 autoload option
- 非保护 option 可改为不自动加载，并记录回滚信息

## 后台列表 AJAX 延迟统计

2.8.0 新增：月份筛选和精确总数可以改为 AJAX 延迟加载，并使用 transient 缓存。这样后台文章列表首屏不再同步等待重统计，适合文章量很大的内容站/采集站。

建议搭配：开启“禁用文章列表月份下拉统计”和“禁用精确总数统计”，再开启对应 AJAX 延迟加载。

## 页面缓存说明

- 默认关闭，需要管理员手动开启
- 支持缓存首页、文章页/页面、分类/标签/归档页
- 自动跳过登录用户、后台、搜索、预览、REST/AJAX、带查询参数 URL
- 支持 TTL 设置、移动端/PC 分开缓存、缓存统计和一键清空
- 发布/删除文章或评论状态变化时自动清空缓存
- 如果服务器已有 Nginx FastCGI Cache、LiteSpeed Cache、WP Rocket 等页面缓存，建议不要重复开启

## 安全原则

- 默认只检测
- 清理动作需手动确认
- 已发布文章不自动删除
- 分批处理，降低大站超时风险
- 维护日志最多保留 100 条

## 打包

在插件父目录执行：

```bash
zip -r wp-large-content-optimizer.zip wp-large-content-optimizer \
  -x 'wp-large-content-optimizer/.git/*' \
  -x 'wp-large-content-optimizer/*.zip'
```

## 自动更新

插件通过 GitHub Releases 检测更新：

- 仓库：https://github.com/921988379/WP-Large-Content-Optimizer
- Release tag 建议使用 `v版本号`，例如 `v2.3.0`
- Release 附件需要上传 `wp-large-content-optimizer.zip`

WordPress 后台会通过 GitHub API 获取最新 Release。
