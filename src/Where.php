<?php

namespace Meekro;

class Where {
    public $type = 'and'; //AND or OR
    public $negate = false;
    public $clauses = [];

    function __construct($type) {
        $type = strtolower($type);
        if ($type !== 'or' && $type !== 'and') return DB::nonSQLError('you must use either WhereClause(and) or WhereClause(or)');
        $this->type = $type;
    }

    function add() {
        $args = func_get_args();
        $sql = array_shift($args);

        if ($sql instanceof Where) {
            $this->clauses[] = $sql;
        } else {
            $this->clauses[] = ['sql' => $sql, 'args' => $args];
        }
    }

    function negateLast() {
        $i = count($this->clauses) - 1;
        if (!isset($this->clauses[$i])) return;

        if ($this->clauses[$i] instanceof Where) {
            $this->clauses[$i]->negate();
        } else {
            $this->clauses[$i]['sql'] = 'NOT (' . $this->clauses[$i]['sql'] . ')';
        }
    }

    function negate() {
        $this->negate = ! $this->negate;
    }

    function addClause($type) {
        $r = new Where($type);
        $this->add($r);
        return $r;
    }

    function count() {
        return count($this->clauses);
    }

    function textAndArgs() {
        $sql = [];
        $args = [];

        if (count($this->clauses) == 0) return ['(1)', $args];

        foreach ($this->clauses as $clause) {
            if ($clause instanceof Where) {
                list($clause_sql, $clause_args) = $clause->textAndArgs();
            } else {
                $clause_sql = $clause['sql'];
                $clause_args = $clause['args'];
            }

            $sql[] = "($clause_sql)";
            $args = array_merge($args, $clause_args);
        }

        if ($this->type == 'and') $sql = implode(' AND ', $sql);
        else $sql = implode(' OR ', $sql);

        if ($this->negate) $sql = '(NOT ' . $sql . ')';
        return [$sql, $args];
    }
    // backwards compatability
    // we now return full Where object here and evaluate it in preparseQueryParams
    function text() { return $this; }
}