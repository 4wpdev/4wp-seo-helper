<?php
/**
 * GA4 storage schema.
 *
 * - forwp_ga_daily:  one row per property × date × metric (additive metrics: sessions, pageviews…).
 * - forwp_ga_period: exact totals per property × period × metric (unique users cannot be summed by day).
 */

namespace Forwp\SeoHelper\Ga;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {
	public const DB_VERSION        = '1.0.0';
	public const OPTION_DB_VERSION = 'forwp_seo_ga_db_version';

	public static function daily_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'forwp_ga_daily';
	}

	public static function period_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'forwp_ga_period';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$daily   = self::daily_table();
		$period  = self::period_table();

		dbDelta(
			"CREATE TABLE {$daily} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				property varchar(32) NOT NULL,
				metric_date date NOT NULL,
				metric varchar(64) NOT NULL,
				value bigint(20) NOT NULL DEFAULT 0,
				synced_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY property_date_metric (property, metric_date, metric),
				KEY metric_date (metric_date)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$period} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				property varchar(32) NOT NULL,
				period_start date NOT NULL,
				period_end date NOT NULL,
				metric varchar(64) NOT NULL,
				value bigint(20) NOT NULL DEFAULT 0,
				synced_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY property_period_metric (property, period_start, period_end, metric)
			) {$charset};"
		);

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION, '' ) ) {
			self::install();
		}
	}
}
