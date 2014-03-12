<?php

function jsts2phpts ($str){
    return substr($str, 0, strlen($str)-3);
}

function jsts2sql($str){
    return date('Y-m-d H:i:s', jsts2phpts($str));
}

function ts2sql($ts) {
	return date('Y-m-d H:i:s', $ts);
}

function now($format = false){
    return ($format == 'sql') ? date('Y-m-d H:i:s') : time();
}