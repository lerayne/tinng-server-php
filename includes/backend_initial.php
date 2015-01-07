<?php
/* Подключаемый файл, который входит в бекенд для XHR */

$env['rootdir'] = '../';
$env['includes'] = '../';

require_once $env['rootdir'].'config.php'; //конфигурационный файл
require_once $env['rootdir'].'lib/toolkit.php'; // типа фреймворк)

require_once $env['rootdir'].'lib/Everything.php'; // типа фреймворк)
$e = new Everything();

require_once $env['rootdir'].'lib/DbSimple/Generic.php'; // либа для работы с базой

require_once $env['includes'].'classes/User.php';

// установка локали
$e->set_locale('utf-8');
date_default_timezone_set('Europe/Kiev');

$db = DbSimple_Generic::connect($safecfg['db']);

$db->query('SET NAMES "utf8"');

$db->setErrorHandler('databaseErrorHandler');
$db->setIdentPrefix($safecfg['db_prefix'].'_');

// !! todo простой логин. потом сделать более секьюрный
$raw_user = $db->selectRow(
    'SELECT * FROM ?_users
		WHERE (hash = ? OR hash = ?)
		AND (login = ? OR email = ?)
		AND approved = 1
	'
	// todo - close the hole with direct hash passing and use of md5
    , $_REQUEST['user']['pass']
    , md5($_REQUEST['user']['password'])
    , $_REQUEST['user']['login']
    , $_REQUEST['user']['login']
);

// todo заглушка аутентификации
if (!$raw_user){
	$user->id = 0;
} else $user = new User ($raw_user);

//if ($user->gravatar)
//$user->avatar = 'http://www.gravatar.com/avatar/'.md5(strtolower($user->email)).'?s=48';


function databaseErrorHandler($message, $info) {
    //if (!error_reporting()) return;
	//ob_start();
	echo "SQL Error: $message<br><br><pre>"; print_r($info); echo "</pre>";
	//$result['exceptions'][] = ob_get_contents();
	//ob_end_clean();
	exit();
}

list($xhr_id, $xhr_method) = explode('-', $_GET['JsHttpRequest']);
$sessid = $_GET['PHPSESSID'];

//require_once $env['rootdir'].'lib/JsHttpRequest.php'; // либа для аякса

if (!ini_get('zlib.output_compression')) ob_start('ob_gzhandler'); // выводим результат в gzip

//$req =& new JsHttpRequest($e->locale); //"utf-8"


function is_assoc($array) {
	return (bool)count(array_filter(array_keys($array), 'is_string'));
}
?>
