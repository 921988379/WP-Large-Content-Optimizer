# WP Large Content Optimizer

WordPress 大文章量站点性能优化插件。

## 作者

一点优化  
https://www.seoyh.net

## 当前版本

`2.9.0`

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
