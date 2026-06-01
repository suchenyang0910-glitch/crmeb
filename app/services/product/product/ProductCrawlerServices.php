<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\services\product\product;

use app\dao\product\product\ProductCrawlGoodsDao;
use app\services\BaseServices;
use crmeb\exceptions\AdminException;
use think\facade\Config;
use think\facade\Db;

/**
 * 类目采集服务
 * Class ProductCrawlerServices
 * @package app\services\product\product
 */
class ProductCrawlerServices extends BaseServices
{
    /**
     * 爬虫状态
     */
    const STATUS_PENDING = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;
    const STATUS_FAILED = 3;

    /**
     * 默认类目关键词
     */
    const CATEGORY_PRESETS = [
        '家清' => ['洗洁精', '垃圾袋', '除菌喷雾', '洗衣凝珠', '清洁湿巾'],
        '厨房' => ['保鲜袋', '厨房抹布', '锅刷', '一次性手套', '厨房收纳盒'],
        '卫浴' => ['马桶刷', '浴室置物架', '牙刷架', '香皂盒', '浴帽'],
        '收纳' => ['收纳箱', '桌面收纳盒', '衣柜收纳袋', '抽屉分隔盒', '鞋盒'],
        '纸品' => ['卷纸', '抽纸', '厨房纸巾', '湿厕纸', '手帕纸'],
        '一次性用品' => ['一次性纸杯', '一次性餐盒', '一次性筷子', '一次性桌布', '一次性围裙'],
        '小百货' => ['衣架', '粘钩', '毛巾', '拖鞋', '防滑垫'],
    ];

    /**
     * @param ProductCrawlGoodsDao $dao
     */
    public function __construct(ProductCrawlGoodsDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取类目预设
     * @return array
     */
    public function getPresets(): array
    {
        $data = [];
        foreach (self::CATEGORY_PRESETS as $category => $keywords) {
            $data[] = [
                'category_name' => $category,
                'keywords' => $keywords,
            ];
        }

        return $data;
    }

    /**
     * 商品池列表
     * @param array $where
     * @return array
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where): array
    {
        $this->ensureTableExists();
        [$page, $limit] = $this->getPageValue();
        $where['is_del'] = 0;
        $list = $this->dao->getList($where, $page, $limit);
        foreach ($list as &$item) {
            $item['add_time_text'] = $item['add_time'] ? date('Y-m-d H:i:s', (int)$item['add_time']) : '';
            $item['update_time_text'] = $item['update_time'] ? date('Y-m-d H:i:s', (int)$item['update_time']) : '';
        }
        $count = $this->dao->getCount($where);
        return compact('list', 'count');
    }

    /**
     * 执行采集
     * @param array $categories
     * @param int $perCategory
     * @param array $sources
     * @param bool $withDetail
     * @return array
     */
    public function crawl(array $categories = [], int $perCategory = 24, array $sources = ['1688', 'pdd'], bool $withDetail = true): array
    {
        $this->ensureTableExists();
        $perCategory = max(1, min($perCategory, 30));
        $sources = $this->normalizeSources($sources);
        if (!$sources) {
            throw new AdminException('采集来源不能为空');
        }

        $presetMap = self::CATEGORY_PRESETS;
        if ($categories) {
            $presetMap = array_intersect_key($presetMap, array_flip($categories));
        }
        if (!$presetMap) {
            throw new AdminException('未匹配到可采集类目');
        }

        $summary = [
            'sources' => $sources,
            'per_category' => $perCategory,
            'categories' => [],
            'saved' => 0,
            'updated' => 0,
            'failed' => 0,
        ];

        foreach ($presetMap as $categoryName => $keywords) {
            $categorySaved = 0;
            $categoryUpdated = 0;
            $categoryFailed = 0;
            foreach ($keywords as $keyword) {
                if (($categorySaved + $categoryUpdated) >= $perCategory) {
                    break;
                }
                foreach ($sources as $source) {
                    $this->randomDelay();
                    if (($categorySaved + $categoryUpdated) >= $perCategory) {
                        break;
                    }
                    try {
                        $items = $this->searchByKeyword($source, $keyword, 1);
                    } catch (\Throwable $e) {
                        $categoryFailed++;
                        continue;
                    }
                    foreach ($items as $item) {
                        if (($categorySaved + $categoryUpdated) >= $perCategory) {
                            break;
                        }
                        try {
                            $result = $this->savePoolItem($categoryName, $keyword, $source, $item, $withDetail);
                            if ($result === 'created') {
                                $categorySaved++;
                            } elseif ($result === 'updated') {
                                $categoryUpdated++;
                            }
                        } catch (\Throwable $e) {
                            $categoryFailed++;
                        }
                    }
                }
            }

            $summary['categories'][] = [
                'category_name' => $categoryName,
                'saved' => $categorySaved,
                'updated' => $categoryUpdated,
                'failed' => $categoryFailed,
            ];
            $summary['saved'] += $categorySaved;
            $summary['updated'] += $categoryUpdated;
            $summary['failed'] += $categoryFailed;
        }

        return $summary;
    }

    /**
     * 软删除
     * @param int $id
     * @return bool
     */
    public function deletePoolItem(int $id): bool
    {
        $this->ensureTableExists();
        $info = $this->dao->get($id);
        if (!$info || (int)$info['is_del'] === 1) {
            throw new AdminException('商品池数据不存在');
        }
        $this->dao->update($id, ['is_del' => 1, 'update_time' => time()]);
        return true;
    }

    /**
     * 审核通过并生成正式商品
     * @param int $id
     * @param array $options
     * @return array
     */
    public function approvePoolItem(int $id, array $options = []): array
    {
        $this->ensureTableExists();
        $info = $this->dao->get($id);
        if (!$info || (int)$info['is_del'] === 1) {
            throw new AdminException('商品池数据不存在');
        }
        $info = $info->toArray();
        if ((int)$info['product_id'] > 0 && (int)$info['status'] === self::STATUS_APPROVED) {
            return [
                'id' => $id,
                'product_id' => (int)$info['product_id'],
                'status' => self::STATUS_APPROVED,
                'message' => '该商品已生成正式商品',
            ];
        }

        $payload = $this->makeProductPayload($info, $options);
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        try {
            $productId = (int)$productServices->save(0, $payload);
            $this->dao->update($id, [
                'status' => self::STATUS_APPROVED,
                'product_id' => $productId,
                'error_msg' => '',
                'update_time' => time(),
            ]);
            return [
                'id' => $id,
                'product_id' => $productId,
                'status' => self::STATUS_APPROVED,
                'message' => '审核通过并已生成正式商品',
            ];
        } catch (\Throwable $e) {
            $this->dao->update($id, [
                'status' => self::STATUS_FAILED,
                'error_msg' => $e->getMessage(),
                'update_time' => time(),
            ]);
            throw $e;
        }
    }

    /**
     * 审核拒绝
     * @param int $id
     * @param string $reason
     * @return bool
     */
    public function rejectPoolItem(int $id, string $reason = ''): bool
    {
        $this->ensureTableExists();
        $info = $this->dao->get($id);
        if (!$info || (int)$info['is_del'] === 1) {
            throw new AdminException('商品池数据不存在');
        }
        $this->dao->update($id, [
            'status' => self::STATUS_REJECTED,
            'error_msg' => $reason,
            'update_time' => time(),
        ]);
        return true;
    }

    /**
     * 获取待审核商品池数据
     * @param int $limit
     * @return array
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getPendingPoolItems(int $limit = 10): array
    {
        $this->ensureTableExists();
        return $this->dao->selectList([
            'status' => self::STATUS_PENDING,
            'is_del' => 0,
        ], '*', 1, $limit, 'id asc', [], true)->toArray();
    }

    /**
     * 批量审核前 N 条待审核商品
     * @param int $limit
     * @param array $options
     * @return array
     */
    public function approvePendingItems(int $limit = 1, array $options = []): array
    {
        $items = $this->getPendingPoolItems($limit);
        $result = [];
        foreach ($items as $item) {
            try {
                $result[] = $this->approvePoolItem((int)$item['id'], $options);
            } catch (\Throwable $e) {
                $result[] = [
                    'id' => (int)$item['id'],
                    'product_id' => 0,
                    'status' => self::STATUS_FAILED,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * 规范化来源
     * @param array $sources
     * @return array
     */
    protected function normalizeSources(array $sources): array
    {
        $sources = array_map('strtolower', array_map('trim', $sources));
        $sources = array_values(array_unique(array_filter($sources)));
        $allowed = ['1688', 'pdd'];
        return array_values(array_intersect($sources, $allowed));
    }

    /**
     * 搜索来源商品
     * @param string $source
     * @param string $keyword
     * @param int $page
     * @return array
     */
    protected function searchByKeyword(string $source, string $keyword, int $page = 1): array
    {
        if ($source === '1688') {
            return $this->searchAlibaba($keyword, $page);
        }
        if ($source === 'pdd') {
            return $this->searchPdd($keyword, $page);
        }

        return [];
    }

    /**
     * 1688 搜索
     * @param string $keyword
     * @param int $page
     * @return array
     */
    protected function searchAlibaba(string $keyword, int $page = 1): array
    {
        $url = 'https://s.1688.com/selloffer/offer_search.htm?keywords=' . urlencode($keyword) . '&pageno=' . $page;
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Referer: https://www.1688.com/',
        ];
        $html = $this->requestWithRetry($url, $headers, 20);
        if (!$html) {
            return [];
        }

        $nodes = $this->extractHtmlNodes($html, [
            "//*[contains(@class,'sm-offer-item')]",
            "//*[@data-offer-id]",
        ]);
        $list = [];
        foreach ($nodes as $node) {
            $href = $this->extractNodeValue($node, [
                ".//a[contains(@href,'detail.1688.com/offer/')]/@href",
            ]);
            if (!$href) {
                continue;
            }
            $href = $this->normalizeUrl($href, 'https:');
            $productId = $this->matchFirst('/offer\/(\d+)\.html/i', $href);
            if (!$productId) {
                continue;
            }
            $title = $this->cleanText($this->extractNodeValue($node, [
                ".//a[contains(@href,'detail.1688.com/offer/')]/@title",
                ".//*[contains(@class,'title')]/@title",
                ".//*[contains(@class,'title')]",
            ]));
            $image = $this->normalizeUrl($this->extractNodeValue($node, [
                ".//img/@data-lazy-src",
                ".//img/@data-img-url",
                ".//img/@src",
            ]), 'https:');
            $price = $this->extractPrice($this->extractNodeValue($node, [
                ".//*[contains(@class,'price')]",
                ".//*[@data-price]",
            ]));
            $sales = (int)$this->matchFirst('/(\d+)/', $this->extractNodeValue($node, [
                ".//*[contains(@class,'sale')]",
                ".//*[contains(@class,'deal')]",
            ]));
            $shopName = $this->cleanText($this->extractNodeValue($node, [
                ".//*[contains(@class,'company')]",
                ".//*[contains(@class,'shop')]",
            ]));

            $list[] = [
                'source_product_id' => $productId,
                'store_name' => $title ?: $keyword,
                'price' => $price,
                'image' => $image,
                'slider_image' => $image ? [$image] : [],
                'source_url' => $href,
                'source_shop_name' => $shopName,
                'sales' => $sales,
                'source_data' => [],
            ];
        }

        return $this->uniqueItems($list);
    }

    /**
     * 拼多多搜索
     * @param string $keyword
     * @param int $page
     * @return array
     */
    protected function searchPdd(string $keyword, int $page = 1): array
    {
        $url = 'https://apiv4.yangkeduo.com/search?q=' . urlencode($keyword) . '&page=' . $page . '&size=50&sort=default&requery=0&pdduid=0';
        $headers = [
            'Accept: application/json,text/plain,*/*',
            'Referer: https://mobile.yangkeduo.com/',
            'Host: apiv4.yangkeduo.com',
        ];
        $content = $this->requestWithRetry($url, $headers, 20);
        if (!$content) {
            return [];
        }
        $result = json_decode($content, true);
        if (!is_array($result)) {
            return [];
        }
        $items = $result['items'] ?? $result['goods_list'] ?? [];
        $list = [];
        foreach ($items as $item) {
            $goodsId = (string)($item['goods_id'] ?? $item['goodsId'] ?? '');
            if ($goodsId === '') {
                continue;
            }
            $title = $item['goods_name'] ?? $item['goodsName'] ?? $item['title'] ?? $keyword;
            $price = $this->normalizePddPrice($item);
            $image = $item['thumb_url'] ?? $item['thumbUrl'] ?? $item['hd_thumb_url'] ?? '';
            $sales = (int)($item['sales'] ?? $item['sales_tip'] ?? 0);
            $shopName = $item['mall_name'] ?? $item['store_name'] ?? '';
            $list[] = [
                'source_product_id' => $goodsId,
                'store_name' => $this->cleanText((string)$title),
                'price' => $price,
                'image' => $this->normalizeUrl((string)$image, 'https:'),
                'slider_image' => $image ? [$this->normalizeUrl((string)$image, 'https:')] : [],
                'source_url' => 'https://mobile.yangkeduo.com/goods.html?goods_id=' . $goodsId,
                'source_shop_name' => $this->cleanText((string)$shopName),
                'sales' => is_numeric($sales) ? (int)$sales : 0,
                'source_data' => $item,
            ];
        }

        return $this->uniqueItems($list);
    }

    /**
     * 保存到商品池
     * @param string $categoryName
     * @param string $keyword
     * @param string $source
     * @param array $item
     * @param bool $withDetail
     * @return string
     */
    protected function savePoolItem(string $categoryName, string $keyword, string $source, array $item, bool $withDetail = true): string
    {
        $now = time();
        $detail = $withDetail ? $this->tryHydrateByDetail($item['source_url']) : [];
        $image = $detail['image'] ?? ($item['image'] ?? '');
        $sliderImage = $detail['slider_image'] ?? ($item['slider_image'] ?? []);
        $description = $detail['description'] ?? '';
        [$image, $sliderImage, $description] = $this->localizeMedia($image, $sliderImage, $description);
        $data = [
            'source' => $source,
            'source_product_id' => (string)$item['source_product_id'],
            'category_name' => $categoryName,
            'keyword' => $keyword,
            'store_name' => $detail['store_name'] ?? $item['store_name'] ?? '',
            'store_info' => $detail['store_info'] ?? '',
            'price' => isset($detail['price']) ? (float)$detail['price'] : (float)($item['price'] ?? 0),
            'image' => $image,
            'slider_image' => json_encode($sliderImage, JSON_UNESCAPED_UNICODE),
            'description' => $description,
            'attrs' => json_encode($detail['attrs'] ?? [], JSON_UNESCAPED_UNICODE),
            'source_url' => $item['source_url'] ?? '',
            'source_shop_name' => $item['source_shop_name'] ?? '',
            'sales' => (int)($item['sales'] ?? 0),
            'status' => 0,
            'detail_status' => $detail ? 1 : 0,
            'error_msg' => $detail['error_msg'] ?? '',
            'source_data' => json_encode($detail['source_data'] ?? ($item['source_data'] ?? []), JSON_UNESCAPED_UNICODE),
            'update_time' => $now,
        ];

        $exists = $this->dao->getBySourceProduct($source, (string)$item['source_product_id']);
        if ($exists) {
            $this->dao->update((int)$exists['id'], $data);
            return 'updated';
        }

        $data['add_time'] = $now;
        $data['is_del'] = 0;
        $this->dao->save($data);
        return 'created';
    }

    /**
     * 尝试拉取详情
     * @param string $url
     * @return array
     */
    protected function tryHydrateByDetail(string $url): array
    {
        if ($url === '') {
            return [];
        }

        try {
            /** @var CopyTaobaoServices $copyService */
            $copyService = app()->make(CopyTaobaoServices::class);
            $result = $copyService->copyProduct('', '', '', $url);
            $productInfo = $result['productInfo'] ?? [];
            if (!$productInfo) {
                return [];
            }
            return [
                'store_name' => $productInfo['store_name'] ?? '',
                'store_info' => $productInfo['store_info'] ?? '',
                'price' => $productInfo['price'] ?? 0,
                'image' => $productInfo['image'] ?? '',
                'slider_image' => $productInfo['slider_image'] ?? [],
                'description' => $productInfo['description'] ?? '',
                'attrs' => $productInfo['attrs'] ?? [],
                'source_data' => $productInfo,
            ];
        } catch (\Throwable $e) {
            return [
                'error_msg' => $e->getMessage(),
            ];
        }
    }

    /**
     * 将远程图片转为本站图片
     * @param string $image
     * @param array $sliderImage
     * @param string $description
     * @return array
     */
    protected function localizeMedia(string $image, array $sliderImage, string $description = ''): array
    {
        /** @var CopyTaobaoServices $copyService */
        $copyService = app()->make(CopyTaobaoServices::class);

        $localImage = $this->downloadImageToLocal($copyService, $image);
        $localSlider = [];
        foreach ($sliderImage as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }
            $localSlider[] = $this->downloadImageToLocal($copyService, $item);
        }
        $localSlider = array_values(array_unique(array_filter($localSlider)));
        if (!$localImage && $localSlider) {
            $localImage = $localSlider[0];
        }
        if ($description !== '') {
            try {
                $description = (string)$copyService->uploadImage([], $description, 1);
            } catch (\Throwable $e) {
            }
        }

        return [$localImage, $localSlider, $description];
    }

    /**
     * 下载单张远程图片
     * @param CopyTaobaoServices $copyService
     * @param string $image
     * @return string
     */
    protected function downloadImageToLocal(CopyTaobaoServices $copyService, string $image): string
    {
        $image = $this->normalizeUrl($image, 'https:');
        if ($image === '') {
            return '';
        }
        if (!$this->isRemoteUrl($image)) {
            return $image;
        }

        try {
            $localImage = $copyService->downloadCopyImage($image);
            return is_string($localImage) && $localImage !== '' ? $localImage : $image;
        } catch (\Throwable $e) {
            return $image;
        }
    }

    /**
     * 建表
     * @return void
     */
    public function ensureTableExists(): void
    {
        $prefix = Config::get('database.connections.' . Config::get('database.default') . '.prefix');
        $table = $prefix . 'product_crawl_goods';
        $exists = Db::query("SHOW TABLES LIKE '{$table}'");
        if ($exists) {
            return;
        }

        $sql = "CREATE TABLE `{$table}` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `source` varchar(20) NOT NULL DEFAULT '',
            `source_product_id` varchar(64) NOT NULL DEFAULT '',
            `category_name` varchar(64) NOT NULL DEFAULT '',
            `keyword` varchar(128) NOT NULL DEFAULT '',
            `store_name` varchar(255) NOT NULL DEFAULT '',
            `store_info` varchar(500) NOT NULL DEFAULT '',
            `price` decimal(10,2) NOT NULL DEFAULT '0.00',
            `image` varchar(500) NOT NULL DEFAULT '',
            `slider_image` longtext,
            `description` longtext,
            `attrs` longtext,
            `source_url` varchar(500) NOT NULL DEFAULT '',
            `source_shop_name` varchar(255) NOT NULL DEFAULT '',
            `sales` int(11) NOT NULL DEFAULT '0',
            `status` tinyint(1) NOT NULL DEFAULT '0',
            `detail_status` tinyint(1) NOT NULL DEFAULT '0',
            `product_id` int(10) unsigned NOT NULL DEFAULT '0',
            `error_msg` varchar(500) NOT NULL DEFAULT '',
            `source_data` longtext,
            `add_time` int(10) unsigned NOT NULL DEFAULT '0',
            `update_time` int(10) unsigned NOT NULL DEFAULT '0',
            `is_del` tinyint(1) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_source_goods` (`source`,`source_product_id`),
            KEY `idx_category_status` (`category_name`,`status`),
            KEY `idx_add_time` (`add_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        Db::execute($sql);
    }

    /**
     * 提取节点列表
     * @param string $html
     * @param array $queries
     * @return array
     */
    protected function extractHtmlNodes(string $html, array $queries): array
    {
        if ($html === '') {
            return [];
        }
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length) {
                $result = [];
                foreach ($nodes as $node) {
                    $result[] = $node;
                }
                return $result;
            }
        }

        return [];
    }

    /**
     * 节点值提取
     * @param \DOMNode $node
     * @param array $queries
     * @return string
     */
    protected function extractNodeValue(\DOMNode $node, array $queries): string
    {
        $xpath = new \DOMXPath($node->ownerDocument);
        foreach ($queries as $query) {
            $list = $xpath->query($query, $node);
            if (!$list || !$list->length) {
                continue;
            }
            $first = $list->item(0);
            $value = $first instanceof \DOMAttr ? $first->value : $first->textContent;
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * URL 标准化
     * @param string $url
     * @param string $prefix
     * @return string
     */
    protected function normalizeUrl(string $url, string $prefix = 'https:'): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '//') === 0) {
            return $prefix . $url;
        }
        return $url;
    }

    /**
     * 是否远程图片
     * @param string $url
     * @return bool
     */
    protected function isRemoteUrl(string $url): bool
    {
        return stripos($url, 'http://') === 0 || stripos($url, 'https://') === 0 || strpos($url, '//') === 0;
    }

    /**
     * 文本清洗
     * @param string $value
     * @return string
     */
    protected function cleanText(string $value): string
    {
        $value = trim(strip_tags($value));
        return preg_replace('/\s+/u', ' ', $value) ?: '';
    }

    /**
     * 价格提取
     * @param string $value
     * @return float
     */
    protected function extractPrice(string $value): float
    {
        $match = $this->matchFirst('/(\d+(?:\.\d+)?)/', $value);
        return $match !== '' ? (float)$match : 0.00;
    }

    /**
     * 正则提取
     * @param string $pattern
     * @param string $subject
     * @return string
     */
    protected function matchFirst(string $pattern, string $subject): string
    {
        if (preg_match($pattern, $subject, $matches)) {
            return (string)($matches[1] ?? '');
        }

        return '';
    }

    /**
     * 去重
     * @param array $list
     * @return array
     */
    protected function uniqueItems(array $list): array
    {
        $map = [];
        foreach ($list as $item) {
            $key = ($item['source_product_id'] ?? '') . '|' . ($item['source_url'] ?? '');
            if ($key === '|' || isset($map[$key])) {
                continue;
            }
            $map[$key] = $item;
        }
        return array_values($map);
    }

    /**
     * 归一化拼多多价格
     * @param array $item
     * @return float
     */
    protected function normalizePddPrice(array $item): float
    {
        $price = $item['normal_price'] ?? $item['normalPrice'] ?? $item['min_group_price'] ?? $item['minGroupPrice'] ?? 0;
        if (is_numeric($price)) {
            return $price > 1000 ? round(((float)$price / 100), 2) : (float)$price;
        }

        return $this->extractPrice((string)$price);
    }

    /**
     * 组装正式商品数据
     * @param array $info
     * @param array $options
     * @return array
     */
    protected function makeProductPayload(array $info, array $options = []): array
    {
        /** @var StoreCategoryServices $categoryServices */
        $categoryServices = app()->make(StoreCategoryServices::class);
        $cateId = $options['cate_id'] ?? [];
        if (!$cateId) {
            $cateNameOne = trim((string)($options['cate_name_one'] ?? '采集商品'));
            $cateNameTwo = trim((string)($options['cate_name_two'] ?? $info['category_name']));
            $cateId = $categoryServices->getCateId($cateNameOne, $cateNameTwo);
        }

        $sourceData = $info['source_data'] ?? [];
        $items = $this->buildProductItems($sourceData, $info);
        $attrs = $this->buildProductAttrs($sourceData, $info);
        $sliderImage = $info['slider_image'] ?? [];
        if (!$sliderImage && !empty($info['image'])) {
            $sliderImage = [$info['image']];
        }
        if (!$attrs) {
            throw new AdminException('商品规格数据为空，无法生成正式商品');
        }

        return [
            'disk_info' => '',
            'logistics' => [1, 2],
            'freight' => 2,
            'postage' => 0,
            'recommend' => [],
            'presale' => 0,
            'presale_time' => [],
            'is_limit' => 0,
            'limit_type' => 0,
            'limit_num' => 0,
            'video_open' => 0,
            'vip_product' => 0,
            'vip_product_type' => 0,
            'custom_form' => [],
            'store_name' => $info['store_name'],
            'cate_id' => $cateId,
            'keyword' => $info['keyword'] ?? '',
            'unit_name' => '件',
            'store_info' => $info['store_info'] ?? $info['store_name'],
            'image' => '',
            'recommend_image' => '',
            'slider_image' => $sliderImage,
            'description' => $info['description'] ?? '',
            'description_images' => [],
            'ficti' => 0,
            'give_integral' => 0,
            'sort' => 0,
            'is_show' => (int)($options['is_show'] ?? 1),
            'is_hot' => 0,
            'is_benefit' => 0,
            'is_best' => 0,
            'is_new' => 0,
            'is_good' => 0,
            'is_postage' => 0,
            'is_sub' => [],
            'recommend_list' => [],
            'virtual_type' => 0,
            'spec_type' => count($items) > 1 ? 1 : 0,
            'is_virtual' => 0,
            'video_link' => '',
            'temp_id' => 0,
            'activity' => ['默认'],
            'couponName' => [],
            'coupon_ids' => [],
            'command_word' => '',
            'min_qty' => 1,
            'type' => 0,
            'is_copy' => 0,
            'label_id' => [],
            'params_list' => [],
            'label_list' => [],
            'protection_list' => [],
            'items' => $items,
            'attrs' => $attrs,
        ];
    }

    /**
     * 组装规格名
     * @param array $sourceData
     * @param array $info
     * @return array
     */
    protected function buildProductItems(array $sourceData, array $info): array
    {
        $items = $sourceData['items'] ?? [];
        if (is_array($items) && count($items)) {
            return array_values(array_map(function ($item) {
                $details = [];
                foreach (($item['detail'] ?? []) as $detail) {
                    if (is_array($detail)) {
                        $details[] = [
                            'value' => $detail['value'] ?? '',
                            'pic' => $detail['pic'] ?? '',
                        ];
                    } else {
                        $details[] = [
                            'value' => (string)$detail,
                            'pic' => '',
                        ];
                    }
                }
                return [
                    'value' => $item['value'] ?? '规格',
                    'detail' => array_values(array_filter($details, function ($detail) {
                        return $detail['value'] !== '';
                    })),
                ];
            }, $items));
        }

        return [
            [
                'value' => '规格',
                'detail' => [
                    ['value' => '默认', 'pic' => ''],
                ],
            ],
        ];
    }

    /**
     * 组装规格值
     * @param array $sourceData
     * @param array $info
     * @return array
     */
    protected function buildProductAttrs(array $sourceData, array $info): array
    {
        $attrs = $sourceData['attrs'] ?? [];
        if (is_array($attrs) && count($attrs)) {
            foreach ($attrs as $index => $attr) {
                $detail = $attr['detail'] ?? [];
                if (!$detail && isset($attr['attr_arr']) && is_array($attr['attr_arr'])) {
                    $detail = array_combine(array_fill(0, count($attr['attr_arr']), '规格'), $attr['attr_arr']) ?: [];
                }
                $attrs[$index] = [
                    'attr_arr' => $attr['attr_arr'] ?? array_values($detail),
                    'detail' => $detail,
                    'price' => (float)($attr['price'] ?? $info['price'] ?? 0),
                    'pic' => $attr['pic'] ?? ($info['image'] ?? ''),
                    'ot_price' => (float)($attr['ot_price'] ?? $attr['price'] ?? $info['price'] ?? 0),
                    'cost' => (float)($attr['cost'] ?? 0),
                    'stock' => (int)($attr['stock'] ?? 100),
                    'is_show' => (int)($attr['is_show'] ?? 1),
                    'is_default_select' => $index === 0 ? 1 : (int)($attr['is_default_select'] ?? 0),
                    'is_virtual' => 0,
                    'brokerage' => 0,
                    'brokerage_two' => 0,
                    'vip_price' => (float)($attr['vip_price'] ?? 0),
                    'vip_proportion' => 0,
                    'unique' => '',
                    'weight' => (float)($attr['weight'] ?? 0),
                    'volume' => (float)($attr['volume'] ?? 0),
                    'bar_code' => $attr['bar_code'] ?? '',
                    'bar_code_number' => $attr['bar_code_number'] ?? '',
                    'quota' => 0,
                    'disk_info' => '',
                ];
            }
            return $attrs;
        }

        return [
            [
                'attr_arr' => ['默认'],
                'detail' => ['规格' => '默认'],
                'price' => (float)($info['price'] ?? 0),
                'pic' => $info['image'] ?? '',
                'ot_price' => (float)($info['price'] ?? 0),
                'cost' => 0,
                'stock' => 100,
                'is_show' => 1,
                'is_default_select' => 1,
                'is_virtual' => 0,
                'brokerage' => 0,
                'brokerage_two' => 0,
                'vip_price' => 0,
                'vip_proportion' => 0,
                'unique' => '',
                'weight' => 0,
                'volume' => 0,
                'bar_code' => '',
                'bar_code_number' => '',
                'quota' => 0,
                'disk_info' => '',
            ],
        ];
    }

    /**
     * 带重试的请求
     * @param string $url
     * @param array $headers
     * @param int $timeout
     * @param int $retry
     * @return string
     */
    protected function requestWithRetry(string $url, array $headers = [], int $timeout = 20, int $retry = 3): string
    {
        $proxy = trim((string)getenv('CRAWLER_PROXY'));
        $proxyAuth = trim((string)getenv('CRAWLER_PROXY_AUTH'));
        for ($i = 0; $i < $retry; $i++) {
            $ch = curl_init();
            $requestHeaders = array_merge([
                'User-Agent: ' . $this->randomUserAgent(),
                'Connection: keep-alive',
            ], $headers);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
            if ($proxy !== '') {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
                if ($proxyAuth !== '') {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyAuth);
                }
            }
            $content = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($content !== false && $httpCode >= 200 && $httpCode < 300) {
                return (string)$content;
            }

            if ($i < $retry - 1) {
                usleep((int)(500000 * ($i + 1)));
            }

            if ($i === $retry - 1 && $curlError) {
                return '';
            }
        }

        return '';
    }

    /**
     * 随机请求头
     * @return string
     */
    protected function randomUserAgent(): string
    {
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Linux; Android 10; Mobile) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
        ];
        return $userAgents[array_rand($userAgents)];
    }

    /**
     * 随机延迟
     * @return void
     */
    protected function randomDelay(): void
    {
        usleep((int)mt_rand(200000, 800000));
    }
}
