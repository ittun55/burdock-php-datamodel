<?php

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use Burdock\DataModel\Sql;

class SqlTest extends TestCase
{
    /**
     * @var PDO
     */
    private static $pdo;

    public function test_pdo_behavior()
    {
        $env = Dotenv::create(__DIR__ . '/..');
        $env->load();
        $dsn      = getenv('TESTDB_DSN');
        $username = getenv('TESTDB_USER');
        $password = getenv('TESTDB_PASS');
        $options  = array(
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        );
        self::$pdo = new PDO($dsn, $username, $password, $options);
        $query = "select `base_table`.`id` as base_table__id, `belong_table`.`id` as belong_table__id from `base_table` join `belong_table` on `base_table`.`id` = `belong_table`.`base_table_id`";
        //$query = "select * from `base_table` join `belong_table` on `base_table`.`id` = `belong_table`.`base_table_id`";
        $stmt = self::$pdo->prepare($query);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $result = $stmt->fetchAll();
        $this->assertNotNull($result);
    }

    public function test_wrap()
    {
        $this->assertEquals('`field`', Sql::wrap('field'));
        $this->assertEquals('`table`.`field`', Sql::wrap('table.field'));
        $this->assertEquals('ABCDEFG', Sql::wrap('@@ABCDEFG'));
    }

    public function test_selectClause()
    {
        $params = [
            Sql::SELECT => ['table_a.id', 'table_b.email', 'table_c.password'],
            Sql::JOIN => [null]
        ];
        $_select = 'SELECT `table_a`.`id`, `table_b`.`email`, `table_c`.`password`';
        $select = Sql::getSelectClause($params);
        $this->assertEquals($_select, $select);

        $params = [
            Sql::SELECT => ['table_a.id table_a__id', 'table_b.email table_b__email', 'table_c.password table_c__password'],
            Sql::JOIN => [null]
        ];
        $_select = 'SELECT `table_a`.`id` AS `table_a__id`, `table_b`.`email` AS `table_b__email`, `table_c`.`password` AS `table_c__password`';
        $select = Sql::getSelectClause($params);
        $this->assertEquals($_select, $select);
    }

    public function test_selectWithoutSelectParams()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage("SELECT fields must be specified.");
        $params = [
            Sql::JOIN => [null]
        ];
        $select = Sql::getSelectClause($params);
    }

    public function test_noSelectWithJoin2()
    {
        $this->expectException('InvalidArgumentException');
        $params = [
            Sql::SELECT => [],
            Sql::JOIN => [null]
        ];
        $select = Sql::getSelectClause($params);
    }

    public function test_joinClause()
    {
        $params = [
            Sql::JOIN => [
                [
                    'inner' => ['table_a tbl_a', [
                        ['tbl_a.tbl_id', 'tbl.id'],
                        ['tbl_a.deleted_at' => null],
                        ['tbl_a.owner_id' => [Sql::EQ => 999]]
                    ]]
                ]
            ]
        ];
        list($join, $bind) = Sql::getJoinClause($params);
        $j = " INNER JOIN `table_a` AS `tbl_a` ON `tbl_a`.`tbl_id` = `tbl`.`id` AND `tbl_a`.`deleted_at` IS NULL AND `tbl_a`.`owner_id` = :tbl_a.owner_id__0";
        $this->assertEquals($j, $join);
        $params[Sql::JOIN][] = [
            'left' => ['table_b tbl_b', [
                ['tbl_b.tbl_id', 'tbl.id'],
                ['tbl_b.deleted_at' => null],
                ['tbl_b.owner_id' => [Sql::EQ => 999]]
            ]]
        ];
        list($join, $bind) = Sql::getJoinClause($params);
        $j.= " LEFT JOIN `table_b` AS `tbl_b` ON `tbl_b`.`tbl_id` = `tbl`.`id` AND `tbl_b`.`deleted_at` IS NULL AND `tbl_b`.`owner_id` = :tbl_b.owner_id__1";
        $this->assertEquals($j, $join);
    }

    public function test_whereClause()
    {
        $params = [
            SQL::WHERE => ['field1' => 'value1'] // 省略時は Where::OP_EQ
        ];
        list($where, $bind) = Sql::getWhereClause($params);
        $w = ' WHERE `field1` = :field1__0';
        $this->assertEquals($w, $where);
        $this->assertEquals('value1', $bind[':field1__0']);

        $params = [
            SQL::WHERE => [
                Sql::OR => [
                    ['field1' => 'value1'], // 省略時は Where::OP_EQ
                    ['field2' => [Sql::NE => 'value2']],
                    [Sql::AND => [
                        ['field3' => [Sql::GE => 'value3']],
                        ['field4' => [Sql::LT => 'value4']]
                    ]],
                ]
            ]
        ];
        list($where, $bind) = Sql::getWhereClause($params);
        $w = ' WHERE (`field1` = :field1__0 OR `field2` <> :field2__1 OR (`field3` >= :field3__2 AND `field4` < :field4__3))';
        $this->assertEquals($w, $where);
    }

    public function test_getPrimaryKeyConditions()
    {
        $primary_keys = ['id'];
        $data = ['id' => 0, 'id2' => 1, 'f1' => 'abc', 'f2' => 'xyz'];
        $params = Sql::getPrimaryKeyConditions($primary_keys, $data);
        $this->assertFalse(array_key_exists(Sql::AND, $params));
        $this->assertTrue(array_key_exists($primary_keys[0], $params));
        $this->assertEquals($data[$primary_keys[0]], $params[$primary_keys[0]]);

        $primary_keys = ['id1', 'id2'];
        $data = ['id1' => 0, 'id2' => 1, 'f1' => 'abc', 'f2' => 'xyz'];
        $params = Sql::getPrimaryKeyConditions($primary_keys, $data);
        $this->assertTrue(array_key_exists(Sql::AND, $params));
    }

    public function test_makeCondition()
    {
        $field = 'table_1.field_1';
        $value = 'ABC';
        $bind  = [];
        $cnt   = 0;

        $op = Sql::EQ;
        list ($where, $_bind) = Sql::makeCondition($field, $op, $value, $bind);
        $placeholder = ':table_1.field_1__0';
        $this->assertEquals('`table_1`.`field_1` = :table_1.field_1__0', $where);
        $this->assertEquals($value, $_bind[$placeholder]);

        $op = Sql::NE;
        list ($where, $_bind) = Sql::makeCondition($field, $op, $value, $bind);
        $placeholder = ':table_1.field_1__0';
        $this->assertEquals('`table_1`.`field_1` <> :table_1.field_1__0', $where);
        $this->assertEquals($value, $_bind[$placeholder]);

        $op = Sql::GT;
        $value = 500;
        list ($where, $_bind) = Sql::makeCondition($field, $op, $value, $bind);
        $placeholder = ':table_1.field_1__0';
        $this->assertEquals('`table_1`.`field_1` > :table_1.field_1__0', $where);
        $this->assertEquals($value, $_bind[$placeholder]);

        $op = Sql::GE;
        $value = 500;
        list ($where, $_bind) = Sql::makeCondition($field, $op, $value, $bind);
        $placeholder = ':table_1.field_1__0';
        $this->assertEquals('`table_1`.`field_1` >= :table_1.field_1__0', $where);
        $this->assertEquals($value, $_bind[$placeholder]);

        $op = Sql::LT;
        $value = 500;
        list ($where, $_bind) = Sql::makeCondition($field, $op, $value, $bind);
        $placeholder = ':table_1.field_1__0';
        $this->assertEquals('`table_1`.`field_1` < :table_1.field_1__0', $where);
        $this->assertEquals($value, $_bind[$placeholder]);

        $op = Sql::LE;
        $value = 500;
        list ($where, $_bind) = Sql::makeCondition($field, $op, $value, $bind);
        $placeholder = ':table_1.field_1__0';
        $this->assertEquals('`table_1`.`field_1` <= :table_1.field_1__0', $where);
        $this->assertEquals($value, $_bind[$placeholder]);

        $op = Sql::IN;
        $value = ['a', 'b', 'c'];
        list ($where, $_bind) = Sql::makeCondition($field, $op, $value, $bind);
        $this->assertEquals('`table_1`.`field_1` IN (:table_1.field_1__0, :table_1.field_1__1, :table_1.field_1__2)', $where);
        $this->assertEquals('a', $_bind[':table_1.field_1__0']);
        $this->assertEquals('b', $_bind[':table_1.field_1__1']);
        $this->assertEquals('c', $_bind[':table_1.field_1__2']);

        $op = Sql::BW;
        $value = ['1900-01-01', '1999-12-31'];
        list ($where, $_bind) = Sql::makeCondition($field, $op, $value, $bind);
        $this->assertEquals('`table_1`.`field_1` BETWEEN :table_1.field_1__0 AND :table_1.field_1__1', $where);
        $this->assertEquals($value[0], $_bind[':table_1.field_1__0']);
        $this->assertEquals($value[1], $_bind[':table_1.field_1__1']);
    }

    public function test_getOrderByClause()
    {
        $this->assertEquals('', Sql::getOrderByClause());
        $params = [Sql::ORDER_BY => ['table1.field1', 'table2.field2 ASC', 'table3.field3 DESC']];
        $clause = Sql::getOrderByClause($params);
        $expected = ' ORDER BY `table1`.`field1`, `table2`.`field2` ASC, `table3`.`field3` DESC';
        $this->assertEquals($expected, $clause);

    }

    public function test_getLimitClause()
    {
        $this->assertEquals('', Sql::getLimitClause());
        $params = [Sql::LIMIT => 10];
        $clause = Sql::getLimitClause($params);
        $this->assertEquals(' LIMIT 10', $clause);
    }

    public function test_getOffsetClause()
    {
        $this->assertEquals('', Sql::getOffsetClause());
        $params = [Sql::OFFSET => 10];
        $clause = Sql::getOffsetClause($params);
        $this->assertEquals(' OFFSET 10', $clause);
    }

    public function test_addWhere()
    {
        $org = [];
        $new = ['field_a' => [Sql::EQ => 100]];
        $this->assertEquals($new, Sql::addWhere($new));
        $this->assertEquals($new, Sql::addWhere($new, $org));
        $org = Sql::addWhere($new, $org);
        $new2 = ['field_b' => [Sql::EQ => 200]];
        $org = Sql::addWhere($new2, $org);
        $this->assertEquals(2, count($org[Sql::AND]));
        $org = [Sql::OR => [$new, $new2]];
        $org = Sql::addWhere($new2, $org);
        $this->assertEquals(2, count($org[Sql::AND]));
    }

    public function test_buildQuery()
    {
        $params = [
          Sql::SELECT => ['@@`table1`.*'],
          Sql::FROM => 'table1 tbl',
          //self::JOIN   => [], // 指定が有る場合は、モデルインスタンスではなく配列を返す
          Sql::WHERE  => [
            Sql::OR  => [
              ['field1' => 'value1'], // 省略時は self::OP_EQ
              ['field2' => [Sql::NE => 'value2']],
              [Sql::AND => [
                ['field3' => [Sql::GE => 'value3']],
                ['field4' => [Sql::LT => 'value4']]
              ]]
            ],
          ],
          Sql::ORDER_BY => ['tbl.id DESC'],
          Sql::LIMIT => 10, // 数値
          Sql::OFFSET => 20, // 数値
          Sql::FOR_UPDATE => true
        ];
        list($sql, $bind) = Sql::buildQuery($params);
        $expected = 'SELECT `table1`.* FROM `table1` `tbl` WHERE (`field1` = :field1__0 OR `field2` <> :field2__1 OR (`field3` >= :field3__2 AND `field4` < :field4__3))';
        $expected.= ' ORDER BY `tbl`.`id` DESC LIMIT 10 OFFSET 20 FOR UPDATE';
        $this->assertEquals($expected, $sql);
        $this->assertEquals('value1', $bind[':field1__0']);

        $params[Sql::JOIN] = [
            [
                'inner' => ['table_a tbl_a', [
                    ['tbl_a.tbl_id', 'tbl.id'],
                    ['tbl_a.deleted_at' => null],
                    ['tbl_a.owner_id' => [Sql::EQ => 999]]
                ]]
            ]
        ];
        list($sql, $bind) = Sql::buildQuery($params);
        $expected = 'SELECT `table1`.* ';
        $expected.= 'FROM `table1` `tbl` INNER JOIN `table_a` AS `tbl_a` ON `tbl_a`.`tbl_id` = `tbl`.`id` AND `tbl_a`.`deleted_at` IS NULL AND `tbl_a`.`owner_id` = :tbl_a.owner_id__0 ';
        $expected.= 'WHERE (`field1` = :field1__1 OR `field2` <> :field2__2 OR (`field3` >= :field3__3 AND `field4` < :field4__4))';
        $expected.= ' ORDER BY `tbl`.`id` DESC LIMIT 10 OFFSET 20 FOR UPDATE';
        $this->assertEquals($expected, $sql);
        $params[Sql::ORDER_BY] = ['tbl.id DESC'];
        $params[Sql::LIMIT]    = 20;
        $params[Sql::OFFSET]   = 40;
        list($sql, $bind) = Sql::buildQuery($params);
        $expected = 'SELECT `table1`.* ';
        $expected.= 'FROM `table1` `tbl` INNER JOIN `table_a` AS `tbl_a` ON `tbl_a`.`tbl_id` = `tbl`.`id` AND `tbl_a`.`deleted_at` IS NULL AND `tbl_a`.`owner_id` = :tbl_a.owner_id__0 ';
        $expected.= 'WHERE (`field1` = :field1__1 OR `field2` <> :field2__2 OR (`field3` >= :field3__3 AND `field4` < :field4__4))';
        $expected.= ' ORDER BY `tbl`.`id` DESC LIMIT 20 OFFSET 40 FOR UPDATE';
        $this->assertEquals($expected, $sql);
    }

    public function test_buildCountQuery()
    {
        $params = [
            Sql::FROM => 'table1 tbl',
            Sql::JOIN => [
                [
                    'inner' => ['table_a tbl_a', [
                        ['tbl_a.tbl_id', 'tbl.id', Sql::GE],
                        ['tbl_a.deleted_at' => null],
                        ['tbl_a.owner_id' => [Sql::EQ => 999]]
                    ]]
                ]
            ],
            Sql::WHERE  => [
                Sql::OR  => [
                    ['field1' => 'value1'], // 省略時は self::OP_EQ
                    ['field2' => [Sql::NE => 'value2']],
                    [Sql::AND => [
                        ['field3' => [Sql::GE => 'value3']],
                        ['field4' => [Sql::LT => 'value4']]
                    ]]
                ],
            ],
            Sql::ORDER_BY => ['tbl.id DESC'],
            Sql::LIMIT => 10, // 数値
            Sql::OFFSET => 20, // 数値
        ];
        list($sql, $bind) = Sql::buildCountQuery($params);
        $expected = 'SELECT COUNT(*) FROM `table1` `tbl`';
        $expected.= ' INNER JOIN `table_a` AS `tbl_a` ON `tbl_a`.`tbl_id` >= `tbl`.`id`';
        $expected.= ' AND `tbl_a`.`deleted_at` IS NULL AND `tbl_a`.`owner_id` = :tbl_a.owner_id__0';
        $expected.= ' WHERE (`field1` = :field1__1 OR `field2` <> :field2__2 OR (`field3` >= :field3__3 AND `field4` < :field4__4))';
        $this->assertEquals($expected, $sql);
    }
}
