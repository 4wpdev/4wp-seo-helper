<?php
/**
 * Positions query storage.
 */

namespace Forwp\SeoHelper\Positions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Repository {
	/**
	 * @return int|\WP_Error
	 */
	public static function add_query( string $query, string $gl, string $hl, string $tbs = '' ) {
		$query = self::normalize_query( $query );
		$gl    = SerperLocales::normalize_gl( $gl );
		$hl    = SerperLocales::normalize_hl( $hl );
		$tbs   = self::normalize_tbs( $tbs );

		if ( '' === $query ) {
			return new \WP_Error( 'forwp_positions_query', __( 'Enter a search query.', '4wp-seo-helper' ) );
		}

		$existing = self::find( $query, $gl );
		if ( $existing ) {
			return new \WP_Error( 'forwp_positions_exists', __( 'That query is already tracked for this country.', '4wp-seo-helper' ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			Schema::queries_table(),
			[
				'query_text' => $query,
				'gl'         => $gl,
				'hl'         => $hl,
				'tbs'        => $tbs,
				'created_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $ok ) {
			return new \WP_Error( 'forwp_positions_db', __( 'Could not save the query.', '4wp-seo-helper' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$table = Schema::queries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find( string $query, string $gl ): ?array {
		global $wpdb;

		$table = Schema::queries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE query_text = %s AND gl = %s",
				self::normalize_query( $query ),
				SerperLocales::normalize_gl( $gl )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function list_queries(): array {
		global $wpdb;

		$queries = Schema::queries_table();
		$checks  = Schema::checks_table();

		$sql = "SELECT q.*, c.checked_at AS check_date, c.site_position, c.organic_count, c.error AS check_error, c.target_property, c.target_host, c.payload AS check_payload
			FROM {$queries} q
			LEFT JOIN {$checks} c ON c.id = q.last_check_id
			ORDER BY q.created_at DESC, q.id DESC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @param array<string, mixed> $check
	 */
	public static function save_check( int $query_id, array $check ): int {
		global $wpdb;

		$now = (string) ( $check['checked_at'] ?? current_time( 'mysql' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			Schema::checks_table(),
			[
				'query_id'      => $query_id,
				'provider'      => (string) ( $check['provider'] ?? Settings::PROVIDER_SERPER ),
				'gl'            => (string) ( $check['gl'] ?? '' ),
				'hl'            => (string) ( $check['hl'] ?? '' ),
				'checked_at'      => $now,
				'target_property' => (string) ( $check['target_property'] ?? '' ),
				'target_host'     => (string) ( $check['target_host'] ?? '' ),
				'organic_count'   => (int) ( $check['organic_count'] ?? 0 ),
				'site_position'   => (int) ( $check['site_position'] ?? 0 ),
				'http_code'       => (int) ( $check['http_code'] ?? 0 ),
				'error'           => (string) ( $check['error'] ?? '' ),
				'payload'         => (string) ( $check['payload'] ?? '' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ]
		);

		$check_id = (int) $wpdb->insert_id;

		if ( $check_id > 0 ) {
			Ideas::ingest( $query_id, $check );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::queries_table(),
			[
				'last_checked_at' => $now,
				'last_check_id'   => $check_id,
			],
			[ 'id' => $query_id ],
			[ '%s', '%d' ],
			[ '%d' ]
		);

		return $check_id;
	}

	public static function delete_query( int $query_id ): bool {
		$query_id = absint( $query_id );
		if ( $query_id < 1 ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::checks_table(), [ 'query_id' => $query_id ], [ '%d' ] );
		Ideas::detach_query( $query_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( Schema::queries_table(), [ 'id' => $query_id ], [ '%d' ] );

		return false !== $deleted && $deleted > 0;
	}

	public static function normalize_query( string $query ): string {
		$query = trim( preg_replace( '/\s+/u', ' ', $query ) ?? '' );

		return function_exists( 'mb_substr' ) ? mb_substr( $query, 0, 512 ) : substr( $query, 0, 512 );
	}

	public static function normalize_tbs( string $tbs ): string {
		return Client::normalize_tbs( $tbs );
	}
}
