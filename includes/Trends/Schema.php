<?php
/**
 * Google Trends import tables.
 */

namespace Forwp\SeoHelper\Trends;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {
	public const DB_VERSION        = '1.1.0';
	public const OPTION_DB_VERSION = 'forwp_seo_trends_db_version';

	public static function keys_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'forwp_trends_keys';
	}

	public static function snapshots_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'forwp_trends_snapshots';
	}

	public static function batches_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'forwp_trends_batches';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset    = $wpdb->get_charset_collate();
		$keys       = self::keys_table();
		$snapshots  = self::snapshots_table();
		$batches    = self::batches_table();

		dbDelta(
			"CREATE TABLE {$batches} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				filename varchar(255) NOT NULL DEFAULT '',
				source varchar(32) NOT NULL DEFAULT 'google_trends',
				snapshot_hint varchar(32) NOT NULL DEFAULT '',
				rows_count int(11) unsigned NOT NULL DEFAULT 0,
				new_keys int(11) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$keys} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				query_key varchar(512) NOT NULL,
				region varchar(128) NOT NULL,
				language varchar(16) NOT NULL DEFAULT '',
				excluded tinyint(1) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY query_region (query_key(191), region(64)),
				KEY region (region(64)),
				KEY language (language),
				KEY excluded (excluded)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$snapshots} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				key_id bigint(20) unsigned NOT NULL,
				batch_id bigint(20) unsigned NOT NULL,
				snapshot_label varchar(32) NOT NULL,
				interest float NOT NULL DEFAULT 0,
				payload longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY key_snapshot (key_id, snapshot_label),
				KEY batch_id (batch_id)
			) {$charset};"
		);

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		$installed = get_option( self::OPTION_DB_VERSION, '' );
		if ( self::DB_VERSION !== $installed ) {
			self::install();
		}
	}

	public static function tables_exist(): bool {
		global $wpdb;

		$table = self::keys_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $table === $found;
	}
}
