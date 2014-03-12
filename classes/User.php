<?php

class User {

    function __construct($row) {

        foreach ($row as $key => $val):
            $valname = str_replace('uset_', '', $key);
            $this->$valname = $val;
        endforeach;
    }
}
