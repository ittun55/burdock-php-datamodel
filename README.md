# burdock-php-datamodel

## Features

データベース検索の各種条件を array 形式で指定できる ActiveRecord データモデル.

### DataModel

* Model::setPDOInstance(PDO $pdo, string $name='default') : pdo オブジェクトを複数保持可能
* Model::getPDOInstance(string $name='default') : 指定した名前の pdo オブジェクトを取得
* Model::setLogger($logger) : ロガーを指定することでSQLクエリを出力可能
* Model::getLogger() : ロガーを取得. 指定されていなければ NullLogger インスタンスを返す
* Model::loadSchema($schema) : 対応するテーブルスキーマを指定.
  * iamcal/sql-parser の出力ファイルをそのまま指定可能.

* soft_delete_field : 指定されたフィールドを論理削除フィールドとして検索等行う
* json_fields : JSON に encode / decode するフィールドを指定
  * 対象フィールドにデータをインスタンス保存時に自動でJSON化
  * インスタンスプロパティまたは $model->get(), $model->getData() 経由でデータを取得すると自動で配列データに変換される
  * find() 系メソッドで配列出力の場合は、JSON化されない. 必要な場合は Model::convertJsonFields($data) で変換可能。
* updated_at は値が設定されていなければ、自動で値を付与する
  * 値が手動で設定された場合はその値で上書きする

### find(array $params=[], ?array $opts=null, ?PDO $pdo=null)

* $params 検索条件となるパラメータ連想配列

```
[
    Sql::SELECT => [], // 指定が有る場合は、モデルインスタンスではなく配列を返す
    Sql::JOIN   => [], // 指定が有る場合は、モデルインスタンスではなく配列を返す
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

* $opts オプション

```
[
    static::WITH_HIDDEN  => false|true,
    static::WITH_DELETED => false|true,
    static::FETCH_MODE   => PDO::FETCH_FUNC | PDO::FETCH_ASSOC | PDO::FETCH_CLASS,
    static::FOR_UPODATE  => false|true
]