<?php
namespace Burdock\DataModel;

use PDO;
use Exception;
use PDOStatement;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;

//Todo: 論理削除フィールド（論理削除を使うかどうかのフラグ）
//Todo: 物理削除をどう実装するか？
//Todo: 各フィールドの型が合っているか、チェックする実装を入れるか？
//Todo: 保存前のバリデーションとエラーメッセージをどう実装するか？
//Todo: 発行したクエリをログとして参照したい

/**
 * Class Model
 * @property string updated_at
 * @package DataModel
 */
class Model
{
    /**
     * @var LoggerInterface
     */
    protected static $logger;

    /**
     * @param LoggerInterface $logger
     */
    public static function setLogger(LoggerInterface $logger)
    {
        static::$logger = $logger;
    }

    public static function getLogger(): LoggerInterface
    {
        if (is_null(static::$logger))
            static::$logger = new NullLogger();
        return static::$logger;
    }

    /**
     *  対応するテーブル名の定義
     */
    protected static $table_name = '';

    /**
     * モデルに対するテーブル名を返す
     *
     * @return string
     */
    public static function getTableName()
    {
        if (!static::$table_name)
            throw new InvalidArgumentException('table_name not defined');
        return static::$table_name;
    }

    /**
     *  @var array field definitions
     */
    protected static $fields = [];

    public static function loadFields($fields): void
    {
        static::$fields = $fields;
    }

    /**
     * @var string field name for soft deletion
     */
    protected static $soft_delete_field = 'deleted_at';

    /**
     * Getting field definitions of this model.
     *
     * @param bool $with_hidden
     * @return array
     */
    public static function getFields($with_hidden=false): array
    {
        if ($with_hidden) return static::$fields;
        $_fields = array_keys(static::$fields);
        $fields = [];
        foreach($_fields as $field) {
            if (isset(static::$fields[$field]['hidden'])) continue;
            $fields[$field] = static::$fields[$field];
        }
        return $fields;
    }

    /**
     * Getting field names only
     *
     * @param bool $with_hidden
     * @return array
     */
    public static function getFieldNames($with_hidden=false): array
    {
        $_fields = array_keys(static::$fields);
        $fields = [];
        foreach($_fields as $field) {
            if (!$with_hidden && isset(static::$fields[$field]['hidden']))
                continue;
            $fields[] = $field;
        }
        return $fields;
    }

    /**
     * Getting primary key field definitions
     *
     * @return array
     */
    public static function getPrimaryKeys()
    {
        $fields = [];
        foreach(static::$fields as $field => $setting) {
            if (isset($setting['primary'])) $fields[] = $field;
        }
        return $fields;
    }

    /**
     * @var array model data cache
     */
    protected $_data = [];

    /**
     * @var array storage for the pair of modified field and original value
     */
    protected $_dirty_fields = [];

    /**
     * Save original value if modified
     *
     * @param string $field
     * @param $value
     */
    protected function setDirtyField(string $field, $value): void
    {
        if (array_key_exists($field, $this->_dirty_fields))
            return;
        $this->_dirty_fields[$field] = $value;
    }

    /**
     * If the record has been modified or not
     *
     * @param null $fields
     * @return bool
     */
    public function isDirty($fields=null): bool
    {
        if (is_null($fields)) {
            return (count(array_keys($this->_dirty_fields)) === 0) ? false : true;
        } else {
            foreach ($fields as $field) {
                if (array_key_exists($field, $this->_dirty_fields)) return true;
            }
            return false;
        }
    }

    /**
     * @var PDO connection object container
     */
    protected static $_pdo_container = [];

    /**
     * 接続済みPDOインスタンスを設定
     *
     * @param $pdo PDO connection object
     * @param string $name Connection name
     * @return void;
     */
    final public static function setPDOInstance(PDO $pdo, $name='default'): void
    {
        self::$_pdo_container[$name] = $pdo;
    }

    /**
     * 接続済みPDOオブジェクトを取得
     *
     * @param string $name Connection name
     * @return PDO connection object
     */
    final public static function getPDOInstance($name='default'): PDO
    {
        if (!isset(self::$_pdo_container[$name]))
            throw new InvalidArgumentException("Connection named : ${name} was not found.");
        return self::$_pdo_container[$name];
    }

    /**
     * 値の反映
     *
     * @param $data mixed
     */
    public function __construct($data=null)
    {
        $this->setData($data);
    }

    /**
     * DBに保存しないプロパティ（フィールド名）を識別する接頭文字
     * 一時的にインスタンスに値を保存しておきたい時に使用する
     */
    protected static $private_prefix = '_';

    /**
     * フィールドがプライベート（DBに保存しない、一時保存領域）かどうかを判別
     *
     * @param $field
     * @return bool
     */
    private function _isPrivate($field)
    {
        return (strpos($field, static::$private_prefix) === 0);
    }

    /**
     * Getter マジックメソッド
     *
     * @param $field   : ゲットするフィールド名
     * @return mixed
     */
    public function __get($field)
    {
        return $this->get($field);
    }

    /**
     * Setter マジックメソッド
     *
     * @param $field : セットするフィールド名
     * @param $value : セットする値
     * @return void;
     */
    public function __set($field, $value)
    {
        $this->set($field, $value);
    }

    /**
     * Getter マジックメソッドの実装
     *
     * フィールドの存在、あるいはプライベートフィールドかを確認、どちらでも無ければ例外送出
     * データが存在しなければ、初期値を返す
     * @param $key string
     * @param $default mixed 初期値
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function get($key, $default=null)
    {
        if (!array_key_exists($key, static::$fields) && !$this->_isPrivate($key)) {
            $msg = $this->getKeyNotFoundMessage($key);
            throw new InvalidArgumentException($msg);
        }
        if (array_key_exists($key, $this->_data)) {
            return $this->_data[$key];
        }
        return $default;
    }

    /**
     *  Setter マジックメソッドの実装
     *
     *  エイリアスフィールド名の場合、実名に変換する
     *  フィールドが存在、あるいはプライベートフィールドであれば、値を保存
     *  @param $key string フィールド名
     *  @param $value mixed 値
     *  @throws InvalidArgumentException
     */
    public function set($key, $value)
    {
        if (array_key_exists($key, static::$fields) || $this->_isPrivate($key)) {
            $this->setDirtyField($key, $value);
            $this->_data[$key] = $value;
            return;
        }
        $msg = $this->getKeyNotFoundMessage($key);
        throw new InvalidArgumentException($msg);
    }

    /**
     *  インスタンスへのデータの一括登録
     *
     *  同クラスのインスタンス、stdClass のインスタンス、
     *  配列データを引数に取り、データを登録する
     *  実フィールドとエイリアスフィールドの両方が混在している場合、どちらの値がセットされるかは不定（混ぜないで！）
     *
     *  @param $data mixed クラスのインスタンス、標準クラスのインスタンス、または配列
     *  @return void
     */
    public function setData($data=null)
    {
        if (is_null($data)) {
            return;
        }
        $array_data = self::convertData($data);
        foreach (array_keys($array_data) as $key) {
            $this->set($key, $array_data[$key]);
        }
    }

    /**
     * オブジェクトインスタンスを連想配列表現に変換する
     *
     * @param $data
     * @return array|null
     */
    public static function convertData($data)
    {
        if (is_null($data)) {
            return $data;
        }
        $arr = null;
        if ($data instanceof static) {
            $arr = $data->_data;
        } elseif (is_object($data)) {
            $arr = get_object_vars($data);
        } else {
            $arr = $data;
        }
        if (is_array($arr)) {
            foreach ($arr as $k1 => $v1) {
                $arr[$k1] = self::convertData($v1);
            }
        }
        return $arr;
    }

    /**
     * クラスに指定されたフィールドが見つからない場合のメッセージを作成
     *
     * @param $key string   "見つからなかったフィールド名"
     * @return string "InvalidArgumentException に埋め込むメッセージ"
     */
    protected function getKeyNotFoundMessage($key)
    {
        return $key . ' does not exist in ' . get_class($this) . ' class.';
    }

    const WITH_HIDDEN  = 'with_hidden';
    const WITH_DELETED = 'with_deleted';
    const FETCH_MODE   = 'fetch_mode';
    /**
     * @param $params array 検索条件となるパラメータ連想配列
     * [
     *     self::SELECT => [], // 指定が有る場合は、モデルインスタンスではなく配列を返す
     *     self::JOIN   => [], // 指定が有る場合は、モデルインスタンスではなく配列を返す
     *     self::WHERE  => [
     *         self::OP_OR => [
     *             ['field1' => 'value1'], // 省略時は self::OP_EQ
     *             ['field2' => [self::OP_NE => 'value2']],
     *             [self::OP_AND => [
     *                 ['field3' => [self::OP_GE => 'value3']],
     *                 ['field4' => [self::OP_LT => 'value4']]
     *             ],
     *         ]
     *     ],
     *     self::ORDER_BY => [],
     *     self::LIMIT => M, // 数値
     *     self::OFFSET => N, // 数値
     *     self::FOR_UPDATE => false / true
     * ]
     * @param $opts array オプション
     * [
     *     static::WITH_HIDDEN  => false|true,
     *     static::WITH_DELETED => false|true,
     *     static::FETCH_MODE   => PDO::FETCH_FUNC | PDO::FETCH_ASSOC | PDO::FETCH_CLASS,
     * ]
     * @return array
     * @throws Exception
     */
    public static function find(array $params=[], ?array $opts=null)
    {
        $params[Sql::FROM] = static::getTableName();

        if (!isset($params[Sql::SELECT])) {
            $with_hidden = isset($opts[static::WITH_HIDDEN]) ? true : false;
            $params[Sql::SELECT] = static::getFieldNames($with_hidden);
        }
        if (!isset($opts[static::WITH_DELETED]) && static::$soft_delete_field) {
            $where = isset($params[Sql::WHERE]) ? $params[Sql::WHERE] : [];
            $params[Sql::WHERE] = Sql::addWhere([self::$soft_delete_field => null], $where);
        }
        if (!isset($params[Sql::ORDER_BY])) {
            $params[Sql::ORDER_BY] = static::getPrimaryKeys();
        }

        list($sql, $bind) = Sql::buildQuery($params);
        $logger = static::getLogger();
        $logger->debug($sql);
        $logger->debug(var_export($bind, true));
        $stmt = self::execute($sql, $bind);
        $fetch_mode = isset($opts[static::FETCH_MODE]) ? $opts[static::FETCH_MODE] : PDO::FETCH_ASSOC;
        if ($fetch_mode == PDO::FETCH_CLASS) {
            return $stmt->fetchAll(PDO::FETCH_CLASS, static::class);
        } else {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /**
     * 検索条件に合うレコードの件数を取得する
     *
     * @param array $params
     * @param array|null $opts
     * @return mixed
     * @throws Exception
     */
    public static function count(array $params=[], ?array $opts=null): int
    {
        $params[Sql::FROM] = static::getTableName();

        if (!isset($opts[static::WITH_DELETED]) && static::$soft_delete_field) {
            $where = isset($params[Sql::WHERE]) ? $params[Sql::WHERE] : [];
            $params[Sql::WHERE] = Sql::addWhere([self::$soft_delete_field => null], $where);
        }

        list($sql, $bind) = Sql::buildCountQuery($params);
        $logger = static::getLogger();
        $logger->debug($sql);
        $logger->debug(var_export($bind, true));
        return self::execute($sql, $bind)->fetchColumn();
    }

    /**
     * @param $sql
     * @param $bind
     * @return PDOStatement
     * @throws Exception
     */
    public static function execute($sql, $bind): PDOStatement
    {
        $pdo  = self::getPDOInstance();
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($bind)) {
            $msg = 'SELECT Query was failed : ' . $sql . PHP_EOL;
            $msg.= '  ERROR CODE : ' . $stmt->errorCode();
            $msg.= '  ERROR INFO : ' . $stmt->errorInfo();
            $logger = static::getLogger();
            $logger->error($msg);
            throw new Exception($msg);
        }
        return $stmt;
    }

    /**
     * @param $params
     * @return array
     * @throws Exception
     */
    public static function paginate($params): array
    {
        $current_page = isset($params['page']) ? (int)$params['page'] : 1;

        $c_params = $params;
        unset($c_params[Sql::LIMIT]);
        unset($c_params[Sql::OFFSET]);
        $total_items = self::count($c_params);

        $item_limit  = (int)$params[Sql::LIMIT];
        $total_pages = (int)ceil($total_items / (int)$item_limit);

        if ($current_page > $total_pages)
            $current_page = $total_pages;

        $params[Sql::OFFSET] = ($current_page - 1) * (int)$params[Sql::LIMIT];
        return [
            'page'  => $current_page,
            'limit' => $item_limit,
            'total_pages' => $total_pages,
            'total_items' => $total_items,
            'items' => self::find($params),
        ];
    }

    /**
     * find one record by using primary key(s)
     *
     * @param $data array この場合、引数に指定できるデータは、プライマリキーと値の連想配列、クラスインスタンスのどちらか
     * @param null $opts オプション
     *     static::WITH_HIDDEN  => false|true,
     *     static::WITH_DELETED => false|true,
     * @return Model|null
     * @throws Exception
     */
    public static function findById($data, $opts=null)
    {
        $where = Sql::getPrimaryKeyConditions(static::getPrimaryKeys(), $data);
        if (!isset($opts[static::WITH_DELETED])) {
            $params[Sql::WHERE] = Sql::addWhere(['deleted_at' => null], $where);
        }

        $params = [
            Sql::SELECT => self::getFieldNames(),
            Sql::WHERE  => $where
        ];
        $results = self::find($params, [static::FETCH_MODE => PDO::FETCH_CLASS]);
        if (count($results) > 1) {
            throw new Exception();
        }
        return (count($results) == 1) ? $results[0] : null;
    }

    public static function getMsecDate(): string
    {
        return date("Y-m-d H:i:s") . '.' . substr(explode('.', (microtime(true) . ''))[1], 0, 3);
    }

    /**
     * @param $data mixed
     * @param $ignore bool
     * @return mixed
     * @throws Exception
     */
    public static function insert($data, $ignore=false)
    {
        $logger = static::getLogger();
        $me = ($data instanceof static) ? $data : new static($data);
        $dt = self::getMsecDate();
        $me->created_at = $dt;
        $me->updated_at = $dt;
        list($sql, $ctx) = Sql::buildInsertQuery(static::getTableName(), static::$fields, $me->_data, $ignore);
        $logger->debug($sql);
        $logger->debug(var_export($ctx, true));
        $pdo = self::getPDOInstance();
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($ctx)) {
            $logger->error($stmt->errorCode());
            $logger->error(var_export($stmt->errorInfo(), true));
            throw new Exception('INSERT Query was failed : '.$sql);
        }
        $me->id = $pdo->lastInsertId();
        return $me;
    }

    /**
     * インスタンス自身のプライマリキーを指定して UPDATE を実行する
     *
     * @return $this
     * @throws Exception
     */
    public function update()
    {
        $logger = static::getLogger();
        $dt = self::getMsecDate();
        $this->updated_at = $dt;
        list($sql, $ctx) = Sql::buildUpdateQuery(static::getTableName(), static::$fields, static::getPrimaryKeys(), $this->_data);
        $logger->debug($sql);
        $logger->debug(var_export($ctx, true));
        $pdo = self::getPDOInstance();
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($ctx)) {
            $logger = static::getLogger();
            $logger->error($stmt->errorCode());
            $logger->error(var_export($stmt->errorInfo(), true));
            throw new Exception('UPDATE Query was failed : '.$sql);
        }
        return $this;
    }
}
