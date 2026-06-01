# CRMEB 本地目录说明

## 当前建议使用的主目录

- 代码工作目录：`D:\projects\crmeb_backup\crmeb\official_v6`
- 当前本地运行基线：`D:\projects\crmeb_backup\crmeb\official_v6`
- 当前整理后的完整数据库文件：`D:\projects\crmeb_backup\crmeb\official_v6\database\crmeb_v6_full.sql`

## 另一个目录是什么

- 官方原始源码目录：`D:\projects\crmeb_backup\CRMEB-6.0.0\crmeb`

这个目录主要用于：

- 对照官方原版文件
- 查找原始模板和源码
- 作为参考基线

这个目录不建议继续直接改动部署。

## `official_v6` 目录里现在有什么

- 完整业务代码
- 已修过的登录注册流程
- 已修过的图片裂图逻辑
- 部署文档：`docs/server-deployment.md`
- 完整数据库导出：`database/crmeb_v6_full.sql`
- 安装基线 SQL：`public/install/crmeb.sql`

## 两个 SQL 的区别

- `public/install/crmeb.sql`
  - 官方安装向导使用的初始化 SQL
  - 适合首次安装基线

- `database/crmeb_v6_full.sql`
  - 当前本地可运行环境导出的完整数据库
  - 更适合直接部署到服务器复现本地现状

## 服务器部署时优先使用什么

- 代码目录：`official_v6`
- 数据库文件：`database/crmeb_v6_full.sql`
- 上传资源目录：`public/uploads`
- 配置文件：`.env`
- 安装标记：`.constant`、`public/install.lock`
