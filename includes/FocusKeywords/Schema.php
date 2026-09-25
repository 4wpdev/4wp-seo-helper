<?php
/**
 * Stored unique focus keywords + usage rows + topic catalog.
 */

namespace Forwp\SeoHelper\FocusKeywords;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {
	public const DB_VERSION        = '1.2.0';
	public const OPTION_DB_VERSION = 'forwp_seo_focus_keywords_db_version';
	public const OPTION_LAST_SCAN  = 'forwp_seo_focus_keywords_last_scan';

	public static function keywords_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'forwp_focus_keywords';
	}

	public static function uses_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'forwp_focus_keyword_uses';
	}

	public static function topics_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'forwp_focus_keyword_topics';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$keywords = self::keywords_table();
		$uses     = self::uses_table();
		$topics   = self::topics_table();

		dbDelta(
			"CREATE TABLE {$keywords} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				phrase_key varchar(191) NOT NULL,
				phrase varchar(512) NOT NULL,
				topic varchar(191) NOT NULL DEFAULT '',
				use_count int(11) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY phrase_key (phrase_key),
				KEY topic (topic)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$uses} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				keyword_id bigint(20) unsigned NOT NULL,
				object_kind varchar(16) NOT NULL DEFAULT 'post',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_type varchar(64) NOT NULL DEFAULT '',
				title varchar(255) NOT NULL DEFAULT '',
				url varchar(768) NOT NULL DEFAULT '',
				url_key varchar(191) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY keyword_object (keyword_id, object_kind, object_id),
				KEY object (object_kind, object_id),
				KEY url_key (url_key)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$topics} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				name_key varchar(191) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY name_key (name_key)
			) {$charset};"
		);

		self::ensure_topic_column();
		self::ensure_topics_table();
		Topics::seed_defaults();
		Topics::sync_from_keywords();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		$installed = get_option( self::OPTION_DB_VERSION, '' );
		if ( self::DB_VERSION !== $installed || ! self::tables_exist() ) {
			self::install();
			return;
		}
		self::ensure_topic_column();
		self::ensure_topics_table();
		Topics::seed_defaults();
	}

	public static function tables_exist(): bool {
		global $wpdb;

		$table = self::keywords_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $table === $found;
	}

	public static function ensure_topic_column(): void {
		global $wpdb;

		$table = self::keywords_table();
		if ( ! self::tables_exist() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'topic'", ARRAY_A );
		if ( is_array( $col ) && [] !== $col ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN topic varchar(191) NOT NULL DEFAULT '' AFTER phrase, ADD KEY topic (topic)" );
	}

	public static function ensure_topics_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::topics_table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				name_key varchar(191) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY name_key (name_key)
			) {$charset};"
		);
	}
}
