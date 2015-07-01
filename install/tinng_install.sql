SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;


CREATE TABLE `tinng_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `author_id` int(10) unsigned NOT NULL,
  `topic_id` bigint(20) unsigned DEFAULT NULL,
  `moved_from` bigint(20) unsigned NOT NULL DEFAULT '0',
  `topic_name` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `created` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `modified` timestamp NULL DEFAULT NULL,
  `updated` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `modifier` int(10) unsigned DEFAULT NULL,
  `deleted` tinyint(1) unsigned DEFAULT NULL,
  `locked` tinyint(1) unsigned DEFAULT NULL,
  `dialogue` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Аналог ЛС',
  PRIMARY KEY (`id`),
  KEY `msg_author` (`author_id`),
  KEY `msg_topic_id` (`topic_id`),
  KEY `msg_deleted` (`deleted`),
  KEY `msg_dialogue` (`dialogue`),
  KEY `msg_created` (`created`),
  KEY `msg_updated` (`updated`),
  KEY `msg_moved_from` (`moved_from`),
  KEY `M_msg_sel_topics` (`topic_id`,`dialogue`,`deleted`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `tinng_private_topics` (
  `link_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `updated` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `message` int(10) unsigned NOT NULL,
  `user` int(10) unsigned NOT NULL,
  `level` tinyint(1) DEFAULT NULL COMMENT 'здесь NULL критичен, ибо JOIN',
  PRIMARY KEY (`link_id`),
  KEY `pvt_message` (`message`),
  KEY `pvt_level` (`level`),
  KEY `pvt_user` (`user`),
  KEY `pvt_message_user` (`message`,`user`),
  KEY `pvt_all` (`message`,`user`,`level`),
  KEY `pvt_updated` (`updated`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `tinng_tagmap` (
  `link_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `message` bigint(20) unsigned NOT NULL,
  `tag` int(10) unsigned NOT NULL,
  PRIMARY KEY (`link_id`),
  KEY `tagmap_tag` (`tag`),
  KEY `tagmap_message_tag` (`message`,`tag`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `tinng_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(20) NOT NULL,
  `type` varchar(10) NOT NULL,
  `strict` int(10) unsigned DEFAULT NULL COMMENT 'привязка к сообществу для внутренних тегов',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `tinng_unread` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user` int(10) unsigned NOT NULL,
  `topic` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unr_topic_user` (`topic`,`user`),
  KEY `unr_topic` (`topic`),
  KEY `unr_user` (`user`),
  KEY `unr_date` (`timestamp`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `tinng_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `login` varchar(24) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `display_name` varchar(48) DEFAULT NULL,
  `email` varchar(128) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `hash` varchar(128) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `reg_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `approved` tinyint(1) unsigned DEFAULT NULL,
  `source` varchar(16) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL DEFAULT 'local',
  `last_read` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `status` varchar(16) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL DEFAULT 'offline',
  PRIMARY KEY (`id`),
  UNIQUE KEY `usr_email` (`email`),
  KEY `usr_login` (`login`),
  KEY `usr_approved` (`approved`),
  KEY `usr_status` (`status`(10))
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `tinng_user_settings` (
  `user_id` int(10) unsigned NOT NULL,
  `param_key` varchar(16) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `param_value` varchar(128) NOT NULL,
  KEY `user_id` (`user_id`),
  KEY `uset_key` (`param_key`(10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
