<?php
/**
 * Created by PhpStorm.
 * User: Lerayne
 * Date: 09.02.14
 * Time: 18:26
 */

// класс чтения обновлений сервера
class Feed {

	function __construct() {

	}


	///////
	// темы
	///////

	function get_topics($topics, &$meta = Array()) {
		global $cfg, $db, $user;

		// defaults
		$topics = load_defaults($topics, $topics_defaults = Array(
			'sort' => 'updated',
			'sort_direction' => 'desc',
			'filter' => ''
		));

		$meta = load_defaults($meta, $meta_defaults = Array(
			'updates_since' => '0' // работает как false, а в SQL-запросах по дате - как 0
		));

		// режим обновления?
		$update_mode = !!$meta['updates_since'];

		// есть фильтрация по тегам?
		$tag_array = tags_to_ids($topics['filter']);

		// составляем джойны для пересечений по тегам
		// поскольку id тегов приходят не от клиента, а вычисляются здесь из имен тегов, использование прямых sql-иньекций относительно безопасно
		$tags_joins = '';
		foreach ($tag_array as $i => $tag_id) {
			$tags_joins .= "/*sql*/ JOIN ?_tagmap tagmap{$i} ON tagmap{$i}.message = msg.id AND tagmap{$i}.tag = {$tag_id} \n";
		}

		// проверяем простым запросом, есть ли что на вывод вообще, прежде чем отправлять следующего "монстра"))
		// todo - удалось избавиться от подзапроса для вычисления даты последнего поста темы. Может удастся и в монстре?
		$updates_since = $db->selectCell("
			SELECT GREATEST(MAX(msg.updated), IFNULL(MAX(mupd.updated), 0))
			FROM ?_messages msg
			LEFT JOIN ?_messages mupd
				ON mupd.topic_id = msg.id
			LEFT JOIN ?_private_topics my_access FORCE INDEX FOR JOIN (pvt_message_user)
				ON my_access.message = msg.id AND my_access.user = ?d
			LEFT JOIN ?_private_topics elses_access FORCE INDEX FOR JOIN (pvt_all)
				ON elses_access.message = msg.id AND elses_access.user != ?d AND elses_access.level IS NOT NULL

			{$tags_joins}
			/*{JOIN ?_tagmap map ON map.message = msg.id AND map.tag IN(?a)}*/

			WHERE msg.topic_id = 0
				AND msg.dialogue = 0
				AND (
					(my_access.level IS NULL AND elses_access.level IS NULL) /* тема публична */
					OR (my_access.level IS NOT NULL) /* тема приватна, но у меня есть доступ */
					{OR (my_access.level IS NULL AND elses_access.level IS NOT NULL AND (my_access.updated > ? }{ OR elses_access.updated > ?))}
				)
				{AND (msg.updated > ? }{ OR mupd.updated > ?)}
				{AND msg.deleted IS NULL AND 1 = ?d}

				/* get_topics есть ли что-нибудь на вывод */
			"
			, $user->id // мой доступ
			, $user->id // чужой доступ
			//, $tag_array // при пустом массиве скип автоматический
			, ($meta['updates_since'] ? $meta['updates_since'] : DBSIMPLE_SKIP) // для доступа
			, ($meta['updates_since'] ? $meta['updates_since'] : DBSIMPLE_SKIP) // для доступа
			, ($meta['updates_since'] ? $meta['updates_since'] : DBSIMPLE_SKIP) // для всего остального
			, ($meta['updates_since'] ? $meta['updates_since'] : DBSIMPLE_SKIP) // для всего остального
			, (!$update_mode ? 1 : DBSIMPLE_SKIP) // достаем удаленные только если мы в режиме обновления
		);

		// если нет - возвращаем пустой массив и прерываем функцию
		if (!$updates_since) return Array();


		// todo - рано или поздно с этим монстром надо что-то делать. Оптимизация архитектуры бд...
		// в данный момент база оптимизирована на скорость записи. Но вообще чтение происходит чаще. Немного спасают
		// проверочные предварительные запросы, но значит ли это, что стоит оставлять этого монстра с кучей подзапросов?

		$query = "
			SELECT
				msg.id AS ARRAY_KEY,
				msg.id,
				LEFT(msg.message, ?d) AS message,
				msg.author_id,
				msg.topic_name,
				msg.created,
				msg.modified, /* todo - это уже не будет нужно, избавляемся, факт изменения отслеживаем по modifier */
				msg.updated AS maxdate, /* todo - это уже не будет нужно, избавляемся, но тут уже есть поле updated, разобраться */
				msg.modifier AS modifier_id,
				(msg.deleted IS NOT NULL OR (my_access.level IS NULL AND elses_access.level IS NOT NULL)) AS deleted,
				usr.email AS author_email,
				IFNULL(usr.display_name, usr.login) AS author,
				mlast.id AS last_id,
				LEFT(mlast.message, ?d) AS lastpost,
				mlast.updated AS lastdate,
				GREATEST(msg.updated, IFNULL(mlast.updated, 0)) as updated,
				IFNULL(lma.display_name, lma.login) AS lastauthor,
				lma.id AS lastauthor_id,
				/*(SELECT COUNT(mcount.id) FROM ?_messages mcount WHERE IF(mcount.topic_id = 0, mcount.id, mcount.topic_id) = msg.id AND mcount.deleted IS NULL) AS postsquant,*/
				IF(unr.timestamp < GREATEST(msg.updated, IFNULL(mlast.updated,0)), 1, 0) AS unread,
				(my_access.level IS NOT NULL OR elses_access.level IS NOT NULL) as private
			FROM ?_messages msg

			LEFT JOIN ?_users usr
				ON msg.author_id = usr.id
			LEFT JOIN ?_messages mupd
				ON mupd.topic_id = msg.id
				AND mupd.updated = (SELECT MAX(mmax.updated) FROM ?_messages mmax WHERE mmax.topic_id = msg.id)
			LEFT JOIN ?_messages mlast
				ON mlast.topic_id = msg.id
				AND mlast.deleted <=> NULL
				AND mlast.created =
					(SELECT MAX(mmax.created) FROM ?_messages mmax WHERE mmax.topic_id = msg.id AND mmax.deleted IS NULL)
			LEFT JOIN ?_users lma
				ON lma.id = mlast.author_id
			LEFT JOIN ?_unread unr
				ON unr.topic = msg.id
				AND unr.user = ?d
			/* доступ */
			LEFT JOIN ?_private_topics my_access FORCE INDEX FOR JOIN (pvt_message_user)
				ON my_access.message = msg.id AND my_access.user = ?d
			LEFT JOIN ?_private_topics elses_access FORCE INDEX FOR JOIN (pvt_all)
				ON elses_access.message = msg.id AND elses_access.user != ?d AND elses_access.level IS NOT NULL

			{$tags_joins}

			/*{JOIN ?_tagmap tagmap
				ON tagmap.message = msg.id
				AND tagmap.tag IN (?a)}*/

			WHERE msg.topic_id = 0
				AND msg.dialogue = 0
				AND (
					(my_access.level IS NULL AND elses_access.level IS NULL) /* тема публична */
					OR (my_access.level IS NOT NULL) /* тема приватна, но у меня есть доступ */
					{OR (my_access.level IS NULL AND elses_access.level IS NOT NULL AND (my_access.updated > ? }{ OR elses_access.updated > ?))}
				)
				{AND (msg.updated > ?}{ OR mupd.updated > ?)}
				{AND msg.deleted IS NULL AND 1 = ?d}

			GROUP BY msg.id

			/* get_topics сам вывод */
		";

		$if_updated = $meta['updates_since'] ? $meta['updates_since'] : DBSIMPLE_SKIP;

		$output_topics = process_data('strip_tags', 'delete_email', $db->select($query

			, $cfg['cut_length'], $cfg['cut_length'] // ограничение выборки первого поста
			, $user->id
			, $user->id
			, $user->id
			//, $tag_array // при пустом массиве скип автоматический
			, $if_updated // для доступа
			, $if_updated // для доступа
			, $if_updated // для всего остального
			, $if_updated // для всего остального
			, (!$update_mode ? 1 : DBSIMPLE_SKIP) // достаем удаленные только если мы в режиме обновления
		));


		// выборка тегов
		$query = "
			SELECT
				msg.id AS message,
				tag.id,
				tag.name,
				tag.type
			FROM ?_tagmap map
			JOIN ?_messages msg ON map.message = msg.id
			JOIN ?_tags tag ON tag.id = map.tag

			{JOIN ?_tagmap map2
				ON map2.message = msg.id
				AND map2.tag IN (?a)}

			WHERE ISNULL(msg.deleted) {AND msg.updated > ?}
			GROUP BY map.link_id
			ORDER BY tag.id

			/* get_topics выборка тегов */
		";

		$tags = $db->select($query

			, $tag_array
			, ($meta['updates_since'] ? $meta['updates_since'] : DBSIMPLE_SKIP)
		);

		foreach ($tags as $tag) {
			$id = $tag['message'];

			if ($output_topics[$id]) $output_topics[$id]['tags'][] = $tag;
		}

		$output_topics = sort_by_field($output_topics, $topics['sort']/*, $topics['sort_direction'] == 'desc'*/);

		// все запросы в базу идут со старой датой и только потом мы обновляем ее
		$meta['updates_since'] = $updates_since;

		// возвращаем полученный массив тем
		return $output_topics;
	}








	////////
	// posts
	////////

	function get_posts($posts, &$meta = Array()) {
		global $cfg, $db, $user;

		// defaults
		$posts = load_defaults($posts, $posts_defaults = Array(
			'limit' => 0, // Ограничение выборки последними n сообщениями. 0 - грузить все
			'show_post' => 0 // При указани на конкретный пост происходит проверка не вне лимита ли он и если да - лимит сдвигается до него
		));

		$meta = load_defaults($meta, $meta_defaults = Array(
			'updates_since' => '0', // работает как false, а в SQL-запросах по дате - как 0
			'slice_start' => '0' // работает как false, а в SQL-запросах по дате - как 0
		));

		// извлечение номера темы из диалога
		if ($posts['dialogue']) {
			$posts['topic'] = get_dialogue($posts['dialogue']);

			if (!$posts['topic']) return Array(); // сбрасываем, если нет такой темы

		}

		// существует ли тема (в том числе не удалена ли и не закрыта ли от читателя)
		$topic_exists = $db->selectCell('
			SELECT msg.id
			FROM ?_messages msg
			LEFT JOIN ?_private_topics my_access
				ON msg.id = my_access.message AND my_access.user = ?d
			LEFT JOIN ?_private_topics elses_access
				ON msg.id = elses_access.message AND elses_access.user != ?d AND elses_access.level IS NOT NULL
			WHERE id = ?d
				AND msg.deleted IS NULL
				AND ((my_access.level IS NULL AND elses_access.level IS NULL) OR my_access.level IS NOT NULL)

				/* get_posts доступна ли тема */
			'
			, $user->id // джойн доступа
			, $user->id // джойн доступа
			, $posts['topic']
		);

		// если заглавного сообщения не существует, или оно было удалено, или нет доступа
		if (!$topic_exists) return Array();


		// если загружаем тему с нуля и есть авторизованный юзер
		if (!$meta['updates_since'] && $user->id != 0) {

			// проверяем, когда пользователь отмечал тему прочитанной
			$date_read = $db->selectCell(
				'SELECT timestamp FROM ?_unread WHERE user = ?d AND topic = ?d /* get_posts когда юзер читал тему? */'
				, $user->id
				, $posts['topic']
			);

			// ой, ни разу! Установить ее прочитанной в этот момент!
			if (!$date_read) {

				// при первом прочтении отмечать прочитанным только первое сообщение
				// todo - попробовал заменить updated на created, иначе возможен случай, когда несколько сообщений отметятся
				// прочитанными из-за того, что первое сообщение было отредактирован опозже
				$first_post_date = $db->selectCell('SELECT created FROM ?_messages WHERE id = ?d /* get_posts достаем дату первого сообщения */', $posts['topic']);

				$values = Array('user' => $user->id, 'topic' => $posts['topic'], 'timestamp' => $first_post_date);
				$db->query('INSERT INTO ?_unread (?#) VALUES (?a) /* get_posts отмечаем первое сообщение прочитанным */', array_keys($values), array_values($values));

				$posts['show_post'] = $posts['topic']; // установить указатель на первое сообщение
				$posts['limit'] = 0; // загрузить все сообщения

				// уже читали
			} else {

				// определить первое непрочитанное сообщение (не учитывать мои и отредактированные мной)
				$first_unread = $db->selectCell('
					SELECT id FROM ?_messages
					WHERE updated > ? AND topic_id = ?d AND deleted IS NULL AND IFNULL(modifier, author_id) != ?d
					ORDER BY created ASC
					LIMIT 1
					/* get_posts определяем первое непрочитанное сообщение */
					'
					, $date_read
					, $posts['topic']
					, $user->id
				);

				// если такие есть - установить его как то, до которого нужно прокрутить
				if ($first_unread) {
					$posts['show_post'] = $first_unread;
				}
			}
		}


		// секция установки слайсов ////////////////////////////////////////////////////////////////////////////////////

		/*
		todo - ВНИМАНИЕ! Текущая система слайсов базируется на 2 параметрах - лимите и хранимой в мета-секции дате начала
		слайса. Для расширения слайса на клиенте подсчитывается кол-во загруженных в данный момент постов, к ним прибавляется
		число N и производится мягкая (без сброса меты) переподписка. Этот принцип сильно полагается на то, что в теме не
		появится N и более новых сообщений с момента отправки запроса на подгрузку. Для шорт-полла это особенно критично,
		т.к. новые сообщения приходят с фиксированной задержкой. Грубо говоря - если в теме за 5 секунд задержки появится более
		N новых сообщений - может произойти непредвиденный сбой - система посчитает что подгрузка не произошла. Внешне это может
		выглядеть как не срабатывание кнопки "подгрузить еще". Впрочем, должно быть достаточно просто нажать ее еще раз.
		*/

		// используем для того, чтобы отсечь ненужные условия в запросе
		if ($posts['limit']) {

			// всего постов в теме
			$postcount = $db->selectCell('
				SELECT COUNT(id)
				FROM ?_messages
				WHERE (topic_id = ?d OR id = ?d)
				AND deleted IS NULL
				/* get_posts считаем кол-во сообщений в теме */
				'
				, $posts['topic']
				, $posts['topic']
			);

			// если кол-во сообщений в теме меньше, чем ограничение - сбросить ограничение
			if ($postcount <= $posts['limit']) $posts['limit'] = 0;
		}


		// если нет ограничения по постам
		if (!$posts['limit']) {

			// устанавливаем дату "от" на 0
			$slice_start = '0';

			// если это догрузка - устанавливаем дату "до"
			if ($meta['slice_start']) $slice_end = $meta['slice_start'];

		} else {
			//если есть ограничение

			// мета есть, в выборке по лимиту есть более ранние сообщения, чем мета - возвращаем новую дату
			// меты нет - возвращаем новую дату по лимиту
			// мета есть и она раньше, чем новая выборка по лимиту - возвращаем мету

			$prev_sstart = $meta['slice_start'] ? $meta['slice_start'] : DBSIMPLE_SKIP;

			$slice_start = $db->selectCell('
				SELECT
					{IF(created < ?, created, }{ ?) AS} created
				FROM ?_messages
				WHERE IF(topic_id=0, id, topic_id) = ?d AND deleted IS NULL
				ORDER BY created DESC
				LIMIT 1 OFFSET ?d
				/* get_posts определяем начало слайса */
				'
				, $prev_sstart, $prev_sstart
				, $posts['topic']
				, ((int) $posts['limit'])-1
			);

			unset($prev_sstart);

			// если передан номер конкретного поста - проверяем, не выходит ли он за слайс-"от" и если да - возвращаем новый слайс-"от"
			// это только для передачи номера поста по параметру из адрессной строки, поэтому в догрузке не используется
			if ($posts['show_post']) {
				$post_start = $db->selectCell('
					SELECT IF(created < ?, created, ?)
					FROM ?_messages
					WHERE id = ?d AND deleted IS NULL
					/* get_posts не выходит ли переданный по сылке пост за пределы слайса */
					'
					, $slice_start, $slice_start
					, $posts['show_post']
				);

				if ($post_start) $slice_start = $post_start;
			}

			// если в мете слайс-от уже был и он не равен новому - устанавливаем слайс-до в значение из меты
			if ($meta['slice_start'] && $slice_start != $meta['slice_start']) $slice_end = $meta['slice_start'];
		}

		////////////////////////////////////////////////////////////////////////////////////////////////////////////////

		// смотрим, есть ли сообщение с датой позже чем $meta['updates_since'] и $slice_start
		// issue - если первая выборка даты не выдает удаленных, возможна ситуация когда последний удаленный пост приходит
		// с ближайшим апдейтом. с этой целью проверка на неудаленность была отключена. todo - проверить все ли ок.
		$new_updates_since = $db->selectCell('
			SELECT MAX(msg.updated)
			FROM ?_messages msg
			WHERE (IF(msg.topic_id = 0, msg.id, msg.topic_id) = ?d {OR (msg.topic_id != ?d }{AND msg.moved_from = ?d )} ) /* topic_id */
				/*{AND msg.deleted IS NULL AND 1 = ?d}*/
				{AND msg.created >= ?} /* $slice_start */
				{AND msg.updated > ?} /* $meta["updates_since"] */

				/* get_posts смотрим, есть ли что новое на выход */
			'
			, $posts['topic']
			, ($meta['updates_since'] ? $posts['topic'] : DBSIMPLE_SKIP)
			, ($meta['updates_since'] ? $posts['topic'] : DBSIMPLE_SKIP)
			//, (!$meta['updates_since'] ? 1 : DBSIMPLE_SKIP)
			, ($slice_start ? $slice_start : DBSIMPLE_SKIP)
			, ($meta['updates_since'] ? $meta['updates_since'] : DBSIMPLE_SKIP)
		);
		// todo - некритично, но при данной системе перемещения тем в тему, из которой когда-то что-то переместили приходят
		// удаленными прежде перенесенные сообщения в двух случаях: 1) в этих сообщениях что-то изменили 2) в теме,
		// откуда переносили со времени переноса не было ни одного изменения (тогда перенесенные приходят удаленными при первом
		// апдейте после загрузки)


		// если мы не в режиме догрузки и нет новых/обновленных - возвращаем пустой массив.
		// todo - возможно нужно что-то сделать со $slice_satrt
		if (!$slice_end && !$new_updates_since) return Array();

		////////////////////////////////////////////////////////////////////////////////////////////////////////////////

		// наконец, запрашиваем посты!
		$query = '
			SELECT
				msg.id AS ARRAY_KEY,
				msg.id,
				msg.message,
				msg.author_id,
				msg.topic_id,
				msg.topic_name,
				msg.created,
				msg.modified,
				(msg.deleted IS NOT NULL OR (msg.topic_id != ?d AND msg.moved_from = ?d)) AS deleted,
				msg.modifier,
				tpc.dialogue,
				tpc.author_id AS topicstarter,
				usr.email AS email,
				UNIX_TIMESTAMP(usr.last_read) AS author_seen_online,
				IFNULL(usr.display_name, usr.login) AS author,
				IF(unr.timestamp < msg.updated && IFNULL(msg.modifier, msg.author_id) != ?d, 1, 0) AS unread,
				moder.login AS modifier_name,
				avatar.param_value as avatar
			FROM ?_messages msg

			JOIN ?_users usr
				ON msg.author_id = usr.id
			LEFT JOIN ?_messages tpc
				ON tpc.id = IF(msg.topic_id = 0, msg.id, msg.topic_id)
			LEFT JOIN ?_unread unr
				ON unr.topic = IF(msg.topic_id = 0, msg.id, msg.topic_id) AND unr.user = ?d
			LEFT JOIN ?_users moder
				ON msg.modifier = moder.id
			LEFT JOIN ?_user_settings avatar
				ON msg.author_id = avatar.user_id AND avatar.param_key = "avatar"

			WHERE
				( (msg.id = ?d OR msg.topic_id = ?d) {OR (msg.topic_id != ?d }{AND msg.moved_from = ?d)} )
				{AND msg.deleted IS NULL AND 1 = ?d}
		';

		$query_end = '/*sql*/ GROUP BY msg.id ORDER BY msg.created ASC /* get_posts загружаем посты */';

		// если режим догрузки
		if ($slice_end) {

			$query .= '/*sql*/
				AND msg.created >= ?
				AND ((msg.created < ? AND msg.deleted IS NULL) OR msg.updated > ?)
			';

			$output_posts = process_data(0, 'delete_email', $db->select($query . $query_end
				, $posts['topic']
				, $posts['topic']
				, $user->id
				, $user->id
				, $posts['topic']
				, $posts['topic']
				, ($meta['updates_since'] ? $posts['topic'] : DBSIMPLE_SKIP)
				, ($meta['updates_since'] ? $posts['topic'] : DBSIMPLE_SKIP)
				, (!$meta['updates_since'] ? 1 : DBSIMPLE_SKIP)

				, $slice_start
				, $slice_end
				, $meta['updates_since']
			));

		} else {

			$query .= '/*sql*/
				{AND msg.created >= ?} /* $slice_start */
				{AND msg.updated > ?} /* $meta["updates_since"] */
			';

			$output_posts = process_data(0, 'delete_email', $db->select($query . $query_end
				, $posts['topic']
				, $posts['topic']
				, $user->id
				, $user->id
				, $posts['topic']
				, $posts['topic']
				, ($meta['updates_since'] ? $posts['topic'] : DBSIMPLE_SKIP)
				, ($meta['updates_since'] ? $posts['topic'] : DBSIMPLE_SKIP)
				, (!$meta['updates_since'] ? 1 : DBSIMPLE_SKIP)

				, ($slice_start ? $slice_start : DBSIMPLE_SKIP)
				, ($meta['updates_since'] ? $meta['updates_since'] : DBSIMPLE_SKIP)
			));
		}
		// да, можно было не ставить условие и объединить два варианта подачи запроса в один, но в таком случае либо
		// условия по плейсхолдерам слишком сложные, либо БД всегда сравнивает даты, даже если они равны 0

		// вставляем в сам пост указание. Сейчас не используется для ссылки и выделения, используется только для
		// прокрутки до первого поста, если тема читается впервые
		if ($posts['show_post'] && $output_posts[$posts['show_post']]) {
			$output_posts[$posts['show_post']]['refered'] = 1;
		}

		// показываем теги заглавного сообщения
		if ($output_posts[$posts['topic']]) {

			$output_posts[$posts['topic']]['head'] = true;

			// в диалоге нам теги не нужны
			if ($output_posts[$posts['topic']]['dialogue'] == 0) {
				$tags = $db->select('
				SELECT
					tag.id,
					tag.name,
					tag.type
				FROM ?_tagmap map
				LEFT JOIN ?_tags tag ON map.tag = tag.id
				WHERE map.message = ?d

				/* get_posts загружаем теги первого поста */
				'
					, $posts['topic']
				);

				$output_posts[$posts['topic']]['tags'] = $tags;
			}
		}


		$meta['slice_start'] = $slice_start;
		if ($new_updates_since) $meta['updates_since'] = $new_updates_since;

		return $output_posts;

	}

	///////////////
	// single topic
	///////////////

	function get_topic($topic, &$meta = Array()) {
		global $db, $user;

		// defaults
		$topic = load_defaults($topic, $topic_defaults = Array(
			'id' => 0,
		));

		$meta = load_defaults($meta, $meta_defaults = Array(
			'updated_at' => '0', // определяем
		));

		if ($topic['dialogue'] && $topic['dialogue'] != $user->id) {
			$topic['id'] = get_dialogue($topic['dialogue']);

			// если такого диалога еще нет
			if (!$topic['id']) {

				// делаем всё это только один раз
				if (!$meta['updated_at']) {
					$dialogue_user = $db->selectRow("
					SELECT
						usr.id,
						usr.login,
						usr.email,
						usr.display_name,
						avatar.param_value AS avatar
					FROM ?_users usr
						LEFT JOIN ?_user_settings avatar ON avatar.user_id = usr.id AND avatar.param_key = 'avatar'
					WHERE usr.id = ?d
					"
						, $topic['dialogue']
					);

					if (!$dialogue_user) return array();

					$dialogue_user = process_data(0, 'delete_email', $dialogue_user);

					$topic_props = array(
						'dialogue' => 1,
						'new_dialogue' => 1,
						'private' => array(0 => $dialogue_user)
					);

					// запоминаем
					$meta['updated_at'] = now('sql');

					return $topic_props;
				} else {
					// если уже сделали - говорим "апдейтов нет"
					return array();
				}
			}
		}

		$topic_exists = $db->selectCell('
			SELECT msg.id
			FROM ?_messages msg
			WHERE id = ?d
				AND msg.deleted IS NULL
			'
			, $topic['id']
		);

		// если заглавного сообщения не существует, или оно было удалено
		if (!$topic_exists) return Array('deleted' => 1);

		// изменялась ли "голова" темы?
		$new_updated_at = $db->selectCell('
			SELECT msg.updated
			FROM ?_messages msg
			WHERE msg.id = ?d AND msg.topic_id = 0
			{AND msg.updated > ?}
			'
			, $topic['id']
			, ($meta['updated_at'] ? $meta['updated_at'] : DBSIMPLE_SKIP)
		);

		if ($new_updated_at) {

			$topic_props = $db->selectRow('
			SELECT
				msg.id,
				msg.message,
				msg.author_id,
				msg.topic_id,
				msg.topic_name,
				msg.created,
				msg.modified,
				msg.modifier,
				msg.dialogue,
				(msg.deleted IS NOT NULL OR (my_access.level IS NULL AND elses_access.level IS NOT NULL)) AS deleted,
				(SELECT COUNT(id) FROM ?_messages msgq WHERE IF(msgq.topic_id = 0, msgq.id, msgq.topic_id) = ?d AND deleted IS NULL) AS post_count,
				(my_access.level IS NOT NULL OR elses_access.level IS NOT NULL) as private

			FROM ?_messages msg
			LEFT JOIN ?_private_topics my_access
				ON msg.id = my_access.message AND my_access.user = ?d
			LEFT JOIN ?_private_topics elses_access
				ON msg.id = elses_access.message AND elses_access.user != ?d AND elses_access.level IS NOT NULL

			WHERE msg.id = ?d
				AND msg.topic_id = 0
				AND (
					(my_access.level IS NULL AND elses_access.level IS NULL) /* тема публична */
					OR (my_access.level IS NOT NULL) /* тема приватна, но у меня есть доступ */
					{OR (my_access.level IS NULL AND elses_access.level IS NOT NULL AND (my_access.updated > ? }{ OR elses_access.updated > ?))}
				)

			GROUP BY msg.id
			'
				, $topic['id']
				, $user->id // джойн доступа
				, $user->id // джойн доступа
				, $topic['id']
				, ($meta['updated_at'] ? $meta['updated_at'] : DBSIMPLE_SKIP) // принимать условия только в режиме обновления
				, ($meta['updated_at'] ? $meta['updated_at'] : DBSIMPLE_SKIP) // принимать условия только в режиме обновления
			);

			if ($topic_props['private']) {
				$allowed_users = $db->select("
					SELECT
						usr.id AS id,
						usr.login,
						usr.email,
						usr.display_name,
						avatar.param_value AS avatar
					FROM ?_private_topics priv
						JOIN ?_users usr ON priv.user = usr.id
						LEFT JOIN ?_user_settings avatar ON avatar.user_id = usr.id AND avatar.param_key = 'avatar'
					WHERE priv.message = ?d
						AND priv.level IS NOT NULL
					GROUP BY priv.link_id
					ORDER BY priv.updated, priv.link_id
					"
					, $topic['id']
				);

				$allowed_users = process_data(0, 'delete_email', $allowed_users);

				$topic_props['private'] = $allowed_users;
			}

			$meta['updated_at'] = $new_updated_at;

		} else {

			$topic_props = Array();
		}

		return $topic_props;
	}







	///////////////
	// users data
	///////////////

	function get_users($users, &$meta = Array()) {
		global $db, $cfg;

		//$GLOBALS['debug']['params'] = $users;

		$meta = load_defaults($meta, $meta_defaults = Array(
			'latest_user' => '0' // работает как false, а в SQL-запросах по дате - как 0
		));

		// есть ли юзеры, зареганные позже опорной даты?
		$users_to_return = $db->selectCell("
			SELECT COUNT(id) FROM ?_users WHERE approved = 1
			{AND reg_date > ?}
			"
			, ($meta['latest_user'] ? $meta['latest_user'] : DBSIMPLE_SKIP)
		);

		// если нам нужна фильтрация по какому-либо признаку
		switch ($users['fielter']){
			case 'online':
				$online_only = true;
				break;
		}

		// если нет - возвращаем пустышку (но если нам нужны уведомления об онлайн-статусе - всегда возвращаем всё)
		if ($users_to_return*1 <= 0 && !$online_only) return Array();



		// дата последнего зареганного юзера
		$latest_user = $db->selectCell('SELECT MAX(reg_date) FROM ?_users WHERE approved = 1');

		// по умолчанию - выбираем древовидный массив
		$method = 'select';

		// текущая дата минус период подразумеваемой активности
		$threshold_away = date('Y-m-d H:i:s', now() - $cfg['online_threshold']);

		// если нам нужен четкий список юзеров
		if ($users['ids']) {
			$ids = explode(',', $users['ids']);
		}

		// фильтрация по полям:

		// сборка полей
		$fields_map = Array(
			'id'			=> 'usr.id',
			'login'			=> 'usr.login',
			'display_name'	=> 'usr.display_name',
			'email'			=> 'usr.email',
			'reg_date'		=> 'usr.reg_date',
			'approved'		=> 'usr.approved',
			'source'		=> 'usr.source',
			'last_read'		=> 'usr.last_read',
			'last_read_ts'	=> 'UNIX_TIMESTAMP(usr.last_read)',
			'status'		=> 'usr.status',
			'avatar'		=> 'avatar.param_value'
		);

		// если заказывали аватар, но не заказывали имейл - запросить имейл, но потом стереть
		if (in_array('avatar', $users['fields']) && !in_array('email', $users['fields'])) {
			$users['fields'][] = 'email';
			$delete_email_after = true;
		}

		$sql_fields = Array();

		foreach ($fields_map as $key => $val) {
			if (!$users['fields'] || in_array($key, $users['fields'])) $sql_fields[$key] = $val.' AS '.$key;
		}

		$fields_to_select = join(",\n", $sql_fields);

		// сборка джойнов
		$joins_map = Array(
			'avatar' => "/*SQL*/ LEFT JOIN ?_user_settings avatar ON usr.id = avatar.user_id AND param_key = 'avatar'"
		);

		$sql_joins = Array();

		foreach ($joins_map as $key => $val) {
			if (!$users['fields'] || in_array($key, $users['fields'])) $sql_joins[$key] = $val;
		}

		$joins_to_select = join("\n", $sql_joins);

		if (count($users['fields']) == 1) $method = 'selectCol';

		$userlist = $db->$method("
			SELECT
				{$fields_to_select}
			FROM ?_users usr
			{$joins_to_select}
			WHERE approved = 1
				{AND id IN (?a)}
				{AND last_read > ? AND status != 'offline'}
			"
			, ($ids ? $ids : DBSIMPLE_SKIP)
			, ($online_only ? $threshold_away : DBSIMPLE_SKIP)
		);

		//$GLOBALS['debug']['$userlist'] = $userlist;

		$userlist = process_data(0, $delete_email_after, $userlist);

		// sorting
		if (in_array('display_name', $users['fields'])) $userlist = sort_by_field($userlist, 'display_name');

		$meta['latest_user'] = $latest_user;

		return $userlist;
	}


	///////////////
	// tags
	///////////////

	function get_tags ($tags, &$meta = Array()) {
		global $db;

		$meta = load_defaults($meta, $meta_defaults = Array(
			'updated' => '0' // работает как false, а в SQL-запросах по дате - как 0
		));

		$latest_tag = $db->selectCell("
			SELECT MAX(updated) FROM ?_tags WHERE updated > ?
			",$meta['updated']
		);

		if (!$latest_tag) return Array();



		$tags_list = $db->select("
			SELECT
				tag.id,
				tag.name,
				tag.type,
				IF(map.message IS NOT NULL, true, false) AS used
			FROM ?_tags tag
			LEFT JOIN ?_tagmap map ON map.tag = tag.id
			LEFT JOIN ?_messages msg ON map.message = msg.id AND msg.deleted IS NULL
			WHERE tag.updated > ?
			GROUP BY tag.id
			",$meta['updated']
		);

		$meta['updated'] = $latest_tag;

		$tags_list = sort_by_field($tags_list, 'name');

		return $tags_list;
	}



	///////////////
	// dialogues
	///////////////

	function get_dialogues ($dialogues, &$meta = Array()){
		global $db, $user, $cfg;

		$dialogues = load_defaults($dialogues, $dialogues_defaults = Array(
			'method' => 'full'
		));

		$meta = load_defaults($meta, $meta_defaults = Array(
			'read' => '0', // работает как false, а в SQL-запросах по дате - как 0
		));

//		$GLOBALS['debug']['dialogue meta read'] = $meta['read'];


		//определяем есть ли вообще непрочитанные диалоги
		$unread = $db->selectCell('
			SELECT MAX(msg.created) AS latestchange
			FROM ?_messages msg
				JOIN ?_messages head ON msg.id = head.id OR msg.topic_id = head.id
				JOIN ?_private_topics my_access ON my_access.message = head.id AND my_access.level IS NOT NULL AND my_access.user = ?d
				LEFT JOIN ?_unread unr ON unr.topic = head.id AND unr.user = ?d
			WHERE head.dialogue = 1
				AND msg.author_id != ?d
				AND msg.deleted IS NULL
				AND msg.created > IFNULL(unr.timestamp, 0)
				{AND msg.created > ?}
			'
			, $user->id
			, $user->id
			, $user->id
			, ($meta['read'] ? $meta['read'] : DBSIMPLE_SKIP)
		);

		if (!$unread) return Array();

		switch ($dialogues['method']){

			case 'updates':
				break;

			case 'updates_extended':

				$result = $db->select("
					SELECT
						msg.id,
						head.id AS topic,
						msg.author_id AS sender,
						usr.login,
						usr.display_name,
						usr.email,
						avatar.param_value AS avatar,
						UNIX_TIMESTAMP(msg.created)*1000 AS created,
						LEFT(msg.message, 80) AS message
					FROM ?_messages msg
						JOIN ?_messages head ON msg.id = head.id OR msg.topic_id = head.id
						JOIN ?_users usr ON msg.author_id = usr.id
						JOIN ?_private_topics my_access ON my_access.message = head.id AND my_access.level IS NOT NULL AND my_access.user = ?d
						/*JOIN ?_private_topics elses_access ON elses_access.message = head.id AND elses_access.level IS NOT NULL AND elses_access.user != ?d*/
						LEFT JOIN ?_unread unr ON unr.topic = head.id AND unr.user = ?d
						LEFT JOIN ?_user_settings avatar ON avatar.user_id = msg.author_id AND avatar.param_key = 'avatar'
					WHERE head.dialogue = 1
						AND msg.author_id != ?d
						AND msg.deleted IS NULL
						AND msg.created > IFNULL(unr.timestamp, 0)
						{AND msg.created > ?}
					GROUP BY msg.id
					"
					, $user->id
					, $user->id
					, $user->id
					, ($meta['read'] ? $meta['read'] : DBSIMPLE_SKIP)
				);

				$result = process_data('strip_tags', 'delete_email', $result);

				break;

			case 'full':
				break;

		}

		$meta['read'] = $unread;

		return $result;
	}
}