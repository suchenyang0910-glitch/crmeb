# CRMEB `official_v6` 服务器部署文档

## 1. 目标说明

本文档用于把当前已经在本地验证通过的 `official_v6` 迁移到 Linux 服务器，而不是重新走安装向导。

当前本地基线：

- 项目根目录：`official_v6`
- Web 入口目录：`public`
- 后台地址：`/admin`
- 已有安装标记：`.constant`、`public/install.lock`
- 当前依赖：ThinkPHP + CRMEB v6
- 当前本地验证环境：PHP 7.1、MySQL 5.5、Redis 6379

推荐服务器目标环境：

- Linux：Ubuntu 20.04+/Debian 11+/CentOS 7+
- Web：Nginx
- PHP：7.4 FPM
- MySQL：5.7 / 8.0
- Redis：6.x
- Supervisor：管理队列、定时任务、Workerman

说明：

- 项目 `composer.json` 要求 `php >= 7.1.0`
- 若使用 PHP 7.4，通常兼容性更好，也更适合线上部署
- 本次部署建议复用当前已修好的业务代码、数据库结构和 `public/uploads`

## 2. 必备部署物料

上线前需要准备以下内容：

- 代码目录：`official_v6`
- 数据库导出文件：建议从当前本地 `crmeb_v6` 导出
- 历史上传文件：`public/uploads`
- 生产环境配置：`.env`
- 安装标记：`.constant`、`public/install.lock`

建议不要遗漏 `public/uploads`，否则商品图、主题图、富文本详情图会再次出现裂图。

## 3. 服务器目录建议

建议目录结构如下：

```text
/data/www/crmeb-official_v6
├── app
├── config
├── crmeb
├── public
├── vendor
├── .env
└── .constant
```

建议日志目录：

```text
/data/logs/crmeb
```

建议 Supervisor 日志目录：

```text
/data/logs/supervisor
```

## 4. 服务器环境准备

以 Ubuntu / Debian 为例：

```bash
sudo apt update
sudo apt install -y nginx redis-server supervisor unzip git
sudo apt install -y php7.4 php7.4-fpm php7.4-cli php7.4-mysql php7.4-curl php7.4-bcmath php7.4-mbstring php7.4-xml php7.4-zip php7.4-gd
```

安装 Composer：

```bash
cd /usr/local/bin
sudo curl -sS https://getcomposer.org/installer | sudo php
sudo mv composer.phar composer
composer --version
```

确认服务：

```bash
sudo systemctl enable nginx redis-server php7.4-fpm supervisor
sudo systemctl start nginx redis-server php7.4-fpm supervisor
```

## 5. 本地导出建议

### 5.1 导出数据库

在本地执行：

```bash
mysqldump -uroot -proot --default-character-set=utf8mb4 crmeb_v6 > crmeb_v6.sql
```

### 5.2 打包上传文件

至少打包以下目录：

```text
official_v6/
official_v6/public/uploads/
crmeb_v6.sql
```

如果你准备直接传整站，可排除 `runtime` 再压缩：

```bash
zip -r crmeb-official_v6-deploy.zip official_v6 -x "official_v6/runtime/*"
```

## 6. 上传到服务器

上传方式任选其一：

- `scp`
- `rsync`
- SFTP
- 宝塔 / 面板上传

示例：

```bash
scp -r official_v6 root@YOUR_SERVER_IP:/data/www/
scp crmeb_v6.sql root@YOUR_SERVER_IP:/data/www/
```

## 7. 服务器部署步骤

### 7.1 安装 PHP 依赖

```bash
cd /data/www/crmeb-official_v6
composer install --no-dev --optimize-autoloader
php think service:discover
php think vendor:publish
```

### 7.2 配置生产环境 `.env`

复制模板：

```bash
cp deploy/env/.env.production.example .env
```

需要按服务器实际信息修改：

- 数据库地址、库名、账号、密码
- Redis 地址和密码
- `QUEUE_NAME`
- `APP_DEBUG=false`

### 7.3 导入数据库

```bash
mysql -uYOUR_DB_USER -pYOUR_DB_PASSWORD YOUR_DB_NAME < /data/www/crmeb_v6.sql
```

### 7.4 确认安装标记

以下文件必须存在：

- `.constant`
- `public/install.lock`

否则程序可能重新进入安装流程。

### 7.5 设置权限

推荐站点用户为 `www-data`：

```bash
sudo chown -R www-data:www-data /data/www/crmeb-official_v6
sudo find /data/www/crmeb-official_v6 -type d -exec chmod 755 {} \;
sudo find /data/www/crmeb-official_v6 -type f -exec chmod 644 {} \;
sudo chmod -R 775 /data/www/crmeb-official_v6/runtime
sudo chmod -R 775 /data/www/crmeb-official_v6/public/uploads
```

如果目录尚未生成：

```bash
mkdir -p /data/www/crmeb-official_v6/runtime
mkdir -p /data/www/crmeb-official_v6/public/uploads
mkdir -p /data/logs/crmeb
mkdir -p /data/logs/supervisor
```

## 8. Nginx 配置

使用模板文件：

`deploy/nginx/crmeb-official-v6.conf.example`

核心要求：

- `root` 指向 `public`
- PHP 请求交给 `php-fpm`
- URL 重写到 `index.php`

部署后执行：

```bash
sudo cp deploy/nginx/crmeb-official-v6.conf.example /etc/nginx/sites-available/crmeb-official_v6.conf
sudo ln -s /etc/nginx/sites-available/crmeb-official_v6.conf /etc/nginx/sites-enabled/crmeb-official_v6.conf
sudo nginx -t
sudo systemctl reload nginx
```

## 9. 队列、定时任务、Workerman

项目包含以下后台进程：

- 队列消费者：处理异步任务
- `timer`：自动收货、库存预警等
- `workerman`：聊天和后台消息通知

建议使用 Supervisor 托管，配置模板：

`deploy/supervisor/crmeb-services.conf.example`

部署后执行：

```bash
sudo cp deploy/supervisor/crmeb-services.conf.example /etc/supervisor/conf.d/crmeb-services.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

## 10. 首次上线检查清单

### 10.1 基础检查

- 页面首页可打开
- 后台 `/admin` 可登录
- 商品详情图可加载
- `public/uploads` 历史图片可访问
- Redis 连接正常
- 数据库写入正常

### 10.2 接口检查

- `/api/basic_config`
- `/api/theme_info/theme`
- `/api/product/detail/3`
- 登录接口 `/api/login`
- 注册接口 `/api/register`

### 10.3 进程检查

```bash
php -v
php think --version
redis-cli ping
sudo supervisorctl status
sudo systemctl status nginx php7.4-fpm redis-server
```

## 11. 回滚建议

建议每次上线保留一份旧版本：

```text
/data/www/releases/crmeb-official_v6_YYYYMMDDHHMMSS
```

回滚时执行：

- 切回上一版代码目录
- 恢复上一版 `.env`
- 如数据库有结构变化，恢复最近一次备份
- 重载 Nginx 和 Supervisor

## 12. 当前项目特殊注意项

- 当前项目是基于本地修复版继续部署，不建议重新走安装向导
- `public/uploads` 已包含历史商品图和主题图，必须一起迁移
- 当前登录/注册流程已改为手机号 + 密码，请以当前代码为准
- 当前图片 URL 已兼容 `localhost / 127.0.0.1` 本地域名混用，但线上仍应统一使用正式域名

## 13. 推荐上线顺序

建议按以下顺序上线：

1. 准备服务器环境
2. 上传代码和 `uploads`
3. 导入数据库
4. 配置 `.env`
5. 配置 Nginx
6. 配置 Supervisor
7. 先验证前台和后台
8. 再开启队列、定时任务、Workerman

## 14. 下一步所需信息

要继续执行真正的服务器部署，还需要你提供：

- 服务器系统版本
- 服务器登录方式：SSH 用户名 / 端口
- 域名
- 生产数据库连接信息
- 生产 Redis 连接信息
- 是否使用 HTTPS
- 是否需要我继续生成数据库导出和上线前检查命令
