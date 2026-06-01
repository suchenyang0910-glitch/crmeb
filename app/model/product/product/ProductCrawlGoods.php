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

namespace app\model\product\product;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

/**
 * 类目采集商品池
 * Class ProductCrawlGoods
 * @package app\model\product\product
 */
class ProductCrawlGoods extends BaseModel
{
    use ModelTrait;

    /**
     * 主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 表名
     * @var string
     */
    protected $name = 'product_crawl_goods';

    /**
     * 搜索来源
     * @param Model $query
     * @param $value
     */
    public function searchSourceAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('source', $value);
        }
    }

    /**
     * 搜索类目
     * @param Model $query
     * @param $value
     */
    public function searchCategoryNameAttr($query, $value)
    {
        if ($value !== '') {
            $query->whereLike('category_name', '%' . trim($value) . '%');
        }
    }

    /**
     * 搜索关键字
     * @param Model $query
     * @param $value
     */
    public function searchKeywordAttr($query, $value)
    {
        if ($value !== '') {
            $query->whereLike('keyword', '%' . trim($value) . '%');
        }
    }

    /**
     * 搜索商品名称
     * @param Model $query
     * @param $value
     */
    public function searchStoreNameAttr($query, $value)
    {
        if ($value !== '') {
            $query->whereLike('store_name', '%' . trim($value) . '%');
        }
    }

    /**
     * 搜索处理状态
     * @param Model $query
     * @param $value
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('status', (int)$value);
        }
    }

    /**
     * 搜索删除状态
     * @param Model $query
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        $query->where('is_del', $value === '' ? 0 : (int)$value);
    }

    /**
     * 轮播图获取器
     * @param $value
     * @return array
     */
    public function getSliderImageAttr($value)
    {
        return is_string($value) && $value !== '' ? json_decode($value, true) : [];
    }

    /**
     * 规格获取器
     * @param $value
     * @return array
     */
    public function getAttrsAttr($value)
    {
        return is_string($value) && $value !== '' ? json_decode($value, true) : [];
    }

    /**
     * 原始数据获取器
     * @param $value
     * @return array
     */
    public function getSourceDataAttr($value)
    {
        return is_string($value) && $value !== '' ? json_decode($value, true) : [];
    }
}
