<?php
/**
 * Created by IntelliJ IDEA.
 * User: Roni
 * Date: 12-08-2016
 * Time: 5:32 PM
 */

namespace Meekro;


class DBEval
{
    private $text = '';

    function __construct($text) {
        $this->text = $text;
    }

    /**
     * @return string
     */
    public function getText()
    {
        return $this->text;
    }
}