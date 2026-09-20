<?php
/**
 * Trends keys and snapshots.
 */

namespace Forwp\SeoHelper\Trends;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Repository {
	public static function key_id( string $query, string $region ): int {
		global $wpdb;

		$table = Schema::keys_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE query_key = %s AND region = %s LIMIT 1",
				Parser::normalize_query( $query ),
				Parser::normalize_region( $region )
			)
		);

		return (int) $id;
	}

	public static function insert_key( string $query, string $region, string $language ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::keys_table(),
			[
				'query_key'  => Parser::normalize_query( $query ),
				'region'     => Parser::normalize_region( $region ),
				'language'   => Parser::normalize_language( $language ),
				'created_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	public static function maybe_set_language( int $key_id, string $language ): void {
		$language = Parser::normalize_language( $language );
		if ( '' === $language || $key_id < 1 ) {
			return;
		}

		global $wpdb;

		$wpdb->update(
			Schema::keys_table(),
			[ 'language' => $language ],
			[ 'id' => $key_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	public static function insert_snapshot( int $key_id, int $batch_id, string $snapshot, float $interest, ?array $payload = null ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::snapshots_table(),
			[
				'key_id'         => $key_id,
				'batch_id'       => $batch_id,
				'snapshot_label' => $snapshot,
				'interest'       => $interest,
				'payload'        => $payload ? wp_json_encode( $payload ) : null,
				'created_at'     => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%f', '%s', '%s' ]
		);
	}

	public static function delete_batch( int $batch_id ): bool {
		if ( $batch_id < 1 ) {
			return false;
		}

		global $wpdb;

		$snapshots = Schema::snapshots_table();
		$keys      = Schema::keys_table();
		$batches   = Schema::batches_table();

		$wpdb->delete( $snapshots, [ 'batch_id' => $batch_id ], [ '%d' ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE k FROM {$keys} k LEFT JOIN {$snapshots} s ON s.key_id = k.id WHERE s.id IS NULL"
		);

		$wpdb->delete( $batches, [ 'id' => $batch_id ], [ '%d' ] );

		return true;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function batches(): array {
		global $wpdb;

		$table = Schema::batches_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT 50",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @param bool $excluded When true, only stop phrases. When false, hide excluded rows.
	 * @return list<array<string, mixed>>
	 */
	public static function list_keys( string $language = '', string $region = '', string $search = '', bool $excluded = false ): array {
		global $wpdb;

		$keys       = Schema::keys_table();
		$where      = $excluded ? 'k.excluded = 1' : 'k.excluded = 0';
		$params     = [];

		if ( '' !== $language ) {
			$where   .= ' AND k.language = %s';
			$params[] = Parser::normalize_language( $language );
		}
		if ( '' !== $region ) {
			$where   .= ' AND k.region = %s';
			$params[] = Parser::normalize_region( $region );
		}
		$search = trim( $search );
		if ( '' !== $search ) {
			$where   .= ' AND k.query_key LIKE %s';
			$params[] = '%' . $wpdb->esc_like( Parser::normalize_query( $search ) ) . '%';
		}

		$sql = "SELECT k.* FROM {$keys} k WHERE {$where} ORDER BY k.query_key ASC, k.region ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$ids = array_map( static fn( $row ) => (int) $row['id'], $rows );
		$map = self::snapshots_for_keys( $ids );

		$out = [];
		foreach ( $rows as $row ) {
			$id              = (int) $row['id'];
			$row['snapshots']  = $map[ $id ] ?? [];
			$row['delta']      = self::delta_from_snapshots( $row['snapshots'] );
			$row['delta_label'] = self::format_delta( $row['snapshots'] );
			$out[]             = $row;
		}

		return $out;
	}

	public static function set_excluded( int $key_id, bool $excluded ): bool {
		$key_id = absint( $key_id );
		if ( $key_id < 1 ) {
			return false;
		}

		global $wpdb;

		$updated = $wpdb->update(
			Schema::keys_table(),
			[ 'excluded' => $excluded ? 1 : 0 ],
			[ 'id' => $key_id ],
			[ '%d' ],
			[ '%d' ]
		);

		return false !== $updated;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, list<array<string, mixed>>>
	 */
	private static function snapshots_for_keys( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return [];
		}

		global $wpdb;

		$table = Schema::snapshots_table();
		$in    = implode( ',', $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE key_id IN ({$in}) ORDER BY snapshot_label ASC, id ASC",
			ARRAY_A
		);

		$map = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (int) $row['key_id'] ][] = $row;
			}
		}

		return $map;
	}

	/**
	 * Latest interest + delta per normalized query (any region; highest latest interest wins).
	 *
	 * @return array<string, array{interest: float, delta: float, snapshot: string, region: string}>
	 */
	public static function latest_by_query(): array {
		$keys = self::list_keys();
		$out  = [];

		foreach ( $keys as $row ) {
			$query     = (string) $row['query_key'];
			$snaps     = is_array( $row['snapshots'] ?? null ) ? $row['snapshots'] : [];
			$latest    = self::latest_snapshot_row( $snaps );
			if ( ! $latest ) {
				continue;
			}
			$interest = (float) $latest['interest'];
			if ( isset( $out[ $query ] ) && $out[ $query ]['interest'] >= $interest ) {
				continue;
			}
			$out[ $query ] = [
				'query'       => $query,
				'interest'    => $interest,
				'delta'       => (float) ( $row['delta'] ?? 0 ),
				'delta_label' => (string) ( $row['delta_label'] ?? '' ),
				'snapshot'    => (string) $latest['snapshot_label'],
				'region'      => (string) $row['region'],
			];
		}

		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $snapshots
	 */
	private static function latest_snapshot_row( array $snapshots ): ?array {
		if ( empty( $snapshots ) ) {
			return null;
		}

		$by_label = [];
		foreach ( $snapshots as $snap ) {
			$label = (string) ( $snap['snapshot_label'] ?? '' );
			$id    = (int) ( $snap['id'] ?? 0 );
			if ( ! isset( $by_label[ $label ] ) || $id > (int) $by_label[ $label ]['id'] ) {
				$by_label[ $label ] = $snap;
			}
		}

		ksort( $by_label );
		$vals = array_values( $by_label );

		return $vals[ count( $vals ) - 1 ] ?? null;
	}

	/**
	 * @param list<array<string, mixed>> $snapshots
	 */
	public static function delta_from_snapshots( array $snapshots ): float {
		$by_label = [];
		foreach ( $snapshots as $snap ) {
			$label = (string) ( $snap['snapshot_label'] ?? '' );
			$id    = (int) ( $snap['id'] ?? 0 );
			if ( ! isset( $by_label[ $label ] ) || $id > (int) $by_label[ $label ]['id'] ) {
				$by_label[ $label ] = $snap;
			}
		}
		ksort( $by_label );
		$vals = array_values( $by_label );
		$n    = count( $vals );
		if ( $n < 1 ) {
			return 0.0;
		}
		if ( $n < 2 ) {
			$payload = self::decode_payload( $vals[0]['payload'] ?? null );
			if ( isset( $payload['increase_percent'] ) && is_numeric( $payload['increase_percent'] ) ) {
				return (float) $payload['increase_percent'];
			}
			return 0.0;
		}

		return (float) $vals[ $n - 1 ]['interest'] - (float) $vals[ $n - 2 ]['interest'];
	}

	/**
	 * @param list<array<string, mixed>> $snapshots
	 */
	public static function format_delta( array $snapshots ): string {
		$by_label = [];
		foreach ( $snapshots as $snap ) {
			$label = (string) ( $snap['snapshot_label'] ?? '' );
			$id    = (int) ( $snap['id'] ?? 0 );
			if ( ! isset( $by_label[ $label ] ) || $id > (int) $by_label[ $label ]['id'] ) {
				$by_label[ $label ] = $snap;
			}
		}
		ksort( $by_label );
		$vals = array_values( $by_label );
		$n    = count( $vals );
		if ( $n < 1 ) {
			return '→ 0';
		}

		if ( $n < 2 ) {
			$payload = self::decode_payload( $vals[0]['payload'] ?? null );
			$raw     = (string) ( $payload['increase_raw'] ?? '' );
			if ( 0 === strcasecmp( $raw, 'breakout' ) ) {
				return '↑ Breakout';
			}
			$delta = self::delta_from_snapshots( $snapshots );
			$arrow = $delta > 0 ? '↑' : ( $delta < 0 ? '↓' : '→' );
			$num   = 0.0 === $delta ? '0' : (string) $delta;
			return isset( $payload['increase_percent'] ) && is_numeric( $payload['increase_percent'] )
				? $arrow . ' ' . $num . '%'
				: $arrow . ' ' . $num;
		}

		$delta = (float) $vals[ $n - 1 ]['interest'] - (float) $vals[ $n - 2 ]['interest'];
		$arrow = $delta > 0 ? '↑' : ( $delta < 0 ? '↓' : '→' );
		return $arrow . ' ' . ( 0.0 === $delta ? '0' : (string) $delta );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decode_payload( mixed $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @return list<string>
	 */
	public static function snapshot_labels( array $keys ): array {
		$labels = [];
		foreach ( $keys as $row ) {
			foreach ( (array) ( $row['snapshots'] ?? [] ) as $snap ) {
				$label = (string) ( $snap['snapshot_label'] ?? '' );
				if ( '' !== $label ) {
					$labels[ $label ] = true;
				}
			}
		}
		$out = array_keys( $labels );
		sort( $out );

		return $out;
	}

	public static function languages(): array {
		global $wpdb;
		$table = Schema::keys_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = $wpdb->get_col( "SELECT DISTINCT language FROM {$table} WHERE language <> '' ORDER BY language ASC" );

		return is_array( $cols ) ? $cols : [];
	}

	public static function regions(): array {
		global $wpdb;
		$table = Schema::keys_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = $wpdb->get_col( "SELECT DISTINCT region FROM {$table} ORDER BY region ASC" );

		return is_array( $cols ) ? $cols : [];
	}
}
