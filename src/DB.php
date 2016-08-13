<?php

namespace Meekro;

class DB
{
    // initial connection
    public static $dbName = '';
    public static $user = '';
    public static $password = '';
    public static $host = 'localhost';
    public static $port = 3306; //hhvm complains if this is null
    public static $encoding = 'utf8';

    /** @var DAL */
    protected static $mdb = null;

    public static function getInstance()
    {

        if (DB::$mdb === null) {
            DB::$mdb = new DAL();
        }

        return DB::$mdb;
    }

    public static function __callStatic($name, $args)
    {
        return call_user_func_array(array(DB::getInstance(), $name), $args);
    }

    public static function debugMode($handler = true)
    {
        Config::$success_handler = $handler;
    }

    public static function nonSQLError($message)
    {
        if (Config::$throw_exception_on_nonsql_error) {
            $e = new DBException($message);
            throw $e;
        }

        $error_handler = is_callable(Config::$nonsql_error_handler) ? Config::$nonsql_error_handler : __NAMESPACE__ . '\DB::errorHandler';

        call_user_func($error_handler, array(
            'type' => 'nonsql',
            'error' => $message
        ));
    }

    public static function errorHandler($params)
    {
        if (isset($params['query'])) $out[] = "QUERY: " . $params['query'];
        if (isset($params['error'])) $out[] = "ERROR: " . $params['error'];
        $out[] = "";

        if (php_sapi_name() == 'cli' && empty($_SERVER['REMOTE_ADDR'])) {
            echo implode("\n", $out);
        } else {
            echo implode("<br>\n", $out);
        }

        die;
    }

    public static function debugModeHandler($params)
    {
        echo "QUERY: " . $params['query'] . " [" . $params['runtime'] . " ms]";
        if (php_sapi_name() == 'cli' && empty($_SERVER['REMOTE_ADDR'])) {
            echo "\n";
        } else {
            echo "<br>\n";
        }
    }
}