<?php

namespace Meekro;

class DAL {
    // Connection Strings
    private $dbName = '';
    private $user = '';
    private $password = '';
    private $host = 'localhost';
    private $port = 3306;
    private $encoding = 'utf8';

    // internal
    private $internal_mysql = null;
    private $server_info = null;
    private $insert_id = 0;
    private $num_rows = 0;
    private $affected_rows = 0;
    private $nested_transactions_count = 0;


    const NESTED_TRANSACTIONS_ERROR_MESSAGE = "Nested transactions are only available on MySQL 5.5 and greater. You are using MySQL ";

    public function __construct($host=null, $user=null, $password=null, $dbName=null, $port=null, $encoding=null) {
        $this->host = $host === null ? DB::$host : $host;
        $this->user = $user === null ?  DB::$user : $user;
        $this->password = $password === null ?  DB::$password : $password;
        $this->dbName = $dbName === null ?  DB::$dbName : $dbName;
        $this->port = $port === null ?  DB::$port : $port;
        $this->encoding = $encoding === null ?  DB::$encoding : $encoding;
    }

    public function get() {
        $mysql = $this->internal_mysql;

        if (!($mysql instanceof \mysqli)) {
            if (! $this->port) $this->port = ini_get('mysqli.default_port');
            $mysql = new \mysqli();

            $connect_flags = 0;
            if (Config::$ssl['key']) {
                $mysql->ssl_set(Config::$ssl['key'], Config::$ssl['cert'], Config::$ssl['ca_cert'], Config::$ssl['ca_path'], Config::$ssl['cipher']);
                $connect_flags |= MYSQLI_CLIENT_SSL;
            }
            foreach (Config::$connect_options as $key => $value) {
                $mysql->options($key, $value);
            }

            if (!$mysql->real_connect($this->host, $this->user, $this->password, $this->dbName, $this->port, null, $connect_flags)) {
                DB::nonSQLError('Unable to connect to MySQL server! Error: ' . $mysql->connect_error);
                return null;
            }

            $mysql->set_charset($this->encoding);
            $this->internal_mysql = $mysql;
            $this->server_info = $mysql->server_info;
        }

        return $mysql;
    }

    public function disconnect() {
        $mysqli = $this->internal_mysql;
        if ($mysqli instanceof \mysqli) {
            if ($thread_id = $mysqli->thread_id) $mysqli->kill($thread_id);
            $mysqli->close();
        }
        $this->internal_mysql = null;
    }

    public function debugMode($handler = true) {
        Config::$success_handler = $handler;
    }

    public function serverVersion() { $this->get(); return $this->server_info; }
    public function transactionDepth() { return $this->nested_transactions_count; }
    public function insertId() { return $this->insert_id; }
    public function affectedRows() { return $this->affected_rows; }
    public function count() { $args = func_get_args(); return call_user_func_array([$this, 'numRows'], $args); }
    public function numRows() { return $this->num_rows; }

    public function useDB() { $args = func_get_args(); return call_user_func_array([$this, 'setDB'], $args); }
    public function setDB($dbName) {
        $db = $this->get();

        if (! $db->select_db($dbName))  {
            DB::nonSQLError("Unable to set database to $dbName");
            return;
        }

        $this->dbName = $dbName;
    }


    public function startTransaction() {
        if (Config::$nested_transactions && $this->serverVersion() < '5.5') {
            DB::nonSQLError(self::NESTED_TRANSACTIONS_ERROR_MESSAGE . $this->serverVersion());
            return null;
        }

        if (!Config::$nested_transactions || $this->nested_transactions_count == 0) {
            $this->query('START TRANSACTION');
            $this->nested_transactions_count = 1;
        } else {
            $this->query("SAVEPOINT LEVEL{$this->nested_transactions_count}");
            $this->nested_transactions_count++;
        }

        return $this->nested_transactions_count;
    }

    public function commit($all=false) {
        if (Config::$nested_transactions && $this->serverVersion() < '5.5') {
            DB::nonSQLError(self::NESTED_TRANSACTIONS_ERROR_MESSAGE . $this->serverVersion());
            return null;
        }

        if (Config::$nested_transactions && $this->nested_transactions_count > 0)
            $this->nested_transactions_count--;

        if (!Config::$nested_transactions || $all || $this->nested_transactions_count == 0) {
            $this->nested_transactions_count = 0;
            $this->query('COMMIT');
        } else {
            $this->query("RELEASE SAVEPOINT LEVEL{$this->nested_transactions_count}");
        }

        return $this->nested_transactions_count;
    }

    public function rollback($all=false) {
        if (Config::$nested_transactions && $this->serverVersion() < '5.5') {
            DB::nonSQLError(self::NESTED_TRANSACTIONS_ERROR_MESSAGE . $this->serverVersion());
            return null;
        }

        if (Config::$nested_transactions && $this->nested_transactions_count > 0)
            $this->nested_transactions_count--;

        if (!Config::$nested_transactions || $all || $this->nested_transactions_count == 0) {
            $this->nested_transactions_count = 0;
            $this->query('ROLLBACK');
        } else {
            $this->query("ROLLBACK TO SAVEPOINT LEVEL{$this->nested_transactions_count}");
        }

        return $this->nested_transactions_count;
    }

    protected function formatTableName($table) {
        $table = trim($table, '`');

        if (strpos($table, '.')) return implode('.', array_map([$this, 'formatTableName'], explode('.', $table)));
        else return '`' . str_replace('`', '``', $table) . '`';
    }

    public function update() {
        $args = func_get_args();
        $table = array_shift($args);
        $params = array_shift($args);
        $where = array_shift($args);

        $query = str_replace('%', Config::$param_char, "UPDATE %b SET %hc WHERE ") . $where;

        array_unshift($args, $params);
        array_unshift($args, $table);
        array_unshift($args, $query);
        return call_user_func_array([$this, 'query'], $args);
    }

    public function insertOrReplace($which, $table, $datas, $options=[]) {
        $datas = unserialize(serialize($datas)); // break references within array
        $keys = $values = [];

        if (isset($datas[0]) && is_array($datas[0])) {
            $var = '%ll?';
            foreach ($datas as $datum) {
                ksort($datum);
                if (! $keys) $keys = array_keys($datum);
                $values[] = array_values($datum);
            }

        } else {
            $var = '%l?';
            $keys = array_keys($datas);
            $values = array_values($datas);
        }

        if (isset($options['ignore']) && $options['ignore']) $which = 'INSERT IGNORE';

        if (isset($options['update']) && is_array($options['update']) && $options['update'] && strtolower($which) == 'insert') {
            if (array_values($options['update']) !== $options['update']) {
                return $this->query(
                    str_replace('%', Config::$param_char, "INSERT INTO %b %lb VALUES $var ON DUPLICATE KEY UPDATE %hc"),
                    $table, $keys, $values, $options['update']);
            } else {
                $update_str = array_shift($options['update']);
                $query_param = [
                    str_replace('%', Config::$param_char, "INSERT INTO %b %lb VALUES $var ON DUPLICATE KEY UPDATE ") . $update_str,
                    $table, $keys, $values];
                $query_param = array_merge($query_param, $options['update']);
                return call_user_func_array([$this, 'query'], $query_param);
            }

        }

        return $this->query(
            str_replace('%', Config::$param_char, "%l INTO %b %lb VALUES $var"),
            $which, $table, $keys, $values);
    }

    public function insert($table, $data) { return $this->insertOrReplace('INSERT', $table, $data); }
    public function insertIgnore($table, $data) { return $this->insertOrReplace('INSERT', $table, $data, ['ignore' => true]); }
    public function replace($table, $data) { return $this->insertOrReplace('REPLACE', $table, $data); }

    public function insertUpdate() {
        $args = func_get_args();
        $table = array_shift($args);
        $data = array_shift($args);

        if (! isset($args[0])) { // update will have all the data of the insert
            if (isset($data[0]) && is_array($data[0])) { //multiple insert rows specified -- failing!
                return DB::nonSQLError("Badly formatted insertUpdate() query -- you didn't specify the update component!");
            }

            $args[0] = $data;
        }

        if (is_array($args[0])) $update = $args[0];
        else $update = $args;

        return $this->insertOrReplace('INSERT', $table, $data, ['update' => $update]);
    }

    public function delete() {
        $args = func_get_args();
        $table = $this->formatTableName(array_shift($args));
        $where = array_shift($args);
        $buildquery = "DELETE FROM $table WHERE $where";
        array_unshift($args, $buildquery);
        return call_user_func_array([$this, 'query'], $args);
    }

    public function sqleval() {
        return new DBEval(call_user_func_array([$this, 'parseQueryParams'], func_get_args()));
    }

    protected function preparseQueryParams() {
        $args = func_get_args();
        $sql = trim(strval(array_shift($args)));
        $args_all = $args;

        if (count($args_all) == 0) return [$sql];

        $param_char_length = strlen(Config::$param_char);
        $named_seperator_length = strlen(Config::$named_param_seperator);

        $types = [
            Config::$param_char . 'll', // list of literals
            Config::$param_char . 'ls', // list of strings
            Config::$param_char . 'l',  // literal
            Config::$param_char . 'li', // list of integers
            Config::$param_char . 'ld', // list of decimals
            Config::$param_char . 'lb', // list of backticks
            Config::$param_char . 'lt', // list of timestamps
            Config::$param_char . 's',  // string
            Config::$param_char . 'i',  // integer
            Config::$param_char . 'd',  // double / decimal
            Config::$param_char . 'b',  // backtick
            Config::$param_char . 't',  // timestamp
            Config::$param_char . '?',  // infer type
            Config::$param_char . 'l?',  // list of inferred types
            Config::$param_char . 'll?',  // list of lists of inferred types
            Config::$param_char . 'hc',  // hash `key`='value' pairs separated by commas
            Config::$param_char . 'ha',  // hash `key`='value' pairs separated by and
            Config::$param_char . 'ho',  // hash `key`='value' pairs separated by or
            Config::$param_char . 'ss'  // search string (like string, surrounded with %'s)
        ];

        // generate list of all MeekroDB variables in our query, and their position
        // in the form "offset => variable", sorted by offsets
        $posList = [];
        foreach ($types as $type) {
            $lastPos = 0;
            while (($pos = strpos($sql, $type, $lastPos)) !== false) {
                $lastPos = $pos + 1;
                if (isset($posList[$pos]) && strlen($posList[$pos]) > strlen($type)) continue;
                $posList[$pos] = $type;
            }
        }

        ksort($posList);

        // for each MeekroDB variable, substitute it with [type: i, value: 53] or whatever
        $chunkyQuery = []; // preparsed query
        $pos_adj = 0; // how much we've added or removed from the original sql string
        foreach ($posList as $pos => $type) {
            $type = substr($type, $param_char_length); // variable, without % in front of it
            $length_type = strlen($type) + $param_char_length; // length of variable w/o %

            $new_pos = $pos + $pos_adj; // position of start of variable
            $new_pos_back = $new_pos + $length_type; // position of end of variable
//            $arg_number_length = 0; // length of any named or numbered parameter addition

            // handle numbered parameters
            if ($arg_number_length = strspn($sql, '0123456789', $new_pos_back)) {
                $arg_number = substr($sql, $new_pos_back, $arg_number_length);
                if (! array_key_exists($arg_number, $args_all)) return DB::nonSQLError("Non existent argument reference (arg $arg_number): $sql");

                $arg = $args_all[$arg_number];

                // handle named parameters
            } else if (substr($sql, $new_pos_back, $named_seperator_length) == Config::$named_param_seperator) {
                $arg_number_length = strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_',
                        $new_pos_back + $named_seperator_length) + $named_seperator_length;

                $arg_number = substr($sql, $new_pos_back + $named_seperator_length, $arg_number_length - $named_seperator_length);
                if (count($args_all) != 1 || !is_array($args_all[0])) return DB::nonSQLError("If you use named parameters, the second argument must be an array of parameters");
                if (! array_key_exists($arg_number, $args_all[0])) return DB::nonSQLError("Non existent argument reference (arg $arg_number): $sql");

                $arg = $args_all[0][$arg_number];

            } else {
               // $arg_number = 0;
                $arg = array_shift($args);
            }

            if ($new_pos > 0) $chunkyQuery[] = substr($sql, 0, $new_pos);

            if (is_object($arg) && ($arg instanceof Where)) {
                list($clause_sql, $clause_args) = $arg->textAndArgs();
                array_unshift($clause_args, $clause_sql);
                $preparsed_sql = call_user_func_array([$this, 'preparseQueryParams'], $clause_args);
                $chunkyQuery = array_merge($chunkyQuery, $preparsed_sql);
            } else {
                $chunkyQuery[] = ['type' => $type, 'value' => $arg];
            }

            $sql = substr($sql, $new_pos_back + $arg_number_length);
            $pos_adj -= $new_pos_back + $arg_number_length;
        }

        if (strlen($sql) > 0) $chunkyQuery[] = $sql;

        return $chunkyQuery;
    }

    public function escape($str) { return "'" . $this->get()->real_escape_string(strval($str)) . "'"; }

    public function sanitize($value, $type='basic', $hashjoin=', ') {
        if ($type == 'basic') {
            if (is_object($value)) {
                if ($value instanceof DBEval) return $value->getText();
                else if ($value instanceof \DateTime) return $this->escape($value->format('Y-m-d H:i:s'));
                else return $this->escape($value); // use __toString() value for objects, when possible
            }

            if (is_null($value)) return Config::$usenull ? 'NULL' : "''";
            else if (is_bool($value)) return ($value ? 1 : 0);
            else if (is_int($value)) return $value;
            else if (is_float($value)) return $value;
            else if (is_array($value)) return "''";
            else return $this->escape($value);

        } else if ($type == 'list') {
            if (is_array($value)) {
                $value = array_values($value);
                return '(' . implode(', ', array_map([$this, 'sanitize'], $value)) . ')';
            } else {
                return DB::nonSQLError("Expected array parameter, got something different!");
            }
        } else if ($type == 'doublelist') {
            if (is_array($value) && array_values($value) === $value && is_array($value[0])) {
                $cleanvalues = [];
                foreach ($value as $subvalue) {
                    $cleanvalues[] = $this->sanitize($subvalue, 'list');
                }
                return implode(', ', $cleanvalues);

            } else {
                return DB::nonSQLError("Expected double array parameter, got something different!");
            }
        } else if ($type == 'hash') {
            if (is_array($value)) {
                $pairs = [];
                foreach ($value as $k => $v) {
                    $pairs[] = $this->formatTableName($k) . '=' . $this->sanitize($v);
                }

                return implode($hashjoin, $pairs);
            } else {
                return DB::nonSQLError("Expected hash (associative array) parameter, got something different!");
            }
        } else {
            return DB::nonSQLError("Invalid type passed to sanitize()!");
        }

    }

    protected function parseTS($ts) {
        if (is_string($ts)) return date('Y-m-d H:i:s', strtotime($ts));
        else if (is_object($ts) && ($ts instanceof \DateTime)) return $ts->format('Y-m-d H:i:s');
        return null;
    }

    protected function intval($var) {
        if (PHP_INT_SIZE == 8) return intval($var);
        return floor(doubleval($var));
    }

    public function parseQueryParams() {
        $args = func_get_args();
        $chunkyQuery = call_user_func_array([$this, 'preparseQueryParams'], $args);

        $query = '';
        $array_types = ['ls', 'li', 'ld', 'lb', 'll', 'lt', 'l?', 'll?', 'hc', 'ha', 'ho'];

        foreach ($chunkyQuery as $chunk) {
            if (is_string($chunk)) {
                $query .= $chunk;
                continue;
            }

            $type = $chunk['type'];
            $arg = $chunk['value'];
            //$result = '';

            $is_array_type = in_array($type, $array_types, true);
            if ($is_array_type && !is_array($arg)) return DB::nonSQLError("Badly formatted SQL query: Expected array, got scalar instead!");
            else if (!$is_array_type && is_array($arg)) $arg = '';

            if ($type == 's') $result = $this->escape($arg);
            else if ($type == 'i') $result = $this->intval($arg);
            else if ($type == 'd') $result = doubleval($arg);
            else if ($type == 'b') $result = $this->formatTableName($arg);
            else if ($type == 'l') $result = $arg;
            else if ($type == 'ss') $result = $this->escape("%" . str_replace(['%', '_'], ['\%', '\_'], $arg) . "%");
            else if ($type == 't') $result = $this->escape($this->parseTS($arg));

            else if ($type == 'ls') $result = array_map([$this, 'escape'], $arg);
            else if ($type == 'li') $result = array_map([$this, 'intval'], $arg);
            else if ($type == 'ld') $result = array_map('doubleval', $arg);
            else if ($type == 'lb') $result = array_map([$this, 'formatTableName'], $arg);
            else if ($type == 'll') $result = $arg;
            else if ($type == 'lt') $result = array_map([$this, 'escape'], array_map([$this, 'parseTS'], $arg));

            else if ($type == '?') $result = $this->sanitize($arg);
            else if ($type == 'l?') $result = $this->sanitize($arg, 'list');
            else if ($type == 'll?') $result = $this->sanitize($arg, 'doublelist');
            else if ($type == 'hc') $result = $this->sanitize($arg, 'hash');
            else if ($type == 'ha') $result = $this->sanitize($arg, 'hash', ' AND ');
            else if ($type == 'ho') $result = $this->sanitize($arg, 'hash', ' OR ');

            else return DB::nonSQLError("Badly formatted SQL query: Invalid MeekroDB param $type");

            if (is_array($result)) $result = '(' . implode(',', $result) . ')';

            $query .= $result;
        }

        return $query;
    }

    protected function prependCall($function, $args, $prepend) { array_unshift($args, $prepend); return call_user_func_array($function, $args); }
    public function query() { $args = func_get_args(); return $this->prependCall([$this, 'queryHelper'], $args, 'assoc'); }
    public function queryAllLists() { $args = func_get_args(); return $this->prependCall([$this, 'queryHelper'], $args, 'list'); }

    public function queryRaw() { $args = func_get_args(); return $this->prependCall([$this, 'queryHelper'], $args, 'raw_buf'); }
    public function queryRawUnbuf() { $args = func_get_args(); return $this->prependCall([$this, 'queryHelper'], $args, 'raw_unbuf'); }

    protected function queryHelper() {
        $args = func_get_args();
        $type = array_shift($args);
        $db = $this->get();

        $is_buffered = true;
        $row_type = 'assoc'; // assoc, list, raw
        $full_names = false;

        switch ($type) {
            case 'assoc':
                break;
            case 'list':
                $row_type = 'list';
                break;
            case 'raw_buf':
                $row_type = 'raw';
                break;
            case 'raw_unbuf':
                $is_buffered = false;
                $row_type = 'raw';
                break;
            default:
                return DB::nonSQLError('Error -- invalid argument to queryHelper!');
        }

        $sql = call_user_func_array([$this, 'parseQueryParams'], $args);

        if (Config::$success_handler) {
            $starttime = microtime(true);
        }

        $result = $db->query($sql, $is_buffered ? MYSQLI_STORE_RESULT : MYSQLI_USE_RESULT);

        if (Config::$success_handler) {
            $runtime = microtime(true) - $starttime;
        }

        else $runtime = 0;

        // ----- BEGIN ERROR HANDLING
        if (!$sql || $db->error) {
            if (Config::$error_handler) {
                $error_handler = is_callable(Config::$error_handler) ? Config::$error_handler : __NAMESPACE__ .'\DB::debugModeHandler';

                call_user_func($error_handler, [
                    'type' => 'sql',
                    'query' => $sql,
                    'error' => $db->error,
                    'code' => $db->errno
                ]);
            }

            if (Config::$throw_exception_on_error) {
                $e = new DBException($db->error, $sql, $db->errno);
                throw $e;
            }
        } else if (Config::$success_handler) {
            $runtime = sprintf('%f', $runtime * 1000);
            $success_handler = is_callable(Config::$success_handler) ?  : __NAMESPACE__ .'\DB::debugModeHandler';

            call_user_func($success_handler, [
                'query' => $sql,
                'runtime' => $runtime,
                'affected' => $db->affected_rows
            ]);
        }

        // ----- END ERROR HANDLING

        $this->insert_id = $db->insert_id;
        $this->affected_rows = $db->affected_rows;

        // mysqli_result->num_rows won't initially show correct results for unbuffered data
        if ($is_buffered && ($result instanceof \mysqli_result)) $this->num_rows = $result->num_rows;
        else $this->num_rows = null;

        if ($row_type == 'raw' || !($result instanceof \mysqli_result)) return $result;

        $return = [];

        $infos = [];
        if ($full_names) {
            foreach ($result->fetch_fields() as $info) {
                if (strlen($info->table)) $infos[] = $info->table . '.' . $info->name;
                else $infos[] = $info->name;
            }
        }

        while ($row = ($row_type == 'assoc' ? $result->fetch_assoc() : $result->fetch_row())) {
            if ($full_names) $row = array_combine($infos, $row);
            $return[] = $row;
        }

        // free results
        $result->free();
        while ($db->more_results()) {
            $db->next_result();
            if ($result = $db->use_result()) $result->free();
        }

        return $return;
    }

    public function row() {
        $args = func_get_args();
        $result = call_user_func_array([$this, 'query'], $args);
        if (!$result || !is_array($result)) return null;
        return reset($result);
    }

    public function column() {
        $args = func_get_args();
        $results = call_user_func_array([$this, 'queryAllLists'], $args);
        $ret = [];

        if (!count($results) || !count($results[0])) return $ret;

        foreach ($results as $row) {
            $ret[] = $row[0];
        }

        return $ret;
    }

    public function field() {
        $args = func_get_args();
        $row = call_user_func_array([$this, 'row'], $args);
        if ($row == null) return null;
        return reset($row);
    }

}