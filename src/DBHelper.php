<?php

namespace Meekro;

class DBHelper {
    /*
        verticalSlice
        1. For an array of assoc rays, return an array of values for a particular key
        2. if $keyfield is given, same as above but use that hash key as the key in new array
    */

    public static function verticalSlice($array, $field, $keyfield = null) {
        $array = (array) $array;

        $R = [];
        foreach ($array as $obj) {
            if (! array_key_exists($field, $obj)) die("verticalSlice: array doesn't have requested field\n");

            if ($keyfield) {
                if (! array_key_exists($keyfield, $obj)) die("verticalSlice: array doesn't have requested field\n");
                $R[$obj[$keyfield]] = $obj[$field];
            } else {
                $R[] = $obj[$field];
            }
        }
        return $R;
    }

    /*
        reIndex
        For an array of assoc rays, return a new array of assoc rays using a certain field for keys
    */

    public static function reIndex() {
        $fields = func_get_args();
        $array = array_shift($fields);
        $array = (array) $array;

        $R = [];
        foreach ($array as $obj) {
            $target =& $R;

            foreach ($fields as $field) {
                if (! array_key_exists($field, $obj)) die("reIndex: array doesn't have requested field\n");

                $nextkey = $obj[$field];
                $target =& $target[$nextkey];
            }
            $target = $obj;
        }
        return $R;
    }
}