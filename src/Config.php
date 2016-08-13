<?php

namespace Meekro;


class Config
{
    public static $param_char = '%';
    public static $named_param_seperator = '_';
    public static $success_handler = false;
    public static $error_handler = true;
    public static $throw_exception_on_error = false;
    public static $nonsql_error_handler = null;
    public static $throw_exception_on_nonsql_error = false;
    public static $nested_transactions = false;
    public static $usenull = true;
    public static $ssl = array('key' => '', 'cert' => '', 'ca_cert' => '', 'ca_path' => '', 'cipher' => '');
    public static $connect_options = array(MYSQLI_OPT_CONNECT_TIMEOUT => 30);
}