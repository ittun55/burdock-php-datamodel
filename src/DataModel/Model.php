<?php
namespace Burdock\DataModel;

use PDO;
use Exception;
use PDOStatement;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;

/**
 * Class Model
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
     *  配列の要素は以下の構造のJSONとする
     *  {
     *    name:      'field_name',
     *    type:      'INT' | 'TINYINT' | 'VARCHAR' | 'DATETIME', など
     *    length:    '20', 文字列で。。。
     *    fsp:       '3', DATETIME で指定のあった場合
     *    collation: 'utf8_unicode_ci', 
     *    default:   null,
     *    null:      true
     *  }
     */
    protected static $fields = null;

    /**
     * @param $schema
     */
    public static function loadSchema($schema): void
    {
        if (!is_null(static::$fields)) return;
        if (array_key_exists('fields', $schema)) {
            static::$fields  = array_column($schema['fields'], null, 'name');
            static::$indexes = $schema['indexes'];
        } else {
            static::$fields  = array_column($schema[static::getTableName()]['fields'], null, 'name');
            static::$indexes = $schema[static::getTableName()]['indexes'];
        }
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
        $fields = [];
        foreach (static::$fields as $field) {
            if (isset($field['hidden'])) continue;
            $fields[] = $field;
        }
        return $fields;
    }

    public static function getField($name): ?array
    {
        $fields = static::$fields;
        // array_search() は最初にマッチした index を１つだけ返す. 無ければ false
        $index  = array_search($name, array_column($fields, 'name'));
        return ($index === false) ? null : $fields[$index];
    }

    /**
     * Getting field names only
     *
     * @param bool $with_hidden
     * @return array
     */
    public static function getFieldNames($with_hidden=false): array
    {
        $field_names = [];
        foreach (static::$fields as $def) {
            if (isset($def['hidden']) && !$with_hidden)
                continue;
            $field_names[] = $def['name'];
        }
        return $field_names;
    }

    /**
     *  @var array index definitions
     */
    protected static $indexes = [];

    /**
     * @return array
     */
    public static function getIndexes(): array
    {
        return static::$indexes;
    }

    /**
     * Getting primary key field definitions
     *
     * @return array
     */
    public static function getPrimaryKeys()
    {
        foreach (static::$indexes as $index) {
            if ($index['type'] === 'PRIMARY') {
                return array_column($index['cols'], 'name');
            }
        }
    }

    /**
     * @var array model data cache
     */
    protected $_data = [];

    /**
     * @var array model data from DB
     */
    protected $_loaded = [];

    protected static $json_fields = [];

    /**
     * find()の結果など、複数レコード配列に含まれるJSONフィールドをデコードする
     *
     * @param $data 複数レコードを含む配列
     * @return array
     */
    public static function convertJsonFields($data)
    {
        $items = [];
        foreach ($data as $item) {
            foreach (static::$json_fields as $field) {
                $item[$field] = json_decode($item[$field], true);
            }
            $items[] = $item;
        }
        return $items;
    }

    protected static $log_field = '';

    public function backupLoaded()
    {
        $this->_loaded = $this->_data;
    }

    public function getDiffs()
    {
        $diffs = [];
        foreach (static::$fields as $def) {
            $field = $def['name'];
            if (in_array($field, [static::$log_field, 'updated_at', 'updated_by'])) continue;
            if ($this->_loaded[$field] === $this->_data[$field]) continue;
            if (isset($def['hidden'])) {
                $diffs[] = [$field, '******', '******'];
            } elseif (strtoupper($def['type']) === 'TEXT') {
                // テキストの場合も、ファイルサイズは大きくなるが old と new を保存しておき、
                // 差分表示時点でツールを使って表示する
                $diffs[] = [$def, $this->_loaded[$def], $this->_data[$def]];
            } else {
                $diffs[] = [$def, $this->_loaded[$def], $this->_data[$def]];
            }
        }
        return $diffs;
    }

    protected function setDiffs()
    {
        $log_field   = static::$log_field;
        $json_fields = static::$json_fields;

        if (!$log_field) return;
        if (!in_array($log_field, $json_fields)) return;

        $logs  = $this->$log_field;
        if (!is_array($logs)) $logs = [];

        $logs[] = [
            'updated_at'  => $this->updated_at,
            'updated_by'  => $this->updated_by,
            'diffs'       => $this->getDiffs()
        ];
    }

    /**
     * @var array storage for the pair of modified field and original value
     */
    protected $_dirty_fields = [];

    /**
     * Save original value if modified
     *
     * @param string $field フィールド名
     * @param mixed $value 更新前の値
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
     * @param array | null $fields
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
            if (in_array($key, static::$json_fields)) {
                return json_decode($this->_data[$key], true);
            } else {
                return $this->_data[$key];
            }
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
            if (array_key_exists($key, $this->_data) && $this->_data[$key] != $value) {
                // 既に値をセットしたことがあり （初回DBデータロード時）、
                // これからセットする値がそれと異なっていれば dirty とする
                // insert の場合は初回DBデータロードと同じ扱いになってしまうため dirty 判定できない.
                $this->setDirtyField($key, $this->_data[$key]);
            }
            if (in_array($key, static::$json_fields) && is_array($value)) {
                $opt = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
                $this->_data[$key] = json_encode($value, $opt);
            } else {
                $this->_data[$key] = $value;
            }
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
     * モデルクラスインスタンス、その他クラスオブジェクトを連想配列表現に変換する
     *
     * @param $data
     * @return array|null
     */
    public static function convertData($data)
    {
        if (is_null($data)) return null;

        $arr = null;
        if ($data instanceof static) {
            $arr = $data->getData(true);
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
     * インスタンスに含まれるデータを連想配列で返す
     *
     * @return array
     */
    public function getData($with_hidden=false)
    {
        $data = [];
        foreach (static::getFieldNames($with_hidden) as $field) {
            $data[$field] = $this->get($field);
        }
        return $data;
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
    const FOR_UPDATE   = 'for_update';
    /**
     * @param array $params 検索条件となるパラメータ連想配列
     * [
     *     Sql::SELECT => [], // 指定が有る場合は、モデルインスタンスではなく配列を返す
     *     Sql::JOIN   => [], // 指定が有る場合は、モデルインスタンスではなく配列を返す
     *     Sql::WHERE  => [
     *         Sql::OP_OR => [
     *             ['field1' => 'value1'], // 省略時は self::OP_EQ
     *             ['field2' => [Sql::OP_NE => 'value2']],
     *             [Sql::OP_AND => [
     *                 ['field3' => [Sql::OP_GE => 'value3']],
     *                 ['field4' => [Sql::OP_LT => 'value4']]
     *             ],
     *         ]
     *     ],
     *     Sql::ORDER_BY => [],
     *     Sql::LIMIT => M, // 数値
     *     Sql::OFFSET => N, // 数値
     *     Sql::FOR_UPDATE => false / true
     * ]
     * @param array|null $opts オプション
     * [
     *     static::WITH_HIDDEN  => false|true,
     *     static::WITH_DELETED => false|true,
     *     static::FETCH_MODE   => PDO::FETCH_FUNC | PDO::FETCH_ASSOC | PDO::FETCH_CLASS,
     *     static::FOR_UPODATE  => false|true
     * ]
     * @param PDO|null $pdo PDOインスタンス
     * @return array
     * @throws Exception
     */
    public static function find(array $params=[], ?array $opts=null, ?PDO $pdo=null)
    {
        $params[Sql::FROM] = isset($params[Sql::FROM]) ? $params[Sql::FROM] : static::getTableName();

        if (!isset($params[Sql::SELECT]) || empty($params[Sql::SELECT])) {
            $with_hidden = isset($opts[static::WITH_HIDDEN]);
            $params[Sql::SELECT] = static::getFieldNames($with_hidden);
        }
        if (!isset($opts[static::WITH_DELETED]) && static::$soft_delete_field) {
            $where = isset($params[Sql::WHERE]) ? $params[Sql::WHERE] : [];
            $soft_delete_field = (is_array($params[Sql::FROM]))
                ? $params[Sql::FROM][1].'.'.self::$soft_delete_field
                : self::$soft_delete_field;
            $params[Sql::WHERE] = Sql::addWhere([$soft_delete_field => null], $where);
        }
        if (!isset($params[Sql::ORDER_BY])) {
            if (is_array($params[Sql::FROM])) {
                $alias = $params[Sql::FROM][1];
                $p_keys = array_map(function($item) use ($alias) {
                    return $alias.'.'.$item;
                }, static::getPrimaryKeys());
            } else {
                $p_keys = static::getPrimaryKeys();
            }
            $params[Sql::ORDER_BY] = $p_keys;
        }
        if (isset($opts[static::FOR_UPDATE]) && $opts[static::FOR_UPDATE] === true) {
            $params[Sql::FOR_UPDATE] = true;
        }

        list($sql, $bind) = Sql::buildQuery($params);
        $logger = static::getLogger();
        $logger->debug($sql);
        $logger->debug(var_export($bind, true));
        $stmt = self::execute($sql, $bind, $pdo);
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
     * @param PDO|null $pdo
     * @return mixed
     * @throws Exception
     */
    public static function count(array $params=[], ?array $opts=null, ?PDO $pdo=null): int
    {
        $params[Sql::FROM] = isset($params[Sql::FROM]) ? $params[Sql::FROM] : static::getTableName();

        if (!isset($opts[static::WITH_DELETED]) && static::$soft_delete_field) {
            $where = isset($params[Sql::WHERE]) ? $params[Sql::WHERE] : [];
            $soft_delete_field = (is_array($params[Sql::FROM]))
                ? $params[Sql::FROM][1].'.'.self::$soft_delete_field
                : self::$soft_delete_field;
            $params[Sql::WHERE] = Sql::addWhere([$soft_delete_field => null], $where);
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
     * @param PDO|null $pdo
     * @return PDOStatement
     * @throws Exception
     */
    public static function execute($sql, $bind, PDO $pdo=null): PDOStatement
    {
        $_pdo  = is_null($pdo) ? self::getPDOInstance() : $pdo;
        $stmt = $_pdo->prepare($sql);
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

        if ($total_pages == 0)
            $current_page = 1;
        else if ($current_page > $total_pages)
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
     *     static::FOR_UPDATE   => false|true,
     *     static::WITH_HIDDEN  => false|true,
     *     static::WITH_DELETED => false|true,
     *     static::FETCH_MODE   => PDO::FETCH_FUNC | PDO::FETCH_ASSOC | PDO::FETCH_CLASS,
     * @param PDO|null $pdo
     * @return Model|null
     * @throws Exception
     */
    public static function findById($data, $opts=null, ?PDO $pdo=null)
    {
        $where = Sql::getPrimaryKeyConditions(static::getPrimaryKeys(), $data);
        if (!isset($opts[static::WITH_DELETED])) {
            $params[Sql::WHERE] = Sql::addWhere(['deleted_at' => null], $where);
        }

        $params = [
            Sql::SELECT => self::getFieldNames(),
            Sql::WHERE  => $where
        ];

        if (isset($opts[static::FOR_UPDATE]) && $opts[static::FOR_UPDATE] === true) {
            $params[Sql::FOR_UPDATE] = true;
        }

        $fetch_mode = isset($opts[static::FETCH_MODE]) ? $opts[static::FETCH_MODE] : PDO::FETCH_CLASS;

        $results = self::find($params, [static::FETCH_MODE => $fetch_mode], $pdo);
        if (count($results) > 1) {
            throw new Exception();
        }
        return (count($results) == 1) ? $results[0] : null;
    }

    /**
     * @param array $params
     * @param array|null $opts
     * @param PDO|null $pdo
     * @return mixed|null
     * @throws Exception
     */
    public static function findOne(array $params, ?array $opts=null, ?PDO $pdo=null)
    {
        $conditions = [];

        foreach($params as $field => $value) {
            if (is_array($value)) {
                $key = array_keys($value)[0];
                $val = $value[$key];
                if (!in_array($key, array_keys(Sql::OP))) {
                    $msg = 'Specified operator ' . $key . ' is invalid.';
                    throw new Exception($msg);
                }
                $conditions[] = [$field => [$key => $val]];
            } else {
                $conditions[] = [$field => [Sql::EQ => $value]];
            }
        }

        $where = (count($conditions) > 1) ? [Sql::AND => $conditions] : $conditions[0];

        if (!isset($opts[static::WITH_DELETED])) {
            $params[Sql::WHERE] = Sql::addWhere(['deleted_at' => null], $where);
        }

        $params = [
            Sql::SELECT => self::getFieldNames(),
            Sql::WHERE  => $where
        ];

        if (isset($opts[static::FOR_UPDATE]) && $opts[static::FOR_UPDATE] === true) {
            $params[Sql::FOR_UPDATE] = true;
        }

        $fetch_mode = isset($opts[static::FETCH_MODE]) ? $opts[static::FETCH_MODE] : PDO::FETCH_CLASS;

        $results = self::find($params, [static::FETCH_MODE => $fetch_mode], $pdo);

        if (count($results) > 1) {
            throw new Exception();
        }
        return (count($results) == 1) ? $results[0] : null;
    }

    public static function getMsecDate(): string
    {
        list($sec, $msec) = explode('.', microtime(true) . '');
        return date("Y-m-d H:i:s", $sec) . '.' . substr($msec, 0, 3);
    }

    /**
     * @param $data mixed
     * @param $ignore bool
     * @return mixed
     * @throws Exception
     */
    public function insert(?PDO $pdo=null, $ignore=false)
    {
        $logger = static::getLogger();
        $applyLastInsertedId = array_key_exists('id', static::$fields) && is_null($this->id);
        $dt = self::getMsecDate();
        $this->created_at = $dt;
        $this->updated_at = $dt;
        list($sql, $ctx) = Sql::buildInsertQuery(static::getTableName(), static::$fields, $this->_data, $ignore);
        $logger->debug($sql);
        $logger->debug(var_export($ctx, true));
        $_pdo = is_null($pdo) ? self::getPDOInstance() : $pdo;
        $stmt = $_pdo->prepare($sql);
        if (!$stmt->execute($ctx)) {
            $logger->error($stmt->errorCode());
            $logger->error(var_export($stmt->errorInfo(), true));
            throw new Exception('INSERT Query was failed : '.$sql);
        }
        if ($applyLastInsertedId) $this->id = $_pdo->lastInsertId();
        return $this;
    }

    /**
     * インスタンス自身のプライマリキーを指定して UPDATE を実行する
     *
     * @param PDO|null $pdo
     * @param bool $diff データモデルの差分を保存する
     * @return $this
     * @throws Exception
     */
    public function update(?PDO $pdo=null, bool $diff=false)
    {
        $logger = static::getLogger();
        // 手動で更新日時をセットされている場合は、その値で更新する
        if (!$this->isDirty(['updated_at'])) {
            $dt = self::getMsecDate();
            $this->updated_at = $dt;
        }
        if ($diff) $this->setDiffs();
        list($sql, $ctx) = Sql::buildUpdateQuery(static::getTableName(), static::$fields, static::getPrimaryKeys(), $this->_data);
        $logger->debug($sql);
        $logger->debug(var_export($ctx, true));
        $_pdo = is_null($pdo) ? self::getPDOInstance() : $pdo;
        $stmt = $_pdo->prepare($sql);
        if (!$stmt->execute($ctx)) {
            $logger = static::getLogger();
            $logger->error($stmt->errorCode());
            $logger->error(var_export($stmt->errorInfo(), true));
            throw new Exception('UPDATE Query was failed : '.$sql);
        }
        return $this;
    }

    /**
     * インスタンス自身のプライマリキーを指定して DELETE または SOFT DELETE を実行する
     *
     * @param bool|null $hard
     * @param PDO|null $pdo
     * @return $this
     * @throws Exception
     */
    public function delete(?bool $hard=false, ?PDO $pdo=null)
    {
        $logger = static::getLogger();
        if ($hard || !self::$soft_delete_field) {
            list($sql, $ctx) = Sql::buildDeleteQuery(static::getTableName(), static::getPrimaryKeys(), $this->_data);
        } else {
            $dt = self::getMsecDate();
            $this->deleted_at = $dt;
            list($sql, $ctx) = Sql::buildUpdateQuery(static::getTableName(), static::$fields, static::getPrimaryKeys(), $this->_data);
        }

        $logger->debug($sql);
        $logger->debug(var_export($ctx, true));
        $_pdo = is_null($pdo) ? self::getPDOInstance() : $pdo;
        $stmt = $_pdo->prepare($sql);
        if (!$stmt->execute($ctx)) {
            $logger = static::getLogger();
            $logger->error($stmt->errorCode());
            $logger->error(var_export($stmt->errorInfo(), true));
            throw new Exception('DELETE Query was failed : '.$sql);
        }
        return $this;
    }
}
