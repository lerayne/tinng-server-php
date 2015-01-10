<?php
/**
 * Created by PhpStorm.
 * User: Lerayne
 * Date: 10.01.2015
 * Time: 23:01
 */

header('Access-Control-Allow-Origin:*'); // отдавать результат любому домену

if (count($GLOBALS['debug'])) $result['debug'] = $GLOBALS['debug'];

$php_message = ob_get_contents();
ob_clean();

if (sizeof($php_message)) {
	$result['php_message'] = $php_message;
}

if (!ini_get('zlib.output_compression')) ob_start('ob_gzhandler'); // выводим результат в gzip

echo json_encode($result);