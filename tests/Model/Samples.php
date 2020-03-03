<?php
use Burdock\DataModel\Model;

class Samples extends Model
{
    protected static $table_name = 'samples';

    protected static $fields = [
        'id' => [
            'type' => 'INT(11)',
            'unsigned' => true,
            'primary' => true,
            'auto_increment' => true
        ],
        'pkey_2' => [
            'type' => 'INT(11)',
            'primary' => true,
            'comment' => "primary が true の場合、required も true"
        ],
        'pkey_3' => [
            'type' => 'INT(11)',
            'primary' => true,
            'default' => 0
        ],
        'ukey_1' => [
            'type' => 'VARCHAR(255)',
            'default' => 'A',
            'unique' => 'ukey_123'
        ],
        'ukey_2' => [
            'type' => 'VARCHAR(255)',
            'default' => 'B',
            'unique' => 'ukey_123'
        ],
        'ukey_3' => [
            'type' => 'VARCHAR(255)',
            'default' => 'C',
            'unique' => 'ukey_123'
        ],
        'email'  => [
            'type' => 'VARCHAR(255)',
            'required' => true
        ],
        'status' => [
            'type' => 'VARCHAR(255)',
            'index' => 'idx_status'
        ],
        'created_at' => [
            'type' => 'DATETIME(3)',
            'required' => true
        ],
        'created_by' => [
            'type' => 'VARCHAR(255)',
            'required' => true
        ],
        'updated_at' => [
            'type' => 'DATETIME(3)',
            'required' => true
        ],
        'updated_by' => [
            'type' => 'VARCHAR(255)',
            'required' => true
        ],
        'deleted_at' => [
            'type' => 'DATETIME(3)'
        ],
        'deleted_by' => [
            'type' => 'VARCHAR(255)'
        ],
    ];

    //protected static $soft_delete_field = null;
    protected static $soft_delete_field = 'deleted_at';
}
Samples::loadSchema(__DIR__.'/../tmp/schema.json');