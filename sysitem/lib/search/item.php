<?php

class sysitem_search_item  {

    public function __construct()
    {
        $this->objMdlItem = app::get('sysitem')->model('item');
        $this->objMdlItemStatus = app::get('sysitem')->model('item_status');
        $this->objMdlItemCount = app::get('sysitem')->model('item_count');
        $this->itemTable = $this->objMdlItem->table_name(1);
        $this->itemStatustable = $this->objMdlItemStatus->table_name(1);
        $this->itemCountTable = $this->objMdlItemCount->table_name(1);
    }

    public function count($filter)
    {
        $whereSql = $this->__preMysqlSearchFilter($filter);

        $qb = app::get('sysitem')->database()->createQueryBuilder();

        $itemTableAlias = $qb->getConnection()->quoteIdentifier($this->itemTable);
        $itemTableStatusAlias = $qb->getConnection()->quoteIdentifier($this->itemStatustable);
        return $qb->select('count('.$itemTableAlias.'.`item_id`) as _count')
            ->from($this->itemTable, $itemTableAlias)
            ->leftJoin($itemTableAlias, $this->itemStatustable, $itemTableStatusAlias, "{$itemTableAlias}.item_id={$itemTableStatusAlias}.item_id")
            ->where($whereSql)->execute()->fetchColumn();
    }

    //检查传入字段在哪个表中
    public function colsByTable($cols)
    {
        if( $this->objMdlItem->schema['columns'][$cols] )
        {
            return $this->itemTable;
        }
        elseif( $this->objMdlItemStatus->schema['columns'][$cols] )
        {
            return $this->itemStatustable;
        }
        elseif( $this->objMdlItemCount->schema['columns'][$cols] )
        {
            return $this->itemCountTable;
        }
    }

    private function __getSelectColumns($columns)
    {
        if( $columns == '*' ) return  $columns;

        $returnCols = array();
        foreach( explode(',', $columns) as $column )
        {
            $table = $this->colsByTable($column);
            if( !$table )
            {
                $returnCols[] = $column;
            }
            else
            {
                $returnCols[] = $table.'.'.$column;
            }
        }

        if( empty($returnCols) ) return '*';

        return implode(',', $returnCols);
    }

    public function getList($cols='*', $filter=array(), $offset=0, $limit=-1, $orderBy=null, $groupBy='')
    {
        $limit = (int)$limit < 0 ? 100000 : $limit;

        $whereSql = $this->__preMysqlSearchFilter($filter);

        $qb = app::get('sysitem')->database()->createQueryBuilder();

        $itemTableAlias = $qb->getConnection()->quoteIdentifier($this->itemTable);
        $itemTableStatusAlias = $qb->getConnection()->quoteIdentifier($this->itemStatustable);
        $itemTableCountAlias = $qb->getConnection()->quoteIdentifier($this->itemCountTable);

        //需要返回的字段，格式化将返回的字段对应到字段的表中
        $columns = $this->__getSelectColumns($cols);

        $qb->select($columns)
            ->from($this->itemTable, $itemTableAlias)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->leftJoin($itemTableAlias, $this->itemStatustable, $itemTableStatusAlias, "{$itemTableAlias}.item_id={$itemTableStatusAlias}.item_id")
            ->leftJoin($itemTableAlias, $this->itemCountTable, $itemTableCountAlias, "{$itemTableAlias}.item_id={$itemTableCountAlias}.item_id")
            ->where($whereSql);

        empty($groupBy) ?: $qb->groupBy($groupBy);

        if ($orderBy)
        {
            $orderBy = is_array($orderBy) ? implode(' ', $orderBy) : $orderBy;
            array_map(function($o) use (&$qb){
                @list($sort, $order) = explode(' ', trim($o));
                $sort = $this->colsByTable($sort) ? $this->colsByTable($sort).'.'.$sort : $sort;
                $qb->addOrderBy($sort, $order);
            }, explode(',', $orderBy));

            //$qb->addOrderBy($this->itemTable.'.item_id', 'desc');
        }
        $rows = $qb->execute()->fetchAll();

        $this->objMdlItem->tidy_data($rows, $cols);
        return $rows;
    }

    /**
     * 对使用mysql的搜索进行条件处理
     *
     */
    private function __preMysqlSearchFilter($filter)
    {
        if( $filter['prop_index'] )
        {
            $filter = $this->__prePropIndex($filter['prop_index'], $filter);
        }

        if( $filter['search_keywords'] )
        {
            $filter['title|has'] = $filter['search_keywords'];
        }

        if(isset($filter['shop_name']))
        {
            $shopIds = app::get('sysitem')->rpcCall('shop.get.search',['shop_name'=>$filter['shop_name'],'fields'=>'shop_id']);
            if( $shopIds )
            {
                $filter['shop_id'] = array_column($shopIds,'shop_id');
            }
            else
            {
                $filter['shop_id'] = '-1';
            }
            unset($filter['shop_name']);
        }

        if(isset($filter['cat_name']))
        {
            $catIds = app::get('sysitem')->rpcCall('category.cat.get.info',['cat_name'=>$filter['cat_name'],'level'=>3,'fields'=>'cat_id']);
            if( $catIds )
            {
                $filter['cat_id'] = key($catIds);
            }
            else
            {
                $filter['cat_id'] = '-1';
            }
            unset($filter['cat_name']);
        }

        if(isset($filter['brand_name']))
        {
            $brandIds = app::get('sysitem')->rpcCall('category.brand.get.list',['brand_name'=>$filter['brand_name'],'fields'=>'brand_id']);
            if( $brandIds )
            {
                $filter['brand_id'] = array_column($brandIds,'brand_id');
            }
            else
            {
                $filter['brand_id'] = '-1';
            }
            unset($filter['brand_name']);
        }

        $whereSql = '';
        if(isset($filter['shop_cat_id']) && is_array($filter['shop_cat_id']))
        {
            foreach($filter['shop_cat_id'] as $key=>$value)
            {
                $shopCatWhere[] = " (shop_cat_id like '%".$value."%')";
            }
            unset($filter['shop_cat_id']);
            $whereSql = ' AND ('.implode($shopCatWhere,' or ').')';
        }

        if( isset($filter['approve_status']) && $filter['approve_status'] )
        {
            if(isset($filter['approve_status']) && $filter['approve_status'])
            {
                $whereSql .= ' AND '.$this->itemStatustable.'.`approve_status` = "'.str_replace('"','\\"',$filter['approve_status']).'"';
            }
            unset($filter['approve_status']);
        }

        $sql = $this->objMdlItem->_filter($filter).$whereSql;

        return $sql;
    }

    /**
     * 处理自然属性搜索
     *
     * @param array $propIndex
     * @param array $filter
     */
    private function __prePropIndex($propIndex, $filter)
    {
        $objMdlItemNatureProps = app::get('sysitem')->model('item_nature_props');

        $propIndexFilter['prop_value_id'] = array();
        foreach( (array)$propIndex as $propId=>$propIndex )
        {
            $propIndexFilter['prop_value_id'] = array_merge($propIndexFilter['prop_value_id'], $propIndex);
            $count[$propId] = 1;
        }

        $qb = app::get('sysitem')->database()->createQueryBuilder();
        $qb->select('item_id')
            ->from('sysitem_item_nature_props')
            ->where($objMdlItemNatureProps->_filter($propIndexFilter))
            ->groupBy('item_id')
            ->having('count(item_id)>='.count($count));

        $data = $qb->execute()->fetchAll();
        if( empty($data) )
        {
            $filter['item_id'] = array('-1');
        }
        else
        {
            foreach( $data as $row )
            {
                $filter['item_id'][] = $row['item_id'];
            }
        }
        return $filter;
    }
}

