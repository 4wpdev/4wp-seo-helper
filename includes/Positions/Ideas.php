<?php
/**
 * People also ask + related searches collected from SERP checks.
 */

namespace Forwp\SeoHelper\Positions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ideas {
	public const KIND_PAA     = 'paa';
	public const KIND_RELATED = 'related';

	/**
	 * @param array<string, mixed> $check
	 */
	public static function ingest( int $query_id, array $check ): void {
		$query_id = absint( $query_id );
		if ( $query_id < 1 ) {
			return;
		}

		$paa     = isset( $check['people_also_ask'] ) && is_array( $check['people_also_ask'] ) ? $check['people_also_ask'] : [];
		$related = isset( $check['related_searches'] ) && is_array( $check['related_searches'] ) ? $check['related_searches'] : [];

		if ( [] === $paa && [] === $related ) {
			$from_payload = Client::ideas_from_payload( (string) ( $check['payload'] ?? '' ) );
			$paa          = $from_payload['people_also_ask'];
			$related      = $from_payload['related_searches'];
		}

		foreach ( $paa as $row ) {
			$phrase = is_array( $row ) ? (string) ( $row['question'] ?? '' ) : (string) $row;
			self::upsert( self::KIND_PAA, $phrase, $query_id );
		}

		foreach ( $related as $row ) {
			$phrase = is_array( $row ) ? (string) ( $row['query'] ?? '' ) : (string) $row;
			self::upsert( self::KIND_RELATED, $phrase, $query_id );
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function list_kind( string $kind ): array {
		if ( ! Schema::ideas_table_exists() ) {
			Schema::install();
		}

		$kind = self::KIND_PAA === $kind ? self::KIND_PAA : self::KIND_RELATED;
		global $wpdb;
		$table = Schema::ideas_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE kind = %s ORDER BY last_seen_at DESC, id DESC",
				$kind
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		foreach ( $rows as &$row ) {
			$row['source_ids'] = self::decode_ids( (string) ( $row['source_query_ids'] ?? '' ) );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * @return array<int, list<string>>
	 */
	public static function phrases_by_query( string $kind ): array {
		$out = [];
		foreach ( self::list_kind( $kind ) as $row ) {
			$phrase = (string) ( $row['phrase'] ?? '' );
			if ( '' === $phrase ) {
				continue;
			}
			$ids = isset( $row['source_ids'] ) && is_array( $row['source_ids'] ) ? $row['source_ids'] : [];
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( $id < 1 ) {
					continue;
				}
				if ( ! isset( $out[ $id ] ) ) {
					$out[ $id ] = [];
				}
				$out[ $id ][] = $phrase;
			}
		}

		return $out;
	}

	public static function detach_query( int $query_id ): void {
		$query_id = absint( $query_id );
		if ( $query_id < 1 ) {
			return;
		}

		global $wpdb;
		$table = Schema::ideas_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, source_query_ids FROM {$table}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$ids = self::decode_ids( (string) ( $row['source_query_ids'] ?? '' ) );
			if ( ! in_array( $query_id, $ids, true ) ) {
				continue;
			}
			$ids = array_values(
				array_filter(
					$ids,
					static function ( $id ) use ( $query_id ) {
						return (int) $id !== $query_id;
					}
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				[ 'source_query_ids' => wp_json_encode( $ids ) ],
				[ 'id' => (int) $row['id'] ],
				[ '%s' ],
				[ '%d' ]
			);
		}
	}

	private static function upsert( string $kind, string $phrase, int $query_id ): void {
		$phrase = Repository::normalize_query( $phrase );
		if ( '' === $phrase ) {
			return;
		}

		global $wpdb;
		$table = Schema::ideas_table();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, source_query_ids FROM {$table} WHERE kind = %s AND phrase = %s LIMIT 1",
				$kind,
				$phrase
			),
			ARRAY_A
		);

		if ( is_array( $existing ) ) {
			$ids = self::decode_ids( (string) ( $existing['source_query_ids'] ?? '' ) );
			if ( ! in_array( $query_id, $ids, true ) ) {
				$ids[] = $query_id;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				[
					'source_query_ids' => wp_json_encode( array_values( $ids ) ),
					'last_seen_at'     => $now,
				],
				[ 'id' => (int) $existing['id'] ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			[
				'kind'             => $kind,
				'phrase'           => $phrase,
				'source_query_ids' => wp_json_encode( [ $query_id ] ),
				'last_seen_at'     => $now,
				'created_at'       => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * @return list<int>
	 */
	private static function decode_ids( string $json ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$ids = [];
		foreach ( $decoded as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
