<?php
namespace Burdock\DataModel;

use iamcal\SQLParser;

class Migrator
{
    /**
     * CREATE TABLE文の生成
     *
     * @param string $table_name
     * @param array $fields
     * @return string
     */
    public static function getCreateTableQuery(string $table_name, array $fields)
    {
        $pkeys = [];
        $uniques = [];
        $indexes = [];
        $s = "CREATE TABLE " . Sql::wrap($table_name) . " (\n";
        $fs = [];
        foreach ($fields as $field => $attr) {
            $f = "  " . self::Sql($field);
            if ($attr['type'] == "integer") {
                $f.= " INT(11)";
            }
            if ($attr['type'] == "string") {
                $f.= " VARCHAR(255)";
            }
            if ($attr['type'] == "text") {
                $f.= " TEXT";
            }
            if ($attr['type'] == "datetime") {
                $f.= " DATETIME";
            }
            if ($attr['type'] == "datetime(3)") {
                $f.= " DATETIME(3)";
            }
            if (array_key_exists('unsigned', $attr) && $attr['unsigned']) {
                $f.= " UNSIGNED";
            }
            if (array_key_exists('required', $attr) && $attr['required']) {
                $f.= " NOT NULL";
            }
            if (array_key_exists('auto_increment', $attr) && $attr['auto_increment']) {
                $f.= " AUTO_INCREMENT";
            }
            if (array_key_exists('primary', $attr) && $attr['primary']) {
                $pkeys[] = "`{$field}`";
            }
            if (array_key_exists('default', $attr)) {
                $f.= " DEFAULT";
                if ($attr['type'] == "integer") {
                    $f.= " {$attr['default']}";
                } else {
                    $f.= " \"{$attr['default']}\"";
                }
            }
            if (array_key_exists('index', $attr)) {
                if (array_key_exists($attr['index'], $indexes)
                    && is_array($indexes[$attr['index']])) {
                    $indexes[$attr['index']][] = "`{$field}`";
                } else {
                    $indexes[$attr['index']] = [];
                    $indexes[$attr['index']][] = "`{$field}`";
                }
            }
            if (array_key_exists('unique', $attr)) {
                if ($attr['unique'] === true) {
                    $f.= " UNIQUE";
                } elseif (array_key_exists($attr['unique'], $uniques) && is_array($uniques[$attr['unique']])) {
                    $uniques[$attr['unique']][] = "`{$field}`";
                } else {
                    $uniques[$attr['unique']] = [];
                    $uniques[$attr['unique']][] = "`{$field}`";
                }
            }
            $fs[] = $f;
        }
        $s.= implode(",\n", $fs);
        $opts = [];
        if (count($pkeys) > 0) {
            $o = "  PRIMARY KEY (" . implode(", ", $pkeys) . ")";
            $opts[] = $o;
        }
        if (count($indexes) > 0) {
            foreach ($indexes as $idx => $fields) {
                $o = "  INDEX `{$idx}` (" . implode(", ", $fields) . ")";
                $opts[] = $o;
            }
        }
        if (count($uniques) > 0) {
            foreach ($uniques as $idx => $fields) {
                $o = "  UNIQUE INDEX `{$idx}` (" . implode(", ", $fields) . ")";
                $opts[] = $o;
            }
        }
        if (count($opts) > 0) {
            $s.= ",\n";
            $s.= implode(",\n", $opts);
        }
        $s.= "\n) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_bin;";
        return $s;
    }

    public static function getTables(\PDO $pdo): array
    {
        $q = $pdo->prepare("SHOW TABLES");
        $q->execute();
        $tables = $q->fetchAll(\PDO::FETCH_COLUMN);
        return $tables;
    }

    public function getTableDefs(\PDO $pdo, $table_name): array
    {
        $field_types = [
            'TINYINT'  => 'integer',
            'INT'      => 'integer',
            'SMALLINT' => 'integer',
            'BIGINT'   => 'integer',
            'CHAR'     => 'string',
            'VARCHAR'  => 'string',
            'TEXT'     => 'text',
            'DATETIME' => 'datetime',
        ];

        $q = $pdo->prepare("SHOW CREATE TABLE `${table_name}`");
        $q->execute();
        $cts = $q->fetchAll(\PDO::FETCH_ASSOC);

        $fields = [];
        $parser = new SQLParser();
        foreach ($cts as $ct) {
            $table = $ct['Table'];
            $parser->parse($ct['Create Table']);
            // フィールド定義
            foreach ($parser->tables[$table]['fields'] as $_f) {
                $fields[$_f['name']] = [];
                $fields[$_f['name']]['type'] = $field_types[$_f['type']];
                if (isset($_f['fsp']))
                    $fields[$_f['name']]['type'].= "(${_f['fsp']})";
                if (isset($_f['length']))
                    $fields[$_f['name']]['length'] = $_f['length'];
                if (isset($_f['unsigned']))
                    $fields[$_f['name']]['unsigned'] = true;
                if (!isset($_f['null']) || $_f['null'] === false)
                    $fields[$_f['name']]['required'] = true;
                if (isset($_f['auto_increment']))
                    $fields[$_f['name']]['auto_increment'] = true;
                if (isset($_f['default']))
                    $fields[$_f['name']]['default'] = ($_f['default'] === 'NULL') ? null : $_f['default'];
            }
            // インデックス定義
            foreach ($parser->tables[$table]['indexes'] as $idx) {
                if ($idx['type'] === 'PRIMARY') {
                    foreach ($idx['cols'] as $i => $col) {
                        $fields[$col['name']]['primary'] = $i + 1;
                    }
                } elseif ($idx['type'] === 'UNIQUE') {
                    foreach ($idx['cols'] as $i => $col) {
                        $fields[$col['name']]['unique'] = [$idx['name'], $i];
                    }
                } elseif ($idx['type'] === 'INDEX') {
                    foreach ($idx['cols'] as $i => $col) {
                        $fields[$col['name']]['index'] = [$idx['name'], $i];
                    }
                } elseif ($idx['type'] === 'FULLTEXT') {
                    foreach ($idx['cols'] as $i => $col) {
                        $fields[$col['name']]['index'] = [$idx['name'], $i];
                    }
                }
            }
        }
        return [$fields, $parser->tables[$table_name]['props']];
    }

    public static function getTableDefsJson(\PDO $pdo): string
    {
        $defs = [];
        $tables = self::getTables($pdo);
        foreach ($tables as $table) {
            list($fields, $props) = self::getTableDefs($pdo, $table);
            $defs[$table] = $props;
            $defs[$table]['fields'] = $fields;
        }
        return json_encode($defs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}