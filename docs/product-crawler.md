# 商品类目采集与审核上架

## 功能范围
- 支持来源：`1688`、`拼多多`
- 预设类目：`家清`、`厨房`、`卫浴`、`收纳`、`纸品`、`一次性用品`、`小百货`
- 流程：类目采集 -> 商品池 -> 审核通过 -> 自动生成正式商品

## 后台接口
- `GET /product/crawl/category/preset`
- `GET /product/crawl/category/list`
- `POST /product/crawl/category/run`
- `POST /product/crawl/category/approve/:id`
- `POST /product/crawl/category/reject/:id`
- `DELETE /product/crawl/category/delete/:id`

## 控制台命令

### 仅采集
```bash
php think product:crawl --sources=1688,pdd --per=5 --detail=1
```

### 采集后自动审核前 3 条
```bash
php think product:crawl --sources=1688 --per=3 --detail=1 --approve=3 --cate1=采集商品
```

### 指定类目测试
```bash
php think product:crawl --categories=家清,纸品 --sources=1688,pdd --per=2 --detail=1 --approve=1
```

## 完整测试流程
1. 先执行小批量采集，建议 `--per=2` 或 `--per=3`
2. 检查商品池接口是否已有数据
3. 使用 `--approve=1` 自动审核一条，验证正式商品是否生成
4. 到后台商品列表确认：
   - 商品名称是否正常
   - 主图/轮播图/详情图是否存在
   - 分类是否挂到 `采集商品/{类目}`
5. 再逐步放大到 `--per=20` 或以上

## 被封 IP / 限流处理

### 已内置能力
- 请求重试
- 随机 `User-Agent`
- 随机请求延迟
- 可选代理支持

### 代理环境变量
```bash
export CRAWLER_PROXY=http://127.0.0.1:7890
export CRAWLER_PROXY_AUTH=username:password
```

如果没有账号密码，只配置 `CRAWLER_PROXY` 即可。

### 建议顺序
1. 先只抓 `1688`
2. 每类先抓 `2-5` 条
3. 开启代理后再扩大采集量
4. 失败率升高时，拆开来源分别跑

### 常见现象
- 返回空列表：目标站点临时风控、页面结构变化或 IP 被限
- 图片存在但详情为空：详情复制链路不可用或来源接口返回不完整
- 商品池有数据但审核失败：规格结构不完整，查看 `error_msg`

## 说明
- 当前仓库里的后台前端为编译产物，不包含可维护的前端源码工程
- 因此建议优先通过接口和控制台命令联调采集流程
