<?php

use Burdock\DataModel\Migrator;
use Burdock\DataModel\Sql;
use PHPUnit\Framework\TestCase;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require_once (__DIR__ . '/Samples.php');

//Todo: ORDER_BY など WHERE 句以外のテスト追加
class SamplesTest extends TestCase
{
    private static $pdo = null;

    public static function setUpBeforeClass(): void
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

        // 対象テーブルの削除
        $sql = 'DROP TABLE IF EXISTS ' . Samples::getTableName();
        self::$pdo->query($sql);

        // 対象テーブルの新規作成
        Samples::setPDOInstance(self::$pdo);
        $with_hidden = true;
        $sql = Migrator::getCreateTableQuery(Samples::getTableName(), Samples::getFields($with_hidden));
        self::$pdo->query($sql);
        self::$pdo->query('TRUNCATE TABLE ' . Samples::getTableName());
    }

    public static function tearDownAfterClass(): void
    {
        //self::$pdo->query('drop table base_table;');
        //Samples::setPDOInstance(null);
    }

    public function setUp(): void
    {
        parent::setUp();
        self::setLogger();
    }

    public static function setLogger()
    {
        $logger = new Logger('DataModelTest');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));
        Samples::setLogger($logger);
    }
    /**
     * @test
     */
    public function test_1_配列からインスタンス変数に値を設定する()
    {
        $test_msg = '_f3 is a private property.';
        $b1 = new Samples();
        $b1->setData(array('ukey_1'=>'abc','ukey_2'=>'xyz', 'ukey_3'=>$test_msg));
        $this->assertEquals('abc', $b1->ukey_1);
        $this->assertEquals($test_msg, $b1->ukey_3);
    }

    /**
     * @test
     */
    public function test_2_存在しないプロパティにアクセスすると例外が発生する()
    {
        $this->expectException(InvalidArgumentException::class);
        $b = new Samples();
        $b->nonExistingProperty;
    }

    /**
     * @test
     */
    public function test_3_同じクラスのインスタンスからコンストラクタ経由で値を設定する()
    {
        $b = new Samples();
        $this->assertFalse($b->isDirty());
        $b->id = 0;
        $b->ukey_1 = 'abc';
        $this->assertTrue($b->isDirty());
        $this->assertTrue($b->isDirty(['ukey_1']));
        $bx = new Samples($b);
        $this->assertEquals($b->id, $bx->id);
        $this->assertEquals($b->ukey_1, $bx->ukey_1);
    }

    /**
     * @test
     */
    public function test_4_同じクラスのインスタンスからsetDataメソッド経由で値を設定する()
    {
        $b = new Samples();
        $b->id = 0;
        $b->ukey_1 = 'abc';
        $bx = new Samples();
        $bx->setData($b);
        $this->assertEquals($b->id, $bx->id);
        $this->assertEquals($b->ukey_1, $bx->ukey_1);
    }

    /**
     * @test
     */
    public function test_5_標準クラスのインスタンスからコンストラクタ経由で値を設定する()
    {
        $d = new stdClass();
        $d->pkey_2 = 999;
        $d->ukey_2 = 'some contents';
        $bx = new Samples($d);
        $this->assertEquals($d->pkey_2, $bx->pkey_2);
        $this->assertEquals($d->ukey_2, $bx->ukey_2);
    }

    /**
     * @test
     */
    public function test_6_標準クラスのインスタンスからsetDataメソッド経由で値を設定する()
    {
        $d = new stdClass();
        $d->pkey_2 = 999;
        $d->ukey_2 = 'some contents';
        $bx = new Samples();
        $bx->setData($d);
        $this->assertEquals($d->pkey_2, $bx->pkey_2);
        $this->assertEquals($d->ukey_2, $bx->ukey_2);
    }

    /**
     * @test
     * @return \Burdock\DataModel\Model
     * @throws Exception
     */
    public function test_7_レコードインサートとアップデート()
    {
        $b = new Samples();
        $b->pkey_2 = 100;
        $b->pkey_3 = 200;
        $b->ukey_1 = 'abc';
        $b->ukey_2 = 'abc';
        $b->email = 'abc@abc.com';
        //$dt = Date('Y-m-d H:i:s');
        //$b->created_at = $dt;
        $b->created_by = 'test';
        //$b->updated_at = $dt;
        $b->updated_by = 'test';
        $b = Samples::insert($b);
        $updated_at = $b->updated_at;
        $b->ukey_2 = 'xyz';
        $b->update();
        $c = Samples::findById(Samples::convertData($b));
        $this->assertEquals($c->ukey_2, $b->ukey_2);
        $this->assertNotEquals($updated_at, $b->updated_at);
        return $c;
    }

    /**
     * @test
     * @depends test_7_レコードインサートとアップデート
     * @throws Exception
     */
    public function test_8_findを使ったレコード取得($obj)
    {
        $obj->id = null;
        $obj->ukey_3 = 'C1';
        Samples::insert($obj);
        $obj->id = null;
        $obj->ukey_3 = 'C2';
        Samples::insert($obj);
        $obj->id = null;
        $obj->ukey_3 = 'C3';
        Samples::insert($obj);
        // WHERE句指定のない find
        $results = Samples::find([], [Samples::FETCH_MODE => PDO::FETCH_CLASS]);
        $this->assertEquals(4, count($results));
        $this->assertInstanceOf(Samples::class, $results[0]);
        //Todo: Baseクラスのインスタンスであることを確認する
        $results = Samples::find([Sql::WHERE => ['id' => 2]]);
        $this->assertEquals(1, count($results));
        //$obj = $results[0];
        //$this->assertFalse($obj->isDirty());
        //$obj->ukey_2 = 'zzzzzzz';
        //$this->assertTrue($obj->isDirty());
        $results = Samples::find([
            Sql::WHERE => [
                Sql:: OR => [
                    ['id' => 2],
                    ['id' => 3]
                ]
            ]
        ]);
        $this->assertEquals(2, count($results));
    }

    public function test_findByIdsのwhere句生成テスト()
    {
        $model = new Samples(['id' => 3, 'pkey_2' => 5, 'pkey_3' => 9]);
        $params = [
            Sql::SELECT => ['@@*'],
            Sql::FROM  => Samples::getTableName(),
            Sql::WHERE => Sql::getPrimaryKeyConditions(Samples::getPrimaryKeys(), Samples::convertData($model))
        ];
        list($sql, $bind) = Sql::buildQuery($params);
        $expected = 'SELECT * FROM `samples` WHERE (`id` = :id__0 AND `pkey_2` = :pkey_2__1 AND `pkey_3` = :pkey_3__2)';
        $this->assertEquals($expected, $sql);
    }

    public function test_count()
    {
        $results = Samples::count([
            Sql::WHERE => [
                Sql:: OR => [
                    ['id' => 2],
                    ['id' => 3]
                ]
            ]
        ]);
        $this->assertEquals(2, $results);
    }

    public function test_paginate()
    {
        $result = Samples::paginate([
            'page' => 1,
            'limit' => 2
        ]);
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(2, count($result['items']));
        $result = Samples::paginate([
            'page' => 3,
            'limit' => 3
        ]);
        $this->assertEquals(2, $result['page']);
        $this->assertEquals(1, count($result['items']));
    }
}
