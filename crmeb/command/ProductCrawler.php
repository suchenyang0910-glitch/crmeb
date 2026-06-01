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

namespace crmeb\command;

use app\services\product\product\ProductCrawlerServices;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 类目采集命令
 * Class ProductCrawler
 * @package crmeb\command
 */
class ProductCrawler extends Command
{
    protected function configure()
    {
        $this->setName('product:crawl')
            ->addOption('categories', null, Option::VALUE_REQUIRED, '类目，多个英文逗号分隔')
            ->addOption('sources', null, Option::VALUE_REQUIRED, '来源，默认 1688,pdd')
            ->addOption('per', null, Option::VALUE_REQUIRED, '每类采集数量，默认 24')
            ->addOption('detail', null, Option::VALUE_REQUIRED, '是否补全详情，1 是 0 否')
            ->addOption('approve', null, Option::VALUE_REQUIRED, '采集后自动审核前 N 条待审核商品，0 表示不审核')
            ->addOption('cate1', null, Option::VALUE_REQUIRED, '自动审核时一级分类名，默认 采集商品')
            ->addOption('cate2', null, Option::VALUE_REQUIRED, '自动审核时二级分类名，不传则按商品池类目')
            ->setDescription('抓取家清/厨房/卫浴/收纳/纸品/一次性用品/小百货类目商品到商品池');
    }

    protected function execute(Input $input, Output $output)
    {
        $categories = trim((string)$input->getOption('categories'));
        $sources = trim((string)$input->getOption('sources'));
        $per = (int)($input->getOption('per') ?: 24);
        $detail = (int)($input->getOption('detail') ?: 1);
        $approve = (int)($input->getOption('approve') ?: 0);
        $cateNameOne = trim((string)($input->getOption('cate1') ?: '采集商品'));
        $cateNameTwo = trim((string)$input->getOption('cate2'));

        $categoryList = $categories !== '' ? array_values(array_filter(array_map('trim', explode(',', $categories)))) : [];
        $sourceList = $sources !== '' ? array_values(array_filter(array_map('trim', explode(',', $sources)))) : ['1688', 'pdd'];

        /** @var ProductCrawlerServices $services */
        $services = app()->make(ProductCrawlerServices::class);
        $result = $services->crawl($categoryList, $per, $sourceList, (bool)$detail);

        $output->info('采集完成');
        $output->info('新增：' . $result['saved'] . '，更新：' . $result['updated'] . '，失败：' . $result['failed']);
        foreach ($result['categories'] as $item) {
            $output->info($item['category_name'] . ' => 新增 ' . $item['saved'] . '，更新 ' . $item['updated'] . '，失败 ' . $item['failed']);
        }

        if ($approve > 0) {
            $output->info('开始自动审核前 ' . $approve . ' 条待审核商品');
            $approveResult = $services->approvePendingItems($approve, [
                'cate_name_one' => $cateNameOne,
                'cate_name_two' => $cateNameTwo,
                'is_show' => 1,
            ]);
            foreach ($approveResult as $item) {
                $output->info('商品池ID ' . $item['id'] . ' => 状态 ' . $item['status'] . '，正式商品ID ' . ($item['product_id'] ?? 0) . '，结果：' . $item['message']);
            }
        }
    }
}
