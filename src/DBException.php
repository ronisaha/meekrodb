<?php

namespace Meekro;


class DBException extends \Exception {
    protected $query = '';

    function __construct($message='', $query='', $code = 0) {
        parent::__construct($message);
        $this->query = $query;
        $this->code = $code;
    }

    public function getQuery() { return $this->query; }
}