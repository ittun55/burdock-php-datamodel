<?php


namespace Burdock\DataModel;


use Exception;

class DuplicatedUniqueIndexException extends \Exception
{
    public $invalids = null;
    /**
     * UniqueIndexException constructor.
     * @param $message
     * @param int $code
     * @param Exception|null $previous
     * @param array|null $invalids インデックス名をキー、フィールドの配列をバリューとした連想配列
     */
    public function __construct($message, $code = 0, Exception $previous = null, array $invalids = null)
    {
        parent::__construct($message, $code, $previous);
        $this->invalids = $invalids;
    }

    public function getValidatedErrors()
    {
        $errors = [];
        foreach($this->invalids as $name => $cols) {
            foreach($cols as $col) {
                if (!isset($errors[$col])) $errors[$col] = [];
                $msg = "すでに値（の組み合わせ）が存在します. {$name}: " . implode(', ', $cols);
                $errors[$col][] = $msg;
            }
        }
    }
}