<?php
use Burdock\DataModel\Model;

class Samples extends Model
{
    protected static $table_name = 'samples';

    protected static $fields = [
        'id' => [
            'type' => 'integer',
            'unsigned' => true,
            'primary' => true,
            'autoincrement' => true
        ],
        'pkey_2' => [
            'type' => 'integer',
            'primary' => true,
            'comment' => "primary が true の場合、required も true"
        ],
        'pkey_3' => [
            'type' => 'integer',
            'primary' => true,
            'default' => 0
        ],
        'ukey_1' => [
            'type' => 'string',
            'default' => 'A',
            'unique' => 'ukey_123'
        ],
        'ukey_2' => [
            'type' => 'string',
            'default' => 'B',
            'unique' => 'ukey_123'
        ],
        'ukey_3' => [
            'type' => 'string',
            'default' => 'C',
            'unique' => 'ukey_123'
        ],
        'email'  => [
            'type' => 'string',
            'required' => true
        ],
        'status' => [
            'type' => 'string',
            'index' => 'idx_status'
        ],
        'created_at' => [
            'type' => 'datetime(3)',
            'required' => true
        ], // datetime not null
        'created_by' => [
            'type' => 'string',
            'required' => true
        ], // varchar(255) not null
        'updated_at' => [
            'type' => 'datetime(3)',
            'required' => true
        ], // datetime not null
        'updated_by' => [
            'type' => 'string',
            'required' => true
        ], // varchar(255) not null
        'deleted_at' => [
            'type' => 'datetime(3)'
        ],   // datetime not null
        'deleted_by' => [
            'type' => 'string'
        ],  // varchar(255) not null
    ];

    //protected static $soft_delete_field = null;
    protected static $soft_delete_field = 'deleted_at';
}