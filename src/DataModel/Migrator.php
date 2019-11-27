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
            $f = "  " . Sql::wrap($field);
            $f.= " ${attr['type']}";
            if (array_key_exists('unsigned', $attr) && $attr['unsigned']) {
                $f.= " UNSIGNED";
            }
            if (array_key_exists('null', $attr) && $attr['null'] === false) {
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
                if (is_null($attr['default'])) {
                    $f.= " NULL";
                } elseif ($attr['type'] == "integer") {
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

    public static function getTableDefs(\PDO $pdo, $table_name): array
    {
        $stmt = $pdo->prepare("SHOW CREATE TABLE `${table_name}`");
        $stmt->execute();
        $ct = $stmt->fetch();
        $parser = new SQLParser();
        $table = $ct['Table'];
        $parser->parse($ct['Create Table']);
        return $parser->tables[$table];
    }

    public static function getTableDefsJson(\PDO $pdo): string
    {
        $defs = [];
        $tables = self::getTables($pdo);
        foreach ($tables as $table) {
            $defs[$table] = self::getTableDefs($pdo, $table);
            $defs[$table]['fields'] = array_map(function($field) {
                if (isset($field['default']) && $field['default'] === "NULL")
                    $field['default'] = null;
                return $field;
            }, $defs[$table]['fields']);
        }
        return json_encode($defs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}