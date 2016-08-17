<?php
/**
 * ShopEx licence
 *
 * @copyright  Copyright (c) 2005-2010 ShopEx Technologies Inc. (http://www.shopex.cn)
 * @license  http://ecos.shopex.cn/ ShopEx License
 */

/**
 * dbeav_model
 * App
 *
 * @uses modelFactory
 * @package
 * @version $Id$
 * @copyright 2003-2007 ShopEx
 * @author Ever <ever@shopex.cn>
 * @license Commercial
 */
class dbeav_model extends base_db_model
{

    //dbschema tableName
    var $dbschema = null;
    var $api_id = null;

    protected $defaultOrder;

    function events(){}

    function _columns()
    {
        //echo '###'.PHP_EOL;
        return $this->schema['columns'];
    }

    function count($filter=null)
    {
        $total = $this->database()->createQueryBuilder()
            ->select('count(*) as _count')->from($this->table_name(true))->where($this->_filter($filter))
            ->execute()->fetchColumn();

        return $total;
    }

    function getList($cols='*', $filter=array(), $offset=0, $limit=-1, $orderBy=null)
    {
        //todo:  临时兼容
        if ($filter == null) $filter = array();
        if (!is_array($filter)) throw new \InvalidArgumentException('filter param not support not array');

        $offset = (int)$offset<0 ? 0 : $offset;
        $limit = (int)$limit < 0 ? 100000 : $limit;
        $orderBy = $orderBy ? $orderBy : $this->defaultOrder;

        $qb = $this->database()->createQueryBuilder();
        $qb->select($cols)
            ->from($this->table_name(1))
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $qb->where($this->_filter($filter));
        // todo: 统一orderby的写法. 目前同时支持array和string
        if ($orderBy)
        {
            $orderBy = is_array($orderBy) ? implode(' ', $orderBy) : $orderBy;
            array_map(function($o) use (&$qb){
                $permissionOrders = ['asc', 'desc', ''];
                @list($sort, $order) = explode(' ', trim($o));
                if (!in_array(strtolower($order), $permissionOrders)  ) throw new \InvalidArgumentException("getList order by do not support {$order} ");
                $qb->addOrderBy($qb->getConnection()->quoteIdentifier($sort), $order);
            }, explode(',', $orderBy));
        }

        $stmt = $qb->execute();
        $data = $stmt->fetchAll();
        //执行的sql
        //$sql = $qb->getSQL();

        $this->tidy_data($data, $cols);

        return $data;
    }

    function getRow($cols='*', $filter=array(), $orderType=null){
        $data = $this->getList($cols, $filter, 0, 1, $orderType);
        if($data){
            return $data['0'];
        }else{
            return $data;
        }
    }

    /**
     * filter
     *
     * 因为parent为反向关联表. 因此通过 _getPkey(), 反向获取关系. 并删除
     *
     * @param array $filter
     * @param misc $subSdf
     */
    function _filter($filter = array()){
        if ($filter == null) $filter = array();

        $dbeav_filter = kernel::single('dbeav_filter');
        $dbeav_filter_ret = $dbeav_filter->dbeav_filter_parser($filter,$this);
        return $dbeav_filter_ret;
    }


    public function insert(&$data){
        $ret = parent::insert($data);
        return $ret;
    }

    /**
     * 删除parent表
     *
     * 因为parent为反向关联表. 因此通过 _getPkey(), 反向获取关系. 并删除
     *
     * @param array $filter
     * @param misc $subSdf
     */
    private function deleteParent($filter)
    {
        $allHas = (array)$this->has_parent;
        foreach( $allHas as $k => $v )
        {
            // image_attach@image:contrast:goods_id^target_id
            list($relatedModelString, $relatedType, $relatedRelation) = explode(':',$allHas[$k]);
            list($relatedModelName, $relatedModelAppId) = explode('@', $relatedModelString);
            $relatedModelAppId = $relatedModelAppId ?: $this->app->app_id;
            $relatedModel = app::get($relatedModelAppId)->model($relatedModelName);
            // 转换当前表的filter为parent表的filter
            $pkey = $relatedModel->_getPkey($this->table_name(), $relatedRelation, $this->app->app_id);
            // 如果找不到 表 关联关系. 则放弃
            $tFilter = $filter[$pkey['c']] ? [$pkey['p']=>$filter[$pkey['c']]] : [];
            $relatedModel->delete($tFilter);
        }
    }
    /**
     * 删除关联表
     *
     * 根据subSdf进行删除关联表
     * 如果subSdf每条记录的key, 在has_many/has_one中进行了关联, 则统统删除
     *
     * @param array $filter
     * @param misc $subSdf
     */

    private function deleteDepends($filter, $subSdf)
    {
        // 获取model的subsdf
        if( $subSdf && !is_array( $subSdf ) ){
            $subSdf = $this->getSubSdf($subSdf);
        }
        $allHas = array_merge( (array)$this->has_many,(array)$this->has_one );
        foreach( (array)$subSdf as $k => $v ){
            // 如果subsdf的键值 在 has_many和has_one 逻辑中找得到
            if( array_key_exists( $k, $allHas ) ){
                list($relatedModelString, $relatedType, $relatedRelation) = explode(':',$allHas[$k]);;
                list($relatedModelName, $relatedModelAppId) = explode('@', $relatedModelString);
                $relatedModelAppId = $relatedModelAppId ?: $this->app->app_id;
                $pkey = $this->_getPkey($relatedModelName, $relatedRelation, $relatedModelAppId);
                // 找到filter对应行 的所有主键值. 并作为筛选条件塞回 filter
                if ($pks = $this->get_pk_list($filter)) $filter[$this->idColumn] = $pks;
                $tFilter = $filter[$pkey['p']] ? [$pkey['c']=>$filter[$pkey['p']]] : [];
                $relatedModel = app::get($relatedModelAppId)->model($relatedModelName);
                $relatedModel->delete($tFilter);
            }
        }
    }

	/**
	 * 删除数据
	 *
     * 1. 删除当前model对应表
     * 2. 删除当前model对应关联 has_parent 表. has_parent表为反向关联表
     * 3. 删除当前model对应关联 has_many/has_one 表. has_many/has_one表为正向关联表
	 *
	 * @param  array
	 * @param  array $filter
	 * @return string $subSdf
	 */
    public function delete($filter,$subSdf = 'delete')
    {
        if (!$filter) return false;
        // 获取所有 model has_parent 属性
        $this->deleteParent($filter);
        $this->deleteDepends($filter, $subSdf);
        return parent::delete($filter);
    }

    /**
     * 更新数据
     *
     * @param array $data
     * @param array $filter
     * @param misc $mustUpdate
     *
     * @return string
     */
    public function update($data,$filter=array(),$mustUpdate = null)
    {
        return parent::update($data,$filter,$mustUpdate);
    }

	/**
	 * 获取通过filter遍历表得到的所有行主键值数组
	 *
	 * @param  array  $filter
	 *
	 * @return array
	 */
    private function get_pk_list($filter)
    {
        $rows = $this->getList($this->idColumn,$filter);
        return $rows ? array_column($rows, $this->idColumn) : [];
    }


  	/**
	 * 保存数据
	 *
	 * 1. 保存parent数据
	 * 2. 保存当前model对应表数据
	 * 3. 保存depends(关联表)数据
	 *
	 * @var bool
	 */
    public function save(&$data,$mustUpdate = null, $mustInsert=false)
    {
        // 对于model的has_parent 进行处理
        $this->saveParent($data,$mustUpdate, $mustInsert);
        // 将sdf数据转换成array .
        $plainData = $this->sdfToPlain($data);
        // 保存本model的数据

        if(!$this->db_save($plainData,($mustUpdate?$this->sdfToPlain($mustUpdate):null),$mustInsert ))
        {
            return false;
        }

        // 如果 主键 不是数组
        if(!is_array($this->idColumn))
        {
            // 如果 主键 列没有值. 则 把saveParent 返回的 主键对应的值取出

            if(!$data[$this->idColumn])
            {
                $data[$this->idColumn] = $plainData[$this->idColumn];
            }
            // 保存 depends 依赖关系
            $this->saveDepends($data,$mustUpdate );
        }
        $plainData = null;
        return true;
    }

  	/**
	 * 保存model中has_parent属性定义的表
	 *
	 * @var bool
	 */
    private function saveParent( &$data,$mustUpdate, $mustInsert=false )
    {
        foreach( (array)$this->has_parent as $k => $v )
        {
            // 如果保存的数据中不存在 has_parent 属性定义的字段
            // 那么跳过处理
            if( !isset($data[$k]) )continue;
            // 获取has_parent 属性中定义的关联表 的 model name 和其 对应的app
            list($parentModelName, $parentModelApp) = explode( '@', $v );

            // parent表对应的model
            $model = app::get($parentModelApp?$parentModelApp:$this->app->app_id)->model($parentModelName);

            // parent表save
            $model->save($data[$k],$mustUpdate,$mustInsert);
            // 遍历当前model对应的表的字段
            foreach($this->_columns() as $colName => $colDefine)
            {
                // 如果当前表有字段 正好关联 对应的parent model
                if( in_array( $colDefine['type'],array('table:'.$parentModelName,'table:'.$parentModelName.'@'.($parentModelApp?$parentModelApp:$this->app->app_id)) ) )
                {
                    // 将parent表生成的id, 作为主表id.
                    // todo: 这一步的判断也没啥用, 因为后边马上要进行sdfToPlain 逻辑
                    if( $colDefine['sdfpath'] )
                    {
                        array_set($data, $this->convertPath($colDefine['sdfpath']), $data[$k][$model->idColumn]);
                    }
                    else
                    {
                        $data[$colName] = $data[$k][$model->idColumn];
                    }
                    break;
                }
            }
        }
    }


	/**
	 * 路径转换, 将path方式路径转换为array_get的方式
	 * a/b/c 替换成 a.b.c
	 *
	 * @param  \Closure  $callback
	 * @return string
	 */
    private function convertPath($path)
    {
        return str_replace('/', '.', $path);
    }

  	/**
	 * 当saveDepends时, 针对关联关系类型, 对关联表所做的删除处理
	 *
	 * @param dbeav_model $relatedModel
	 */
    private function processDependByRelatedType($relatedModel, $relatedType, $itemdata, $relatedFilter)
    {
        switch( $relatedType)
        {
            case 'contrast':
                $relatedIdColumns = (array)$relatedModel->idColumn;
                // 获取实际存在的 关联表行数据的 主键
                $repIds = (array)$relatedModel->getList(implode(',', $relatedIdColumns), $relatedFilter, 0, -1);
                if ($repIds)
                {
                    array_walk($itemdata, function($item, $key) use ($relatedIdColumns, &$repIds) {
                        $defaultDataId = array();
                        foreach($relatedIdColumns as $idColumn)
                        {
                            $defaultDataId[$idColumn] = $item[$idColumn];
                        }
                        // 如果数据库中存在 即将要保存的关联数据, 那么就删除 repId的对应 ids
                        if(($hasDefId = array_search( $defaultDataId, $repIds )) !== false)
                        {
                            unset( $repIds[$hasDefId] );
                        }
                    });
                    foreach( (array)$repIds as $repId ) $relatedModel->delete($repId);
                }
                break;
            case 'replace':
                // 替换模式, 先把关联表对应的数据 干掉
                $relatedModel->delete($relatedFilter);
                break;
        }

    }

  	/**
	 * 保存depends表(关联表)
	 *
	 * @var bool
	 */
    private function saveDepends(&$data,$mustUpdate = null, $mustInsert=false)
    {

        // 合并 has_many 和 has_one 统一处理
        foreach( array_merge( (array)$this->has_many,(array)$this->has_one ) as $mk => $mv )
        {
            $path = $this->convertPath($mk);
            // 如果存在关联表数据
            if( $itemdata = array_get($data, $path, false) )
            {
                $itemdata = (array)$itemdata;
                if( !isset( $this->has_many[$mk] ) ) $itemdata = array($itemdata );
                // image_attach@image:contrast:goods_id^target_id
                list($relatedModelString, $relatedType, $relatedRelation) = explode(':',$mv);
                list($relatedModelName, $relatedModelAppId) = explode('@', $relatedModelString);
                $relatedModelAppId = $relatedModelAppId ?: $this->app->app_id;
                $relatedModel = app::get($relatedModelAppId)->model($relatedModelName);

                $pkey = $this->_getPkey($relatedModelName, $relatedRelation, $relatedModelAppId);

                // 外键表关联主键值
                $relatedFilter = [$pkey['c'] => $data[$pkey['p']]];
                // 将主表主键值作为即将保存的关联表的外键值
                $itemdata = array_map(function($item) use ($pkey, $relatedFilter) {
                    $item = array_merge($item, $relatedFilter);
                    return $item;
                }, $itemdata);

                // 根据关联类型定义, 对关联表数据进行删除处理
                $this->processDependByRelatedType($relatedModel, $relatedType, $itemdata, $relatedFilter);

                // 遍历当前关联表 的 关联数据
                foreach( (array)$itemdata as $mconk => $mconv )
                {
                    $relatedModel->save($mconv, null, $mustInsert);
                    array_set($data, "{$path}.{$mconk}", $mconv);
                }

                unset($relatedModel);
            }
        }
    }

    function dump($filter,$field = '*',$subSdf = null)
    {
        // 如果$filter为空, 或者$filter为空数则, 那么返回 null
        if( !$filter || (is_array($filter) && count($filter) ==1 && !current($filter)))return null;
        // 如果$filter不是数组, 那么默认当$filter为主键ID值, 重新生成新的$filter
        if( !is_array( $filter ) ) $filter = array( $this->idColumn=>$filter );

        if(!($data = $this->db_dump($filter,$field))) return null;

        // 把$data数据转换成sdf数据
        $redata = $this->plainToSdf($data);
        if( $subSdf && !is_array( $subSdf ) )
        {
            $subSdf = $this->getSubSdf($subSdf);
        }
        // 如果存在subsdf, 则dump
        if($subSdf){
            $this->_dump_depends($data,$subSdf,$redata);
        }
        $columnDefines = $this->_columns();
        // todo: 以前的版本有unfield逻辑, 实际上没有地方用过. 暂时去掉
        return $redata;
    }

    function _dump_depends(&$data,$subSdf,&$redata)
    {
        //$allHas
        $allHas = array_merge((array)$this->has_many, (array)$this->has_one);
        // 遍历$subSdf

        foreach( (array)$subSdf as $subSdfKey => $subSdfVal )
        {
            // array('*', 'default', array(0, -1, 'order_id')
            @list($relatedField, $relatedSubSdf, $relatedSqlStatement) = $subSdfVal;
            $relatedFilter = null;
            if( isset($allHas[$subSdfKey]))
            {
                list($relatedModelString, $relatedType, $relatedRelation) = explode(':',$allHas[$subSdfKey]);
                list($relatedModelName, $relatedModelAppId) = explode('@', $relatedModelString);
                $relatedModelAppId = $relatedModelAppId ?: $this->app->app_id;
                $pkey = $this->_getPkey($relatedModelName, $relatedRelation, $relatedModelAppId);
                $relatedFilter[$pkey['c']] = $data[$pkey['p']];

                // todo: 老版本支持model提供'_dump_depends_'.$relatedModelName调用, 暂时去掉, 需要再加
                $relatedModel = app::get($relatedModelAppId)->model($relatedModelName);

                $basePath = $this->convertPath($subSdfKey);
                if ($this->has_one[$subSdfKey])
                {
                    if ($aIdArray = $this->getRow(implode(',',(array)$relatedModel->idColumn), $relatedFilter, $orderby))
                    {
                        $subDump = $relatedModel->dump($aIdArray,$relatedField,$relatedSubSdf);
                        array_set($redata, $basePath, $subDump);
                    }
                }
                elseif($this->has_many[$subSdfKey])
                {
                    @list($start, $limit, $orderBy) = $relatedSqlStatement;
                    $start = $start ?: 0;
                    $limit = $limit ?: -1;
                    $orderBy = $orderBy ?: null;

                    $idArray = $relatedModel->getList(implode(',',(array)$relatedModel->idColumn), $relatedFilter, $start, $limit, $orderBy);
                    if ($idArray)
                    {
                        $i = 0;
                        foreach( $idArray as $aIdArray ){
                            $subDump = $relatedModel->dump($aIdArray,$relatedField,$relatedSubSdf);
                            $path = sprintf('%s.%s', $basePath, $i);
                            array_set($redata, $path, $subDump);
                            $i++;
                        }
                    }
                }
            }
            else if( strpos( $subSdfKey,':' ) !== false )
            {
                @list($subSdfKey, $relatedModelString) = explode(':', $subSdfKey);
                @list($relatedModelName, $relatedModelAppId) = explode('@', $relatedModelString);
                $relatedModelAppId = $relatedModelAppId ?: $this->app->app_id;
                $relatedModel = app::get($relatedModelAppId)->model($relatedModelName);

                $pkey = $relatedModel->_getPkey($this->table_name(), null, $this->app->app_id);

                $relatedFilter[$pkey['p']] = $data[$pkey['c']];

                if (!$subSdfKey)
                {
                    $relatedModelColDefines = $relatedModel->_columns();
                    if ($sdfpath = $relatedModelColDefines[$pkey['p']]['sdfpath'])
                    {
                        $subSdfkey = substr($sdfpath, 0, strpos($sdfpath, '/'));
                    }
                    else
                    {
                        $subSdfKey = $relatedModelName;
                    }
                }
                $redata[$subSdfKey] = $relatedModel->dump($relatedFilter,$relatedField,$relatedSubSdf);

            }

            unset($relatedModel);
        }
    }


	/**
	 * 获取主键和外键
	 *
	 * 1. 如果存在 $cCol, 则按照$cCol的规则返回主键和外键
	 * 2. 如果不存在 $cCol, 则获取对应$appId下的$tableName表的定义,
	 *    找到是否有关联当前model表的关系. 当前model对应表的主键为返回主键,
	 *    对应$appId下的$tableName表的主键为返回外键
	 *
	 * @param string $tableName
	 * @param string $cCol
	 * @param string $appId
	 * @return array
	 */
    private function _getPkey($tableName,$cCol,$appId)
    {
        if ($cCol)
        {
            $pkeyAndfkey = explode('^', $cCol);

            if (count($pkeyAndfkey) != 2)
            {
                throw new \InvalidArgumentException("Param cCol:{$cCol} not invalid");
            }
            return ['p' => $pkeyAndfkey[0], 'c' => $pkeyAndfkey[1]];
        }
        else
        {
            $baseTableString = 'table:'.$this->table_name();
            $appId = $appId ?: $this->app->app_id;
            $relatedModel = app::get($appId)->model($tableName);
            foreach($relatedModel->_columns() as $relatedColumnName => $relatedColumnDefine)
            {
                $relatedColumnType = $relatedColumnDefine['type'];
                if ($relatedColumnType == $baseTableString || $relatedColumnType == $baseTableString.'@'.$this->app->app_id)
                {
                    return ['p'=>$this->idColumn, 'c'=>$relatedColumnName];
                }
            }
            throw new \OutOfBoundsException(sprintf("Table %s not related table: %s", $relatedModel->table_name(), $this->table_name()));
        }
    }

	/**
	 * 将sdf数据转换成array
	 *
	 * 根据dbschema字段定义的sdfpath属性, 将sdf转换为array
	 *
	 * @param  array  $data
	 * @return string
	 */
    protected function sdfToPlain($data,$appends=false)
    {
        $columnDefines = $this->_columns();
        array_walk($columnDefines, function($columnDefine, $columnName) use ($data, &$return){
            $path = $this->convertPath($columnDefine['sdfpath']?$columnDefine['sdfpath']:$columnName);
            if (($value = array_get($data, $path, false)) !== false) $return[$columnName] = $value;
        });

        return $return;
    }

	/**
	 * 将array数据转换成sdf
	 *
	 * @param  array  $data
	 * @return string
	 */
    protected function plainToSdf($data,$appends=false)
    {
        $columnDefines = $this->_columns();

        array_walk($columnDefines, function($columnDefine, $columnName) use ($data, &$return){
            $path = $this->convertPath($columnDefine['sdfpath']?$columnDefine['sdfpath']:$columnName);
            if (isset($data[$columnName]))
            {
                array_set($return, $path, $data[$columnName]);
            }
        });

        return $return;
    }

	/**
	 * 获取对应key的model的subsdf属性
	 *
	 * @param  string $key
	 * @return string
	 */
    function getSubSdf($key)
    {
        // 获取对应key的model的subsdf属性
        if(array_key_exists($key,(array)$this->subSdf))
        {
            return $this->subSdf[$key];
        }
        // 如果取不到则取默认 default的值
        elseif( $this->subSdf['default'] )
        {
            return $this->subSdf['default'];
        }
        // 如果连默认的default的subsdf也取不到
        // 拼接主表所有关联表的subsdf 返回
        // ps: 其实就是将model has_many 和 has_one 属性数组合并返回
        else
        {
            $subSdf = array();
            foreach( array_merge( (array)$this->has_many, (array)$this->has_one ) as $k => $v ){
                $subSdf[$k] = array('*');
            }
            return $subSdf?$subSdf:null;
        }
    }

    function batch_dump($filter,$field = '*',$subSdf = null,$start=0,$limit=20,$orderType = null ){
        $aId = $this->getList( implode( ',', (array)$this->idColumn ), $filter,$start,$limit,$orderType );
        $rs = array();
        foreach( $aId as $id ){
            $rs[] = $this->dump( $id,$field,$subSdf );
        }
        return $rs;
    }
    function searchOptions(){
        $columns = array();
        foreach($this->_columns() as $k=>$v){
            if(isset($v['searchtype']) && $v['searchtype']){
                $columns[$k] = $v['label'];
            }
        }
        return $columns;
    }
}
