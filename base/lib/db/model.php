<?php
/**
 * ShopEx licence
 *
 * @copyright  Copyright (c) 2005-2010 ShopEx Technologies Inc. (http://www.shopex.cn)
 * @license  http://ecos.shopex.cn/ ShopEx License
 */

use \Doctrine\DBAL\Exception\UniqueConstraintViolationException as UniqueConstraintViolationException;
use \Doctrine\DBAL\Exception\NotNullConstraintViolationException as NotNullConstraintViolationException;

abstract class base_db_model implements base_interface_model
{
    /**
     * @var \app
     */
    public $app;

    private $table_define;

    private $__exists_schema = [];

    /**
     * model初始化
     *
     * @param \app $app  
     */
    function __construct($app){
        $this->app = $app;
        $this->schema = $this->get_schema();
        $this->idColumn = $this->schema['idColumn'];
        $this->textColumn = $this->schema['textColumn'];
        if(!is_array( $this->idColumn ) && array_key_exists( 'autoincrement',$this->schema['columns'][$this->idColumn]))
        {
            $this->idColumnAutoincrement = $this->schema['columns'][$this->idColumn]['autoincrement'];
        }
    }

    

    /**
     * 获取base_application_dbtable 对象
     *
     * @param \app $app  
     */
    public function getTableDefine()
    {
        if (!$this->table_define) $this->table_define = new base_application_dbtable;
        return $this->table_define;
    }

    public function database()
    {
        return $this->app->database();
    }

    public function table_name($real=false){
        $class_name = get_class($this);
        $table_name = substr($class_name,5+strpos($class_name,'_mdl_'));
        if($real){
            return $this->app->app_id.'_'.$table_name;
        }else{
            return $table_name;
        }
    }

    /**
     * 处理输出数据
     *
     * 因为parent为反向关联表. 因此通过 _getPkey(), 反向获取关系. 并删除
     *
     * @param array $filter
     * @param misc $subSdf
     */
    public function tidy_data(&$rows, $cols='*')
    {
        if($rows)
        {
            // 目前不支持 字段别名
            $useColumnKeys = array_keys($rows[0]);
            $columnDefines = $this->_columns();

            foreach($useColumnKeys as $columnKey)
            {
                $columnType = $columnDefines[$columnKey]['type'];

                if ($func = kernel::single('base_db_datatype_manage')->getDefineFuncOutput($columnType))
                {
                    array_walk($rows, function(&$row, $func) use ($func, $columnKey){
                        $row[$columnKey] = call_user_func($func, $row[$columnKey]);
                    });
                }
            }

            return $rows;
            
        }
    }//End Function
    
    public function get_schema()
    {
        $table = $this->table_name();
        if(!isset($this->__exists_schema[$this->app->app_id][$table])){
            $this->__exists_schema[$this->app->app_id][$table] = $this->getTableDefine()->detect($this->app,$table)->load();
        }
        return $this->__exists_schema[$this->app->app_id][$table];
    }

    function searchOptions()
    {
        $columns = array();
        foreach($this->_columns() as $k=>$v)
        {
            if(isset($v['searchtype']) && $v['searchtype']){
                $columns[$k] = $v['label'];
            }
        }
        return $columns;
    }


	/**
	 * replace 
	 *
	 * @param array $data
	 * @param array $filter
	 * @return mixed
	 */
    public function replace($data,$filter)
    {
        // todo: 现在逻辑简单, 但是对于Exception的处理上会有问题 
        if ($return = $this->insert($data)===false)
        {
            $return = $this->update($data, $filter);
        }
        return $retuen;
    }

    private function prepareUpdateData($data)
    {
        return $this->prepareUpdateOrInsertData($data);
    }

    private function prepareInsertData($data)
    {
        return $this->prepareUpdateOrInsertData($data);
    }


    private function prepareUpdateOrInsertData($data)
    {
        $columnDefines = $this->_columns();
        $return = [];
        array_walk($columnDefines, function($columnDefine, $columnName) use (&$return, $data) {

            if ($func = kernel::single('base_db_datatype_manage')->getDefineFuncInput($columnDefine['type']))
            {
                if ($funcResult = call_user_func($func, $data[$columnName]))
                {
                    $return[$this->database()->quoteIdentifier($columnName)] = $funcResult;
                }
                else return;
            }
            elseif ($columnDefine['required'] && ($data[$columnName] === '' || is_null($data[$columnName])))
            {
                return;
            }
            elseif (!isset($data[$columnName]))
            {
                return;
            }
            else
            {
                if(is_array($data[$columnName])) $data[$columnName] = serialize($data[$columnName]);
                
                $return[$this->database()->quoteIdentifier($columnName)] = $data[$columnName];
            }
        });
        return $return;
    }

    /**
     * 获取lastInsertId
     *
     * @param integer|null $data
     * @param integer|null
     */
    public function lastInsertId($data = null)
    {
        if ($this->idColumnAutoincrement)
        {
            $insertId = $this->database()->lastInsertId();
        }
        else
        {
            if (!is_array($this->idColumn))
            {
                $insertId = isset($data[$this->idColumn]) ? $data[$this->idColumn] : null;
            }
            else
            {
                $insertId = null;
            }
        }
        return $insertId;
    }

    /**
     * 检测inser条数据, 是否有必填数据没有处理t
     *
     * @param integer|null $data
     * @param integer|null
     */
    public function checkInsertData($data)
    {
        foreach($this->_columns() as $columnName => $columnDefine)
        {
            if(!isset($columnDefine['default']) && $columnDefine['required'] && $columnDefine['autoincrement']!=true)
            {
                // 如果当前没有值, 那么抛错
                if(!isset($data[$columnName]))
                {
                    throw new \InvalidArgumentException(($columnDefine['label']?$columnDefine['label']:$columnName).app::get('base')->_('不能为空！'));
                }
            }
        }
    }
    
    /**
	 * 插入数据 
	 *
	 * @var array $data
     @ @return integer|bool 
	 */
    public function insert(&$data)
    {
        $this->checkInsertData($data);
        $prepareUpdateData = $this->prepareInsertData($data);
        $qb = $this->database()->createQueryBuilder();

        $qb->insert($this->database()->quoteIdentifier($this->table_name(1)));

        array_walk($prepareUpdateData, function($value, $key) use (&$qb) {
            $qb->setValue($key, $qb->createPositionalParameter($value));
        });
        
        try {
            $stmt = $qb->execute();
        }
        // 主键重
        catch (UniqueConstraintViolationException $e) 
        {
            return false;
        }

        $insertId = $this->lastInsertId($data);
        if ($this->idColumnAutoincrement)
        {
            $data[$this->idColumn] = $insertId;
        }
        
        return isset($insertId) ? $insertId : true;
    }
    


    /**
     * delete
     *
     * @param mixed $filter
     * @param mixed $named_action
     * @access public
     * @return void
     */
    public function delete($filter)
    {
        $qb = $this->database()->createQueryBuilder();
        $qb->delete($this->database()->quoteIdentifier($this->table_name(1)))
           ->where($this->_filter($filter));

        return $qb->execute() ? true : false;
    }

    /**
     * delete
     *
     * @param mixed $filter
     * @param mixed $named_action
     * @access public
     * @return void
     */
    public function update($data, $filter, $mustUpdate=null)
    {
        if (count((array)$data)==0) return true;
        $prepareUpdateData = $this->prepareUpdateData($data);
        $qb = $this->database()->createQueryBuilder();
        $qb->update($this->database()->quoteIdentifier($this->table_name(1)))
           ->where($this->_filter($filter));

        array_walk($prepareUpdateData, function($value, $key) use (&$qb) {
            $qb->set($key, $qb->createPositionalParameter($value));
        });
        $stmt = $qb->execute();
        

        return $stmt>0?$stmt:true;
    }
    
    /*
     *对数据库结构数据save
     *$dbData db结构
     *$mustUpdate db结构
     */
    final public function db_save(&$dbData,$mustUpdate=null, $mustInsert=false) {


        // 默认方式为 
        $doMethod = 'update';
        $filter = array();

        // 如果save数据中主键为空, 则改方式为insert
        // todo: 如果是多主键的时候会有bug
        foreach( (array)$this->idColumn as $idv ){
            if( !$dbData[$idv] ){
                $doMethod = 'insert';
                break;
            }
            // 组织filter
            // 将要保存数据中的主键对应值取出, 做为filter的一个条件
            $filter[$idv] = $dbData[$idv];
        }


        // 如果非强制insert 并且 save方式为update 并且 能找到相关记录, 那么进行update 
        if(!$mustInsert && $doMethod == 'update' && $a = $this->getRow(implode(',',(array)$this->idColumn), $filter))
        {
            return $this->update($dbData,$filter,$mustUpdate);
        }

        // 否则insert数据
        return $this->insert($dbData);
    }

    final public function db_dump($filter,$field = '*')
    {
        if(!isset($filter))return null;
        if( !is_array( $filter ) )
            $filter = array( $this->idColumn=>$filter );
        $tmp = $this->getList($field,$filter,0,1);
        reset($tmp);
        $data = current( $tmp );
        return $data;
    }}
