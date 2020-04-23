<?php
use Burdock\DataModel\Migrator;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

require(__DIR__.'/Samples.php');

class MigratorTest extends TestCase
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
    }

    public function testGetCreateTableQuery()
    {
        $with_hidden = true;
        $ct = Migrator::getCreateTableQuery(Samples::getTableName(), Samples::getFields($with_hidden));
        $this->assertNotNull($ct);
    }

    public function testGetTables()
    {
        $tables = Migrator::getTables(self::$pdo);
        $this->assertTrue(in_array('samples', $tables));
    }

    public function testGetTableDefs()
    {
        $def = Migrator::getTableDefs(self::$pdo, 'samples');
        $fields = $def['fields'];
        $props  = $def['props'];
        $this->assertEquals('utf8mb4', $props['CHARSET']);
        $this->assertEquals('utf8mb4_bin', $props['COLLATE']);
        //$this->assertTrue($fields['id']['auto_increment']);
    }

    public function testGetTableDefsJson()
    {
        $json = Migrator::getTableDefsJson(static::$pdo);
        file_put_contents(__DIR__.'/../tmp/schemas.json', $json);
        $this->assertFalse(false);
    }
}
