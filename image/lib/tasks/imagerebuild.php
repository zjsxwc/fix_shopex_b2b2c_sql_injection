<?php
/**
 * ShopEx licence
 *
 * @copyright  Copyright (c) 2005-2012 ShopEx Technologies Inc. (http://www.shopex.cn)
 * @license  http://ecos.shopex.cn/ ShopEx License
 * @author shopex ecstore dev dev@shopex.cn
 * @version 0.1
 * @package image.lib
 *
 * 这个类实现图片批量重新生成水印
 */

class image_tasks_imagerebuild extends base_task_abstract implements base_interface_task{

    public function exec($params=null)
    {
        //每次最多处理2个
        $limit = 2;
        if($params['filter']['id']=='_ALL_'||$params['filter']['id']=='_ALL_')
        {
            unset($params['filter']['id']);
        }
        $qb = app::get('image')->database()->createQueryBuilder();

        $imageModel = app::get('image')->model('images');
        $rows = $qb->select('id,img_type')->from('image_images')
                   ->where($imageModel->_filter($params['filter']))
                   ->setParameters($imageModel->dbeav_filter->getPrepareParamMarkedValues())
                   ->andWhere('last_modified<='.$qb->createNamedParameter($params['queue_time']))
                   ->setMaxResults($limit)
                   ->orderBy('last_modified', 'desc')
                   ->execute()->fetchAll();

        $objLibImage = kernel::single('image_data_image');
        foreach($rows as $r)
        {
            $objLibImage->rebuild($r['id'],$r['img_type']);
        }
        return app::get('image')->database()->executeQuery('select count(*) as c from image_image where '.$where)->fetchColumn();
    }
}

