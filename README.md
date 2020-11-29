# burdock-php-datamodel

## Features

データベース検索の各種条件を array 形式で指定できる ActiveRecord データモデル.

### DataModel メソッド

* Model::setPDOInstance(PDO $pdo, string $name='default') : pdo オブジェクトを複数保持可能
* Model::getPDOInstance(string $name='default') : 指定した名前の pdo オブジェクトを取得
* Model::setLogger($logger) : ロガーを指定することでSQLクエリを出力可能
* Model::getLogger() : ロガーを取得. 指定されていなければ NullLogger インスタンスを返す
* Model::loadSchema($schema) : 対応するテーブルスキーマを指定.
  * iamcal/sql-parser の出力ファイルをそのまま指定可能.

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
    * Sql::INNER|Sql::OUTER => ['tablename_to_join alias', [ON結合条件, ...]]
      * ON結合条件が複数指定された場合は、自動で AND 結合される
      * ON結合条件の書式は検索条件を参照

  * WHERE で指定可能な条件: 検索条件単体、または AND か OR でラップされた複数の検索条件
    * 単体の検索条件
    * Sql::AND または Sql::OR をキーにもつ連想配列で、値は複数の検索条件を要素に持つ配列
  
  * 検索条件: フィールド名をキーにもつ連想配列
    * ['field2' => [Sql::OP_NE => 'value2']] // 値は比較演算子をキー比較する対象を値とする
    * ['field1' => 'value1']                 // 値が単一の値で比較演算子省略時はイコールで比較

  * ソート条件:  以下のいずれかの文字列表現を要素に持つ配列
    * 'table.field [ASC|DESC]'
    * 'field [ASC|DESC]'
    * 'alias [ASC|DESC]'

* $opts オプション

```
[
    static::WITH_HIDDEN  => false|true,
    static::WITH_DELETED => false|true,
    static::FETCH_MODE   => PDO::FETCH_FUNC | PDO::FETCH_ASSOC | PDO::FETCH_CLASS,
    static::FOR_UPODATE  => false|true
]

