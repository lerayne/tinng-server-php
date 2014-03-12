<?php
/**
 * Created by PhpStorm.
 * User: Lerayne
 * Date: 09.02.14
 * Time: 18:41
 */

// Запись в лог
/*$log = fopen('ajax_log.txt', 'w+');
function ex ($log){
	fwrite($log, 'process terminated '.connection_status()."\n");
}
register_shutdown_function("ex", $log);

function log ($text){
	global $log;
	fwrite($log, $text);
}*/


function advanced_strip($str){
	$str = str_replace('<br>', "\n", $str);
	$str = str_replace('<br />', "\n", $str);
	$str = str_replace('</p>', "\n", $str);
	$str = strip_tags($str);
	$str = html_entity_decode($str);
	$str = trim($str);

	return $str;
}

// подготовка каждой строки
function process_data($strip, $delete_email_after, $data) {

	$to_process = is_assoc($data) ? array(0 => $data) : $data;

	//$GLOBALS['debug']['$data'] = $data;
//	$GLOBALS['debug']['is_assoc'] = is_assoc($data);
//	$GLOBALS['debug']['$to_process'] = $to_process;

	foreach ($to_process as $key => $val) {

		//$GLOBALS['debug']['data'][] = $val;

		if (is_array($val)) {

			if ($val['deleted'] || $val['noaccess']) {

				$GLOBALS['debug']['deleted'][] = ($val['deleted'] == true);

				// убираем из сообщений об удаленных рядах всю лишнюю инфу
				$processed[$key] = Array (
					'deleted' => 1,
					'id' => $val['id'],
					'author' => $val['author'],
					'created' => $val['created']
				);

				continue;
			}

			// Если работаем не с отчетом об удалении

			if ($val['avatar'] == 'gravatar') {
				$val['avatar'] = 'http://www.gravatar.com/avatar/' . md5(strtolower($val['email'])) . '?s=50';
			}

			if ($val['display_name'] == null && $val['login']) $val['display_name'] = $val['login'];

			if ($delete_email_after) unset($val['email']);

			if ($strip) {
				if ($val['message']) $val['message'] = advanced_strip($val['message']);
				if ($val['lastpost']) $val['lastpost'] = advanced_strip($val['lastpost']);
			}

		}

		$processed[$key] = $val;
	}

	return is_assoc($data) ? $processed[0] : $processed;
}

// сортировка двумерного массива по указанному полю field. Работает даже с неуникальными ключами
// внимание! возвращает нумерованный массив, а не хеш-таблицу!
function sort_by_field($array, $field, $reverse = false) {

	$afs = Array(); // array for sort
	$out = Array();

	foreach ($array as $key => $val) $afs[mb_strtolower($val[$field], 'utf-8') .'.'.$key] = $val;

	ksort($afs);

	if ($reverse) $afs = array_reverse($afs);
	foreach ($afs as $key => $val) {
//		$GLOBALS['debug']['sorted'][$key] = $val['topic_name'];
		$out[] = $val;
	}
	return $out;
}


/////////////////////////////////
// Импорт и подготовка переменных
/////////////////////////////////

function extract_tag_array($string) {
	$chunks = explode('+', $string);
	$arr = array();
	if (count($chunks) && $chunks[0] != '') {
		foreach ($chunks as $val) {
			$arr[] = (int)$val;
		}
	}
	return $arr;
}

function tags_to_ids($string) {
	global $db;

	$tags = explode('+', $string);
	$arr = array();

	if (count($tags) && $tags[0] != '') {
		$arr = $db->selectCol('SELECT id FROM ?_tags WHERE name IN (?a)', $tags);
	}

	return $arr;
}

function add_tags($tags, $message_id){
	if (count($tags)) {

		global $db;

		foreach ($tags as $tag) {

			if (!is_array($tag)) {
				$tag = array(
					'name' => $tag,
					'type' => 'user'
				);
			}

			$tag_id = $db->selectCell('SELECT id FROM ?_tags WHERE name = ?', $tag['name']);

			if (!$tag_id) {

				$tag_id = $db->query('INSERT INTO ?_tags (?#) VALUES (?a)', array_keys($tag), array_values($tag));
			}

			$new_tagbind = array(
				'message' => $message_id,
				'tag' => $tag_id
			);

			$db->query('INSERT INTO ?_tagmap (?#) VALUES (?a)', array_keys($new_tagbind), array_values($new_tagbind));
		}
	}
}

function get_dialogue($dialogue) {
	global $db, $user;

	if ($user->id == 0 || $dialogue == $user->id) return null;

	$topic = $db->selectCell('
		SELECT msg.id
		FROM ?_messages msg
			JOIN ?_private_topics my_access ON my_access.message = msg.id AND my_access.level IS NOT NULL AND my_access.user = ?d
			JOIN ?_private_topics elses_access ON elses_access.message = msg.id AND elses_access.level IS NOT NULL AND elses_access.user = ?d
		WHERE msg.dialogue = 1
		GROUP BY msg.id
		'
		, $user->id
		, $dialogue
	);

	return $topic;
}


function strip_nulls($array) {

	foreach ($array as $key => $val) {

		// сначала рекурсивно вычищаем нуллы
		if (is_array($val)) $array[$key] = strip_nulls($val);
		// а потом проверяем не $val, а $array[$key] и таким образом рекурсивно избавляемся от пустых массивов
		if ($array[$key] == null || count($array[$key]) == 0) unset($array[$key]);
	}

	return $array;
}

function load_defaults($params, $defaults) {
	foreach ($defaults as $key => $def_val) {
		if (!$params[$key]) $params[$key] = $def_val;
	}

	return $params;
}