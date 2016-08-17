<?php
/**
 * ShopEx licence
 *
 * @copyright  Copyright (c) 2005-2010 ShopEx Technologies Inc. (http://www.shopex.cn)
 * @license  http://ecos.shopex.cn/ ShopEx License
 */

class dbeav_filter
{

    function dbeav_filter_parser($filter=array(),&$object)
    {
        if (!is_array($filter)) return $filter;

        $tPre = ('`'.$object->table_name(true).'`').'.';

        $where = [1];
        // 因为searchOptions 会员非 dbschme定义的字段

        $qb = $object->database()->createQueryBuilder();

        $cols = array_merge($object->searchOptions(), $object->_columns());

        // 过滤无用的filter条件
        $filter = array_where($filter, function($filterKey, $filterValue) use ($cols) {
            return !is_null($filterValue) &&
                   (isset($cols[$filterKey]) || strpos($filterKey, '|'));
        });

        foreach($filter as $filterKey => $filterValue)
        {
            if (strpos($filterKey, '|'))
            {
                list($columnName, $type) = explode('|', $filterKey);
                $where[] = $this->processTypeSql($tPre.$columnName, $type, $filterValue, $qb);
            }
            else
            {
                $columnName = $filterKey;
                if (is_array($filterValue))
                {

                    $where[] = $this->processTypeSql($tPre.$columnName, 'in', $filterValue, $qb);
                }
                else
                {
                    $where[] = $this->processTypeSql($tPre.$columnName, 'nequal', $filterValue, $qb);
                }
            }
        }
        return call_user_func_array(array($qb->expr(), 'andX'), $where);
    }


    /**
     * @var int
     */
    private $prepareParamMarkId = 0;
    /**
     * @var array
     */
    public  $prepareParamMarkedValues = [];

    /**
     * @return array
     */
    public function getPrepareParamMarkedValues()
    {
        return $this->prepareParamMarkedValues;
    }

    /**
     * @param $value
     * @return array|string
     */
    private function pickPrepareParamMark($value)
    {
        if (!is_array($value)) {
            $this->prepareParamMarkId++;
            $mark = ':ppm' . $this->prepareParamMarkId;
            $this->prepareParamMarkedValues[$mark] = $value;
            return $mark;
        } else {
            $marks = [];
            $values = $value;
            foreach ($values as $v) {
                $this->prepareParamMarkId++;
                $mark = ':ppm' . $this->prepareParamMarkId;
                $this->prepareParamMarkedValues[$mark] = $v;

                $marks[] = $mark;
            }
            return $marks;
        }
    }

    /**
     * @param $columnName
     * @param $type
     * @param $filterValue
     * @param \Doctrine\DBAL\Query\QueryBuilder $qb
     * @return mixed
     * @throws ErrorException
     */
    private function processTypeSql($columnName, $type, $filterValue, &$qb)
    {

        $filterPrepareMark = $this->pickPrepareParamMark($filterValue);

        $db = $qb->getConnection();
        switch ($type)
        {
            case 'than':
                $sql = $qb->expr()->gt($columnName, $filterPrepareMark);
                break;
            case 'lthan':
                $sql = $qb->expr()->lt($columnName, $filterPrepareMark);
                break;
            case 'nequal':
            case 'tequal':
                $sql = $qb->expr()->eq($columnName, $filterPrepareMark);
                break;
            case 'noequal':
                $sql = $qb->expr()->neq($columnName, $filterPrepareMark);
                break;

            case 'sthan':
                $sql = $qb->expr()->lte($columnName, $filterPrepareMark);
                break;
            case 'bthan':
                $sql = $qb->expr()->gte($columnName, $filterPrepareMark);
                break;
            case 'has':
                $sql = $qb->expr()->like($columnName, '%'.$filterPrepareMark.'%');
                break;
            case 'head':
                $sql = $qb->expr()->like($columnName, $filterPrepareMark.'%');
                break;
            case 'foot':
                $sql = $qb->expr()->like($columnName, '%'.$filterPrepareMark);
                break;
            case 'nohas':
                $sql = $qb->expr()->notlike($columnName, '%'.$filterPrepareMark.'%');
                break;
            case 'between':
                $sql = $qb->expr()->andX($qb->expr()->gte($columnName, $filterPrepareMark[0]),
                                         $qb->expr()->lt($columnName, $filterPrepareMark[1]));
                break;
            case 'in':
                $filterPrepareMark = (array)$filterPrepareMark;//if(count($filterValue) == 9){$lk = debug_backtrace();dump($lk);dump($filterValue);die;}
                if (empty($filterPrepareMark)) throw new InvalidArgumentException("filter column:{$columnName} in type, cannot empty");
                $sql = $qb->expr()->in($columnName, $filterPrepareMark);
                break;
            case 'notin':
                $filterPrepareMark = (array)$filterPrepareMark;
                $sql = $qb->expr()->notin($columnName, $filterPrepareMark);
                break;
            default:
                throw new \ErrorException(sprintf('column : %s dbeav filter donnot support type:%s', $columnName, $type));
        }
        return $sql;
    }
}

