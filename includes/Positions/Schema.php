<?php
/**
 * Positions query + check tables.
 */

namespace Forwp\SeoHelper\Positions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {
	public const DB_VERSION        = '1.3.0';
	public const OPTION_DB_VERSION = 'forwp_seo_positions_db_version';

	public static function queries_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'forwp_positions_queries';
	}

	public static function checks_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'forwp_positions_checks';
	}

	public static function ideas_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'forwp_positions_ideas';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$queries = self::queries_table();
		$checks  = self::checks_table();
		$ideas   = self::ideas_table();

		dbDelta(
			"CREATE TABLE {$queries} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				query_text varchar(512) NOT NULL,
				gl varchar(8) NOT NULL DEFAULT 'us',
				hl varchar(16) NOT NULL DEFAULT 'en',
				tbs varchar(64) NOT NULL DEFAULT '',
				last_checked_at datetime NULL,
				last_check_id bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY query_gl (query_text(191), gl),
				KEY gl (gl),
				KEY last_checked_at (last_checked_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$checks} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				query_id bigint(20) unsigned NOT NULL,
				provider varchar(32) NOT NULL DEFAULT 'serper',
				gl varchar(8) NOT NULL DEFAULT '',
				hl varchar(16) NOT NULL DEFAULT '',
				checked_at datetime NOT NULL,
				target_property varchar(255) NOT NULL DEFAULT '',
				target_host varchar(255) NOT NULL DEFAULT '',
				organic_count int(11) unsigned NOT NULL DEFAULT 0,
				site_position int(11) unsigned NULL,
				http_code int(11) unsigned NOT NULL DEFAULT 0,
				error varchar(255) NOT NULL DEFAULT '',
				payload longtext NULL,
				PRIMARY KEY  (id),
				KEY query_checked (query_id, checked_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$ideas} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(16) NOT NULL DEFAULT 'related',
				phrase varchar(512) NOT NULL,
				source_query_ids longtext NULL,
				last_seen_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY kind_phrase (kind, phrase(191)),
				KEY kind (kind)
			) {$charset};"
		);

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		$installed = get_option( self::OPTION_DB_VERSION, '' );
		if ( self::DB_VERSION !== $installed || ! self::ideas_table_exists() ) {
			self::install();
		}
	}

	public static function tables_exist(): bool {
		global $wpdb;

		$table = self::queries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $table === $found;
	}

	public static function ideas_table_exists(): bool {
		global $wpdb;

		$table = self::ideas_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $table === $found;
	}
}
