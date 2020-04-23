<?php

use Burdock\Config\Config;
use Burdock\DataModel\Model;

class Samples extends Model
{
    protected static $table_name = 'samples';
    protected static $fields = null;
    protected static $soft_delete_field = 'deleted_at';
}
$config = Config::load(__DIR__.'/../tmp/schemas.json');
Samples::loadSchema($config->getValue());