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

namespace app\adminapi\controller\v1\product;

use app\adminapi\controller\AuthController;
use app\services\product\product\ProductCrawlerServices;
use think\facade\App;

/**
 * 类目采集
 * Class ProductCrawler
 * @package app\adminapi\controller\v1\product
 */
class ProductCrawler extends AuthController
{
    /**
     * @param App $app
     * @param ProductCrawlerServices $services
     */
    public function __construct(App $app, ProductCrawlerServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 预设类目
     * @return mixed
     */
    public function preset()
    {
        return app('json')->success($this->services->getPresets());
    }

    /**
     * 商品池列表
     * @return mixed
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['source', ''],
            ['category_name', ''],
            ['keyword', ''],
            ['store_name', ''],
            ['status', ''],
        ]);
        return app('json')->success($this->services->getList($where));
    }

    /**
     * 执行采集
     * @return mixed
     */
    public function run()
    {
        $data = $this->request->postMore([
            ['categories', []],
            ['per_category', 24],
            ['sources', ['1688', 'pdd']],
            ['with_detail', 1],
        ]);
        $result = $this->services->crawl(
            is_array($data['categories']) ? $data['categories'] : [],
            (int)$data['per_category'],
            is_array($data['sources']) ? $data['sources'] : ['1688', 'pdd'],
            (bool)$data['with_detail']
        );
        return app('json')->success($result);
    }

    /**
     * 删除商品池数据
     * @param int $id
     * @return mixed
     */
    public function delete(int $id)
    {
        $this->services->deletePoolItem($id);
        return app('json')->success('删除成功');
    }

    /**
     * 审核通过并生成正式商品
     * @param int $id
     * @return mixed
     */
    public function approve(int $id)
    {
        $data = $this->request->postMore([
            ['cate_id', []],
            ['cate_name_one', '采集商品'],
            ['cate_name_two', ''],
            ['is_show', 1],
        ]);
        $result = $this->services->approvePoolItem($id, $data);
        return app('json')->success($result);
    }

    /**
     * 审核拒绝
     * @param int $id
     * @return mixed
     */
    public function reject(int $id)
    {
        [$reason] = $this->request->postMore([
            ['reason', ''],
        ], true);
        $this->services->rejectPoolItem($id, (string)$reason);
        return app('json')->success('已拒绝该商品池记录');
    }
}
