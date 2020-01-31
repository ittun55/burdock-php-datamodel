<?php
namespace Burdock\DataModel;

use Exception;
use \InvalidArgumentException;

class Sql
{
    const SELECT = 'select';
    const FROM   = 'from';
    const JOIN   = 'join';
    const INNER  = 'inner';
    const OUTER  = 'outer';
    const WHERE  = 'where';
    const AND = 'and';
    const OR  = 'or';
    const EQ  = 'eq'; // IS NULL は eq NULL で代用
    const NE  = 'ne'; // iS NOT NULL は ne NULL で代用
    const GT  = 'gt';
    const LT  = 'lt';
    const GE  = 'ge';
    const LE  = 'le';
    const LK  = 'lk'; // LIKE
    const NL  = 'nl'; // NOT LIKE
    const FM  = 'fm'; // FORWARD MATCH
    const NF  = 'nf'; // NOT FORWARD MATCH
    const PM  = 'pm'; // PARTIAL MATCH
    const NP  = 'np'; // NOT PARTIAL MATCH
    const IN  = 'in';
    const NI  = 'ni'; // NOT IN
    const BW  = 'bw'; // BETWEEN
    const OP = [
        self::EQ => '=',
        self::NE => '<>',
        self::GT => '>',
        self::LT => '<',
        self::GE => '>=',
        self::LE => '<=',
        self::LK => 'LIKE',
        self::NL => 'NOT LIKE',
        self::FM => 'LIKE',
        self::NF => 'NOT LIKE',
        self::PM => 'LIKE',
        self::NP => 'NOT LIKE',
        self::IN => 'IN',
        self::NI => 'NOT IN',
        self::BW => 'BETWEEN',
    ];
    const OPS = [
        self::EQ, self::NE, self::GT, self::LT,
        self::GE, self::LE, self::LK, self::NL,
        self::FM, self::NF, self::PM, self::NP,
        self::IN, self::NI, self::BW,
    ];
    const ORDER_BY  = 'order_by';
    const ASC  = 'ASC';
    const DESC = 'DESC';
    const SORTS = [
        self::ASC,
        self::DESC
    ];
    const LIMIT  = 'limit';
    const OFFSET  = 'offset';
    const FOR_UPDATE = 'for_update';

    /**
     * テーブル名、フィールド名をクォートする
     * @param string $item
     * @return string
     */
    public static function wrap(string $item): string
    {
        if (strncmp($item, '@@', 2) == 0)
            return substr($item, 2);
        $c = '`';
        $items = explode('.', $item);
        return (count($items) == 2) ? "$c$items[0]$c.$c$items[1]$c" : "$c$items[0]$c";
    }

    /**
     * INSERT文の生成
     *
     * @param string $table_name
     * @param array $fields
     * @param array $data
     * @param bool $ignore
     * @return array
     */
    public static function buildInsertQuery(string $table_name, array $fields, array $data, bool $ignore): array
    {
        $phd = ''; // place holder
        $ctx = array();
        $_fields = [];
        foreach ($fields as $field => $attr) {
            //todo: autoincrement フィールドでも値の指定があれば、その値を設定する？
            if (array_key_exists('auto_increment', $attr) && $attr['auto_increment']) {
                continue;
            }
            //フィールドに対応する値が $data に存在しない場合、初期値があれば設定し、無ければ null を設定する
            if (array_key_exists($field, $data)) {
                $ctx[':' . $field] = $data[$field];
            } elseif (array_key_exists('default', $attr)) {
                $ctx[':' . $field] = $attr['default'];
            } elseif (!array_key_exists('required', $attr) || !$attr['required']) {
                $ctx[':' . $field] = null;
            } else {
                $msg = 'Field: ' . $field . 'value is required for insert query．';
                throw new InvalidArgumentException($msg);
            }
            $_fields[] = $field;
            if ($phd != '') {
                $phd.= ', ';
            }
            $phd .= ':' . $field;
        }
        $sql = 'INSERT' . (($ignore) ? ' IGNORE' : '') . ' INTO ' . $table_name;
        $sql.= ' (' . implode(', ', $_fields) . ') VALUES (' . $phd . ')';
        return [$sql, $ctx];
    }

    /**
     * UPDATE文の生成
     *
     * @param string $table_name
     * @param array $fields
     * @param array $primary_keys
     * @param array $data
     * @return array
     * @throws Exception
     */
    public static function buildUpdateQuery(string $table_name, array $fields, array $primary_keys, array $data): array
    {
        $phd = ''; // 更新SQL用プレースホルダ文字列
        $ctx = []; // バインド連想配列

        foreach ($fields as $field => $attrs) {
            // primary_key フィールドは除外
            // データに含まれないフィールドも除外
            if (in_array($field, $primary_keys)
                || !array_key_exists($field, $data)) {
                continue;
            }
            if ($phd != '') {
                $phd .= ', ';
            }
            $phd .= $field . ' = :' . $field;
            $ctx[':' . $field] = $data[$field];
        }

        // primary_key フィールドによる WHERE句の生成
        $w_params = [Sql::WHERE => Sql::getPrimaryKeyConditions($primary_keys, $data)];
        list($where, $ctx) = self::getWhereClause($w_params, $ctx);

        // プリペアードステートメントの生成と値のバインド
        $sql = 'UPDATE ' . $table_name . ' SET ' . $phd . $where;
        return [$sql, $ctx];
    }

    /**
     * プライマリキーを指定した WHERE句を生成する
     *
     * @param array $primary_keys plain array
     * @param array $data field and value pairs array object
     * @return array
     */
    public static function getPrimaryKeyConditions(array $primary_keys, array $data): array
    {
        $w_params = [];
        foreach ($primary_keys as $pkey) {
            if (array_key_exists($pkey, $data)) {
                $w_params[] = [$pkey => $data[$pkey]];
            } else {
                $msg = 'Argument hash should have the primary key value pair.';
                throw new InvalidArgumentException($msg);
            }
        }
        return (count($w_params) == 1) ? $w_params[0] : [self::AND => $w_params];
    }

    /**
     * WHERE句を生成する (外部から呼び出す時の WRAPPER)
     *
     * @param array|null $params
     * @param array $bind
     * @return array
     * @throws Exception
     */
    public static function getWhereClause(array $params=null, ?array $bind=[]): array
    {
        $where = '';
        if (isset($params[Sql::WHERE]))
            list($where, $bind) = self::parseWhere($params, $bind);
        return ($where) ? [' WHERE ' . $where, $bind] : ['', $bind];
    }

    /**
     * WHERE句を生成する
     *
     * [
     *     SQL::WHERE => [
     *         Sql::OR => [
     *             ['field1' => 'value1'], // 比較演算子省略時は Where::OP_EQ
     *             ['field2' => [Sql::NE, 'value2']],
     *             [Sql::AND => [
     *                 ['field3' => [Sql::GE => 'value3']],
     *                 ['field4' => [Sql::LT => 'value4']]
     *             ],
     *         ]
     *     ],
     * ]
     * @param $params array       "検索条件のフィールド、値が含まれる連想配列、またはクラスインスタンス"
     * @param $bind array         "検索条件のプレースホルダと値のセットが格納される配列"
     * @return array
     *      $where: プレースホルダーを含む Where 句文字列,
     *      $bind:  検索条件にバインドする値,
     *      $cnt:   プレースホルダーが重複しないためのカウンタ
     * @throws Exception
     */
    public static function parseWhere(?array $params, $bind=[])
    {
        $where = '';
        if (is_null($params)) {
            return array($where, $bind);
        } elseif (!is_array($params)) {
            throw new InvalidArgumentException('');
        }
        if (array_key_exists(Sql::WHERE, $params)) {
            $elm = $params[Sql::WHERE];
            list($where, $bind) = static::parseWhere($elm, $bind);
        } elseif (array_key_exists(Sql::AND, $params)) {
            list($where, $bind) = static::parseElements(Sql::AND, $params, $bind);
        } elseif (array_key_exists(Sql::OR, $params)) {
            list($where, $bind) = static::parseElements(Sql::OR, $params, $bind);
        } else {
            list($field, $op, $value) = static::parseCondition($params);
            list($where, $bind) = static::makeCondition($field, $op, $value, $bind);
        }
        return [$where, $bind];
    }

    /**
     * AND/OR 結合を処理する
     *
     * @param $op
     * @param $params
     * @param $bind
     * @return array
     * @throws Exception
     */
    public static function parseElements($op, $params, $bind): array
    {
        $ws = [];
        foreach ($params[$op] as $elm) {
            list($w, $bind) = static::parseWhere($elm, $bind);
            $ws[] = $w;
        }
        $where = '(' . implode(' ' . strtoupper($op) . ' ', $ws) . ')';
        return [$where, $bind];
    }

    /**
     * WHERE句条件オブジェクトを分解する
     * @param $params
     * @return array
     */
    public static function parseCondition($params): array
    {
        $value = null;
        $op = Sql::EQ;
        $field = array_keys($params)[0];
        $condition = $params[$field];
        if (is_array($condition)) {
            $op = array_keys($condition)[0];
            $value = $condition[$op];
        } else {
            $value = $condition;
        }
        return [$field, $op, $value];
    }

    /**
     * 比較演算文字列を生成
     *
     * @param $field string フィールド名
     * @param $op    string オペレーター
     * @param $value mixed 値
     * @param $bind  array プレースホルダを保存する連想配列
     * @return array $where, $bind
     */
    public static function makeCondition($field, $op, $value, $bind)
    {
        $where = '';
        $placeholder = sprintf(':%s__%d', $field, count($bind));
        switch ($op) {
            case static::EQ:
                if (is_null($value)) {
                    $where = sprintf('%s IS NULL', self::wrap($field));
                } else {
                    $where = sprintf('%s %s %s', self::wrap($field), Sql::OP[$op], $placeholder);
                    $bind[$placeholder] = $value;
                }
                break;
            case static::NE:
                if (is_null($value)) {
                    $where = sprintf('%s IS NOT NULL', self::wrap($field));
                } else {
                    $where = sprintf('%s %s %s', self::wrap($field), Sql::OP[$op], $placeholder);
                    $bind[$placeholder] = $value;
                }
                break;
            case static::GT:
            case static::LT:
            case static::GE:
            case static::LE:
                $where = sprintf('%s %s %s', self::wrap($field), Sql::OP[$op], $placeholder);
                $bind[$placeholder] = $value;
                break;
            case static::LK: // forward match
            case static::FM: // forward match
                $where = sprintf('%s LIKE %s', self::wrap($field), $placeholder);
                $bind[$placeholder] = $value . '%';
                break;
            case static::PM: // partial match
                $where = sprintf('%s LIKE %s', self::wrap($field), $placeholder);
                $bind[$placeholder] = '%' . $value . '%';
                break;
            case static::IN:
                $ws = [];
                foreach($value as $v) {
                    $placeholder = sprintf(':%s__%d', $field, count($bind));
                    $ws[] = $placeholder;
                    $bind[$placeholder] = $v;
                }
                $where = sprintf('%s IN (%s)', self::wrap($field), implode(', ', $ws));
                break;
            case static::BW:
                $ph_1 = sprintf(':%s__%d', $field, count($bind));
                $bind[$ph_1] = $value[0];
                $ph_2 = sprintf(':%s__%d', $field, count($bind));
                $bind[$ph_2] = $value[1];
                $where = sprintf('%s BETWEEN %s AND %s', self::wrap($field), $ph_1, $ph_2);
                break;
        }
        return [$where, $bind];
    }

    /**
     * SELECT文を生成する.
     *
     * @param array|null $params
     * @return array
     * @throws Exception
     */
    public static function buildQuery(array $params): array
    {
        $bind = [];
        $sql = self::getSelectClause($params);
        $sql.= self::getFromClause($params);
        list($join, $bind) = self::getJoinClause($params, $bind);
        $sql.= $join;
        list($where, $bind) = self::getWhereClause($params, $bind);
        $sql.= $where;
        $sql.= self::getOrderByClause($params);
        $sql.= self::getLimitClause($params);
        $sql.= self::getOffsetClause($params);

        //if (!is_null($params) && array_key_exists(Sql::FOR_UPDATE, $params) && $params[Sql::FOR_UPDATE]) {
        if (isset($params[Sql::FOR_UPDATE])) {
            $sql.= ' FOR UPDATE';
        }
        return [$sql, $bind];
    }

    /**
     * SELECT COUNT(*)文を生成する.
     *
     * @param array $params
     * @return array
     * @throws Exception
     */
    public static function buildCountQuery(array $params): array
    {
        $params[Sql::SELECT] = ['@@COUNT(*)'];
        if (isset($params[Sql::LIMIT]))
            unset($params[Sql::LIMIT]);
        if (isset($params[Sql::OFFSET]))
            unset($params[Sql::OFFSET]);
        if (isset($params[Sql::ORDER_BY]))
            unset($params[Sql::ORDER_BY]);
        list($sql, $bind) = self::buildQuery($params);
        return array($sql, $bind);
    }

    /**
     * 取得するフィールドを指定する
     * 現状、Query の結果はそのモデルクラスのインスタンスになるが、SELECT でフィールドを指定すると
     * モデルクラスのフィールドとマッチしないという課題があり、現状は SELECT 指定があれば例外を発生している。
     * @param array $params
     * @return string
     */
    public static function getSelectClause(array $params = null): string
    {
        if (!isset($params[self::SELECT]) || (count($params[self::SELECT]) === 0)) {
            $msg = 'SELECT fields must be specified.';
            throw new InvalidArgumentException($msg);
        }
        $_fields = [];
        foreach($params[self::SELECT] as $item) {
            if (!is_string($item)) {
                $msg = 'SELECT field item should be "table.field alias" style string.';
                throw new InvalidArgumentException($msg);
            }
            if (strncmp($item, '@@', 2) == 0) {
                $_fields[] = substr($item, 2);
                continue;
            }
            list($fld, $als) = array_merge(explode(' ', $item), [null]);
            $f = Sql::wrap($fld);
            if ($als)
                $f.= ' AS ' . Sql::wrap($als);
            $_fields[] = $f;
        }
        return 'SELECT ' . implode(', ', $_fields);
    }

    /**
     * FROM句を生成する
     *
     * @param array $params
     * @return string
     */
    public static function getFromClause(array $params): string
    {
        if (!isset($params[Sql::FROM]))
            throw new InvalidArgumentException('The table name should be specified by FROM parameter.');
        if (is_array($params[Sql::FROM])) {
            list($table, $alias) = $params[Sql::FROM] + [null, null];
        } else {
            list($table, $alias) = array_merge(explode(' ', $params[Sql::FROM]), [null]);
        }
        $c = ' FROM ' . self::wrap($table);
        if ($alias)
            $c.= ' ' . Sql::wrap($alias);
        return $c;
    }

    /**
     * JOIN句を生成する
     *
     * @param array $params
     * @param array $bind
     * @param int $cnt
     * @return array
     */
    public static function getJoinClause(array $params, $bind=[], $cnt=0): array
    {
        if (!isset($params[self::JOIN])) return ['', $bind, $cnt];
        if (count($params[self::JOIN]) == 0) return ['', $bind, $cnt];

        $_joins = [];
        foreach($params[self::JOIN] as $j) {
            $_type = array_keys($j)[0];
            list($tbl_als, $consts) = $j[$_type];
            list($tbl, $als) = array_merge(explode(' ', $tbl_als), [null]);
            $table = Sql::wrap($tbl);
            $alias = Sql::wrap($als);
            $_constraints = [];
            foreach($consts as $c) {
                if (is_string($c)) {
                    $_constraints[] = $c;
                } elseif (count($c) >= 2) {
                    $field1 = Sql::wrap($c[0]);
                    $field2 = Sql::wrap($c[1]);
                    $op = Sql::OP[Sql::EQ];
                    if (isset($c[2])) {
                        if (!in_array($c[2], Sql::OPS)) {
                            $msg = "Invalid Operator Specified. : ${c[2]}";
                            throw new InvalidArgumentException($msg);
                        }
                        $op = Sql::OP[$c[2]];
                    }
                    $_constraints[] = "${field1} ${op} ${field2}";
                } else {
                    list($field, $op, $value) = static::parseCondition($c);
                    list($where, $bind) = static::makeCondition($field, $op, $value, $bind);
                    $_constraints[] = $where;
                }
            }
            $constraints = implode(' AND ', $_constraints);
            $type = strtoupper($_type);
            $_join = "${type} JOIN ${table}";
            $_join.= ($alias) ? " AS ${alias}" : '';
            $_joins[] = $_join . " ON ${constraints}";
        }
        return [' ' . implode(' ', $_joins), $bind];
    }

    /**
     * ORDER BY句を生成する (外部からの呼び出しWRAPPER)
     *
     * @param $params
     * @return string
     * @throws Exception
     */
    public static function getOrderByClause($params=null): string
    {
        if (!isset($params[Sql::ORDER_BY]) || count($params[Sql::ORDER_BY]) === 0) {
            return '';
        }
        $_orders = [];
        foreach($params[Sql::ORDER_BY] as $item) {
            if (!is_string($item)) {
                $msg = 'ORDER BY field item should have at least 2 elements';
                throw new InvalidArgumentException($msg);
            }
            list($fld, $dct) = array_merge(explode(' ', $item), [null]);
            $o = Sql::wrap($fld);
            if ($dct) {
                $dct_upper = strtoupper($dct);
                if (in_array($dct_upper, Sql::SORTS)) {
                    $o.= ' ' . $dct_upper;
                }
            }
            $_orders[] = $o;
        }
        return ' ORDER BY ' . implode(', ', $_orders);
    }

    /**
     * LIMIT句を生成する
     *
     * @param null $params
     * @return string
     * @throws Exception
     */
    public static function getLimitClause($params=null)
    {
        if (isset($params[Sql::LIMIT])) {
            if (is_int($params[Sql::LIMIT])) {
                return ' LIMIT '. $params[Sql::LIMIT];
            } else {
                $msg = 'Limit value should have integer value.';
                throw new InvalidArgumentException($msg);
            }
        } else {
            return '';
        }
    }

    /**
     * OFFSET句を生成する
     *
     * @param null $params
     * @return string
     * @throws Exception
     */
    public static function getOffsetClause($params=null)
    {
        if (isset($params[static::OFFSET])) {
            if (is_int($params[static::OFFSET])) {
                return ' OFFSET '. $params[static::OFFSET];
            } else {
                throw new Exception('OFFSET value should have integer value.');
            }
        } else {
            return '';
        }
    }

    /**
     * WHERE句条件を配列のまま追加する
     *
     * where句条件オブジェクトの "中身" (whereキー部分は要らない) を受け取り、
     * 追加する条件オブジェクトをマージし、
     * 新しいwhere句条件オブジェクトの "中身" を返す
     *
     * @param array $new
     * @param array|null $org
     * @return array
     */
    public static function addWhere(array $new, ?array $org=[]): array
    {
        if (count($org) == 0) {
            return $new;
        }
        if (array_key_exists(Sql::AND, $org)) {
            $merged = array_merge($org[Sql::AND], [$new]);
        } else { //OR結合、単一条件
            $merged = [$org, $new];
        }
        return [Sql::AND => $merged];
    }
}