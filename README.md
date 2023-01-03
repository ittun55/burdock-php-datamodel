# burdock-php-datamodel

## Features

データベース検索の各種条件を array 形式で指定できる ActiveRecord データモデル.

### DataModel メソッド

#### PDO の注入

* Model::setPDOInstance(PDO $pdo, string $name='default'): void PDO オブジェクトを複数保持可能
* Model::getPDOInstance(string $name='default'): PDO 指定した名前の PDO オブジェクトを取得

#### ロガー関連

* Model::setLogger(LoggerInterface$logger): void
  * ロガーを指定することでSQLクエリを出力可能
* Model::getLogger(): LoggerInterface
  * ロガーを取得. 指定されていなければ NullLogger インスタンスを返す

#### モデル定義関連

* Model::getTableName(): string
* Model::loadSchema(array $schema): void
  * テーブルスキーマを読み込む.
  * iamcal/sql-parser の出力ファイルをそのまま指定可能.
* Model::getFieldNames(bool $with_hidden=false): array 
* Model::getField(string $name): ?array
* Model::getIndexes(): array
* Model::getPrimaryKeys(): array
* Model::_isPrivate(bool $field): bool

#### インスタンスデータ操作

* $model->__set(string $field, $value): void
* $model->set(string $key, $value): void
* $model->__get(string $field)
* $model->get($key, $default=null)
* $model->setData($data=null): void
* $model->getData($with_hidden=false): array
* $model->setDirtyField(string $field, $value): void
* $model->isDirty($fields=null): bool
* $model->convertData($data): array
* $model->getKeyNotFoundMessage(string $key): string

#### データベース操作

* Model::find(array $params=[], ?array $opts=null, ?PDO $pdo=null): array
* Model::findById($data, ?array $opts=null, ?PDO $pdo=null)
* Model::findOne(array $params=[], ?array $opts=null, ?PDO $pdo=null)
* Model::count(array $params=[], ?array $opts=null, ?PDO $pdo=null): int
* Model::paginate($params): array
* $model->insert(?PDO $pdo=null, $ignore=false): void
* $model->update(?PDO $pdo=null, bool $diff=false): self
* $model->delete(?bool $hard=false, ?PDO $pdo=null): self

#### ユーティリティ

* $model->convertJsonFields($data): array
* backupLoaded(): void
* getDiffs(): array
* getMsecDate(): string
* setDiffs(): void


### DataModel プロパティ

* soft_delete_field : 指定されたフィールドを論理削除フィールドとして検索等行う
* json_fields : JSON に encode / decode するフィールドを指定
  * 対象フィールドにデータをインスタンス保存時に自動で JSON シリアライズ化
  * インスタンスプロパティまたは $model->get(), $model->getData() 経由でデータを取得すると自動で配列データに変換される
  * find() 系メソッドで配列出力の場合は、JSON化されない. 必要な場合は Model::convertJsonFields($data) で変換可能。
* updated_at は値が設定されていなければ、自動で値を付与する
  * 値が手動で設定された場合はその値で上書きする

### find(array $params=[], ?array $opts=null, ?PDO $pdo=null)

* $params 検索条件となるパラメータ連想配列

```
[
    Sql::SELECT => [], // 指定が有る場合は、モデルインスタンスではなく配列を返す
    Sql::JOIN   => [  // 指定が有る場合は、モデルインスタンスではなく配列を返す
        [
            'inner' => ['table_a tbl_a', [
                ['tbl_a.tbl_id', 'tbl.id'],
                ['tbl_a.deleted_at' => null],
                ['tbl_a.owner_id' => [Sql::EQ => 999]]
            ]]
        ]
    ],
    Sql::WHERE  => [
        Sql::OP_OR => [
            ['field1' => 'value1'], // 省略時は self::OP_EQ
            ['field2' => [Sql::OP_NE => 'value2']],
            [Sql::OP_AND => [
                ['field3' => [Sql::OP_GE => 'value3']],
                ['field4' => [Sql::OP_LT => 'value4']]
            ],
        ]
    ],
    Sql::ORDER_BY => [],
    Sql::LIMIT => M, // 数値
    Sql::OFFSET => N, // 数値
    Sql::FOR_UPDATE => false / true
]
```

  * SELECT で指定可能な条件: 以下のいずれかの文字列表現を要素に持つ配列
    * 'table.field alias' table, field をバッククォートでラップ
    * 'table.field' table, field をバッククォートでラップ
    * 'field'　field をバッククォートでラップ
    * '@@...'　何も変換せずに SQL として出力
  * SELECT が無指定の場合、Model::getFieldNames() がセットされる

  * JOIN で指定可能な条件: INNER または OUTER の JOIN 条件を１つ以上含む配列
    * ※※※ INNER または OUTER の JOIN 条件配列単体では動作せず、複数の JOIN 条件をラップする外側の配列が必要 ※※※
    * Sql::INNER | Sql::OUTER => ['tablename_to_join alias', [ON 結合条件, ...]]
      * ※※※ ON 結合条件単体では動作せず、複数の結合条件をラップする外側の配列が必要 ※※※
      * ON 結合条件が複数指定された場合は、自動で AND 結合される
      * ON 結合条件の書式は検索条件を参照

  * WHERE で指定可能な条件: 検索条件単体、または AND か OR でラップされた複数の検索条件
    * 単体の検索条件
    * Sql::AND または Sql::OR をキーにもつ連想配列で、値は複数の検索条件を要素に持つ配列
  
  * 検索条件: フィールド名をキーにもつ連想配列
    * ['field2' => [Sql::OP_NE => 'value2']] // 値は比較演算子をキー比較する対象を値とする
    * ['field1' => 'value1']                 // 値が単一の値で比較演算子省略時はイコールで比較

  * ソート条件:  以下のいずれかの文字列表現を要素に持つ配列
    * 'table.field [ASC | DESC]'
    * 'field [ASC | DESC]'
    * 'alias [ASC | DESC]'

* $opts オプション

```
[
    static::WITH_HIDDEN  => false|true,
    static::WITH_DELETED => false|true,
    static::FETCH_MODE   => PDO::FETCH_FUNC | PDO::FETCH_ASSOC | PDO::FETCH_CLASS,
    static::FOR_UPODATE  => false|true
]
```

### findById($data, ?array $opts=null, ?PDO $pdo=null)

* $data プライマリキーと値の連想配列、クラスインスタンスのどちらか
* $opts, $pdo は find() と同様
* $opts の FETCH_MODE を指定しなければ、モデルクラスのインスタンスを返す
* 結果が 1 件より大きい場合は例外送出、0 件の場合は null を返す

### findOne(array $params=[], ?array $opts=null, ?PDO $pdo=null)

* $params 結果を一意に特定できる、フィールドと値の連想配列
  * Sql::WHERE は省略する
  * 複数条件の結合は AND のみとし Sql::AND キーも省略する
* $opts, $pdo は find() と同様
* $opts の FETCH_MODE を指定しなければ、モデルクラスのインスタンスを返す
* 結果が 1 件より大きい場合は例外送出、0 件の場合は null を返す

