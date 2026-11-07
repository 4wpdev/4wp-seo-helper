<?php
/**
 * GA4 storage: daily rows and period totals.
 */

namespace Forwp\SeoHelper\Ga;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Repository {

	/**
	 * Dates in [start, end] that have a final row for every metric (older than the refresh window).
	 *
	 * @param list<string> $metrics Metrics.
	 * @return array<string, true> date => true
	 */
	public static function stored_dates( string $property, string $start, string $end, array $metrics ): array {
		global $wpdb;
		$table = Schema::daily_table();
		$in    = implode( ',', array_fill( 0, count( $metrics ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT metric_date, COUNT(DISTINCT metric) AS n FROM {$table} WHERE property = %s AND metric_date BETWEEN %s AND %s AND metric IN ({$in}) GROUP BY metric_date", array_merge( [ $property, $start, $end ], $metrics ) ) );
		$out  = [];
		foreach ( (array) $rows as $row ) {
			if ( (int) $row->n >= count( $metrics ) ) {
				$out[ (string) $row->metric_date ] = true;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, array<string, int>> $by_date date => [metric => value]
	 */
	public static function save_daily( string $property, array $by_date ): void {
		global $wpdb;
		$table = Schema::daily_table();
		$now   = current_time( 'mysql', true );
		foreach ( $by_date as $date => $values ) {
			foreach ( $values as $metric => $value ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (property, metric_date, metric, value, synced_at) VALUES (%s, %s, %s, %d, %s) ON DUPLICATE KEY UPDATE value = VALUES(value), synced_at = VALUES(synced_at)", $property, $date, $metric, (int) $value, $now ) );
			}
		}
	}

	public static function sum_daily( string $property, string $start, string $end, string $metric ): int {
		global $wpdb;
		$table = Schema::daily_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(value), 0) FROM {$table} WHERE property = %s AND metric_date BETWEEN %s AND %s AND metric = %s", $property, $start, $end, $metric ) );
	}

	/**
	 * @return array{value: int, synced_at: string}|null
	 */
	public static function get_period( string $property, string $start, string $end, string $metric ): ?array {
		global $wpdb;
		$table = Schema::period_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT value, synced_at FROM {$table} WHERE property = %s AND period_start = %s AND period_end = %s AND metric = %s", $property, $start, $end, $metric ) );
		return $row ? [
			'value'     => (int) $row->value,
			'synced_at' => (string) $row->synced_at,
		] : null;
	}

	public static function save_period( string $property, string $start, string $end, string $metric, int $value ): void {
		global $wpdb;
		$table = Schema::period_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (property, period_start, period_end, metric, value, synced_at) VALUES (%s, %s, %s, %s, %d, %s) ON DUPLICATE KEY UPDATE value = VALUES(value), synced_at = VALUES(synced_at)", $property, $start, $end, $metric, $value, current_time( 'mysql', true ) ) );
	}
}
