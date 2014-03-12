<?php
//sleep(1); // в целях отладки

require_once '../includes/backend_initial2.php';
require_once '../includes/funcs.php';
require_once '../classes/Feed.php';

$result['xhr'] = $xhr_id;
//$result['sessid'] = $sessid;

$GLOBALS['debug'] = Array();

///////////////////////
// разбираем что пришло
///////////////////////

function parse_request($request) {
	global $user, $db;

	$result = array();

	// пишем время активности юзера
	if ($user->id > 0) {

		$data = array(
			'last_read' => now('sql'),
			'status' => 'online'
		);

		$db->query('UPDATE ?_users SET ?a WHERE id = ?d', $data, $user->id);
	}

	///////////////////////////////
	// Записываем в базу обновления
	///////////////////////////////

	if ($request['write']) {

		$writes = $request['write'];
		$now = now('sql');

		foreach ($writes as $write_index => $write) {
			switch ($write['action']) {

				// добавляем новую тему (тут нет брейка, так и надо)
				case 'add_topic':

					$new_row['topic_name'] = $write['title'];
					if ($write['dialogue']) $new_row['dialogue'] = 1;

				// вставляем новое сообщение (адаптировать для старта темы!)
				case 'add_post':



					if (!$write['topic']) $write['topic'] = 0;

					$new_row['author_id'] = $user->id;
					//$new_row['parent_id'] = $write['parent'] ? $write['parent'] : $write['topic'];
					$new_row['topic_id'] = $write['topic'];
					$new_row['message'] = $write['message'];
					$new_row['created'] = $now;
					$new_row['updated'] = $now;

					$new_node_id = $db->query('INSERT INTO ?_messages (?#) VALUES (?a)', array_keys($new_row), array_values($new_row));

					// отмечаем прочитанным
					$db->query(
						'UPDATE ?_unread SET timestamp = ? WHERE user = ?d AND topic = ?d'
						, $now
						, $user->id
						, ($write['action'] == 'add_topic' ? $new_node_id : $write['topic'])
					);

				// добавляем новую тему (тут нет брейка, так и надо)
				case 'add_topic':

					if ($write['dialogue']) {
						$db->query('
							INSERT INTO ?_private_topics (message, user, level, updated)
							VALUES (?d, ?d, 1, ?), (?d, ?d, 1, ?)
							'
							, $new_node_id, $user->id, $now
							, $new_node_id, $write['dialogue'], $now
						);
					}

					$result['actions'][$write_index] = Array('action' => $write['action'],'id' => $new_node_id);

					add_tags($write['tags'], $new_node_id);

					break;


				// обновляет запись в ?_messages
				case 'update_message':
					unset ($write['action']);

					$upd_id = $write['id'];
					$new_tags = $write['tags'];
					unset($write['id'], $write['tags']);

					if ($new_tags) {
						// занимаемся тегами
						$current_tags = $db->selectCol('
						SELECT tag.id AS ARRAY_KEY, tag.name FROM ?_tagmap map
						LEFT JOIN ?_tags tag ON map.tag = tag.id
						WHERE map.message = ?d
						'
							, $upd_id
						);

						$tags_to_add = array();
						$tags_to_remove = array();

						foreach ($new_tags as $tag) if (!in_array($tag, $current_tags)) $tags_to_add[] = $tag;
						foreach ($current_tags as $id => $tag) if (!in_array($tag, $new_tags)) $tags_to_remove[$id] = $tag;

						if (count($tags_to_remove)) {
							$db->query('DELETE FROM ?_tagmap WHERE message = ?d AND tag IN (?a)', $upd_id, array_keys($tags_to_remove));
						}

						if (count($tags_to_add)) {
							add_tags($tags_to_add, $upd_id);
						}
					}

					/*$GLOBALS['debug']['$new_tags'] = $new_tags;
					$GLOBALS['debug']['$current_tags'] = $current_tags;
					$GLOBALS['debug']['$tags_to_add'] = $tags_to_add;
					$GLOBALS['debug']['$tags_to_remove'] = array_keys($tags_to_remove);*/

					$write['modified'] = $now; // при любом обновлении пишем дату,
					$write['updated'] = $now; // при любом обновлении пишем дату,
					$write['modifier'] = $user->id; // пользователя, отредактировавшего сообщение
					$write['locked'] = null; // и убираем блокировку

					$db->query('UPDATE ?_messages SET ?a WHERE id = ?d', $write, $upd_id);

					break;


				// удаляем сообщение
				case 'delete_message':
					unset ($write['action']);

					// проверка блокировки сообщения
					$locked = $db->selectCell('SELECT locked FROM ?_messages WHERE id = ?d', $write['id']);

					if ($locked) {

						$result['error'] = 'post_locked';

					} else {

						$upd_id = $write['id'];
						unset($write['id']);
						$write['deleted'] = 1;
						$write['modifier'] = $user->id;
						$write['modified'] = $now;
						$write['updated'] = $now;

						$db->query('UPDATE ?_messages SET ?a WHERE id = ?d', $write, $upd_id);
					}

					break;

				// убираем тег с темы
				case 'tag_remove':

					$msgupd['modified'] = $now;
					$msgupd['updated'] = $now;
					$msgupd['modifier'] = $user->id;

					$db->query('UPDATE ?_messages SET ?a WHERE id = ?d', $msgupd, $write['msg']);

					$db->query('DELETE FROM ?_tagmap WHERE message = ?d AND tag = ?d', $write['msg'], $write['tag']);

					break;
			}
		}
	}


	/////////////////////////////
	// выдаем данные по подпискам
	/////////////////////////////

	if ($request['subscribe']) {

		$subscribers = $request['subscribe'];

		if ($request['meta']) $meta = $request['meta'];

		// есть ли подписчики и хоть что-то обновленное на сервере?
		if (count($subscribers)) {

			$feed = new Feed();

			foreach ($subscribers as $subscriberId => $subscriptions) {

				foreach ($subscriptions as $feedName => $params) {

					// вызываем метод get_[имя фида]
					$method_name = 'get_' . $params['feed'];

					// тут в конце страшная магия - передача параметра в функцию по ссылке
					$feed_data =  $feed->$method_name($params, $meta[$subscriberId][$feedName]);
					if (count($feed_data)) $result['feeds'][$subscriberId][$feedName] = $feed_data;
				}
			}
		}

		// и вот тут мы записываем мету (параллельно очищая ее от нуллов и пустых массивов)
		$result['meta'] = strip_nulls($meta);
	}

	return $result;
}

$GLOBALS['_RESULT'] = parse_request($_REQUEST);

if (count($GLOBALS['debug'])) print_r($GLOBALS['debug']);
?>
