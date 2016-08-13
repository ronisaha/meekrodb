<?php

namespace Meekro;

class Transaction {
    private $committed = false;

    function __construct() {
        DB::startTransaction();
    }
    function __destruct() {
        if (! $this->committed) DB::rollback();
    }
    function commit() {
        DB::commit();
        $this->committed = true;
    }
}