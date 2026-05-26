# WP Large Content Optimizer

WordPress 大文章量站点性能优化插件。

## 作者

一点优化  
https://www.seoyh.net

## 当前版本

`2.5.0`

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
