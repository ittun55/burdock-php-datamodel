<?php
use Burdock\Config\Config;
use iamcal\SQLParser;

$msg = "arg1: path to config file." . PHP_EOL;
$msg.= "arg2: object path to DB setting." . PHP_EOL;
$msg.= "arg3: table name to dump schema." . PHP_EOL;

// $argv[1] config.json ロードとパスの確認
if (empty($argv[1])) {
    die($msg);
}
$config_path = __DIR__ . '/../' . $argv[1];
if (!file_exists($config_path)) {
    die("config file not found in ${config_path}.");
}
$config = Config::load($config_path);

// $argv[2] DB定義オブジェクトまでの config パス
if (empty($argv[2])) {
    die($msg);
}
$obj_path = $argv[2];
if (!$config->hasValue($obj_path)) {
    die("The config doesn't contain DB setting values");
}

// argv[3] テーブル名の確認
if (empty($argv[3])) {
    die($msg);
}
if (empty($argv[3])) die("table name must be specified.");

// PDO の生成
$pdo = createPdo($config->getValue($obj_path));

$def = getTableDefs($pdo, $argv[3]);
$def_json = json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

echo $def_json . PHP_EOL;

function createPdo(array $setting): PDO
{
    $host     = $setting['host'];
    $port     = $setting['port'];
    $dbname   = $setting['name'];
    $charset  = $setting['charset'];
    $username = $setting['user'];
    $password = $setting['pass'];
    $dsn = "mysql:host=${host};port=${port};dbname=${dbname};charset=${charset}";
    $options  = [
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false
    ];
    return new PDO($dsn, $username, $password, $options);
}

function getTableDefs(\PDO $pdo, $table_name): array
{
    $stmt = $pdo->prepare("SHOW CREATE TABLE `${table_name}`");
    $stmt->execute();
    $ct = $stmt->fetch();
    $parser = new SQLParser();
    $table = $ct['Table'];
    $parser->parse($ct['Create Table']);
    return $parser->tables[$table];
}
