<?php
/**
 * Event-sourced SEO history. One row per real change, capped per post.
 */

namespace Forwp\SeoHelper\Inventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class History {
	public const TYPE_INDEX_REQUEST = 'index_request';
	public const TYPE_INSPECT       = 'inspect';
	public const TYPE_CRAWL         = 'crawl';
	public const TYPE_CONTENT       = 'content';
	public const TYPE_SEO           = 'seo';
	public const TYPE_GSC           = 'gsc';

	public const MAX_PER_POST = 40;

	/** @var list<string> */
	public const TYPES = [
		self::TYPE_INDEX_REQUEST,
		self::TYPE_INSPECT,
		self::TYPE_CRAWL,
		self::TYPE_CONTENT,
		self::TYPE_SEO,
		self::TYPE_GSC,
	];

	/**
	 * @param array<string, mixed> $snapshot
	 */
	public static function record( int $post_id, string $type, array $snapshot, ?string $occurred_at = null, bool $force = false ): bool {
		$post_id = absint( $post_id );
		$type    = sanitize_key( $type );
		if ( $post_id <= 0 || ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}

		if ( ! HistorySchema::tables_exist() ) {
			HistorySchema::install();
		}

		$snapshot    = self::sanitize_snapshot( $snapshot );
		$fingerprint = self::fingerprint( $type, $snapshot );
		if ( ! $force && self::last_fingerprint( $post_id, $type ) === $fingerprint ) {
			return false;
		}

		$occurred_at = self::normalize_datetime( $occurred_at );
		$encoded     = wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			$encoded = '{}';
		}

		global $wpdb;
		$table = HistorySchema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'post_id'      => $post_id,
				'event_type'   => $type,
				'occurred_at'  => $occurred_at,
				'fingerprint'  => $fingerprint,
				'snapshot'     => $encoded,
			],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return false;
		}

		self::prune_post( $post_id );

		return true;
	}

	/**
	 * Pages that already have at least one recorded event.
	 *
	 * @return array{items: list<array{post_id:int,event_count:int,last_at:string,last_type:string,last_snapshot:array<string,mixed>}>, total: int}
	 */
	public static function listed_posts( int $page = 1, int $per_page = 20, string $search = '' ): array {
		$empty = [
			'items' => [],
			'total' => 0,
		];

		if ( ! HistorySchema::tables_exist() ) {
			return $empty;
		}

		global $wpdb;
		$table    = HistorySchema::table_name();
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;
		$search   = sanitize_text_field( $search );

		$join  = "INNER JOIN {$wpdb->posts} p ON p.ID = h.post_id";
		$where = "p.post_status NOT IN ('trash','auto-draft','inherit')";
		$args  = [];

		if ( '' !== $search ) {
			$where .= ' AND p.post_title LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$count_sql = "SELECT COUNT(DISTINCT h.post_id) FROM {$table} h {$join} WHERE {$where}";
		if ( [] !== $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name and optional search.
			$count_sql = $wpdb->prepare( $count_sql, ...$args );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $count_sql );

		$list_sql  = "SELECT h.post_id, COUNT(*) AS event_count, MAX(h.occurred_at) AS last_at, MAX(h.id) AS last_id
			FROM {$table} h
			{$join}
			WHERE {$where}
			GROUP BY h.post_id
			ORDER BY last_at DESC, h.post_id DESC
			LIMIT %d OFFSET %d";
		$list_args = array_merge( $args, [ $per_page, $offset ] );
		$prepared  = $wpdb->prepare( $list_sql, ...$list_args );

		if ( ! is_string( $prepared ) || '' === $prepared ) {
			return $empty;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) || [] === $rows ) {
			return [
				'items' => [],
				'total' => $total,
			];
		}

		$last_ids = [];
		foreach ( $rows as $row ) {
			$last_ids[] = (int) ( $row['last_id'] ?? 0 );
		}
		$last_ids = array_values( array_filter( $last_ids ) );
		$types    = [];

		if ( [] !== $last_ids ) {
			$id_sql = implode( ',', array_map( 'absint', $last_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$type_rows = $wpdb->get_results(
				"SELECT id, event_type, snapshot FROM {$table} WHERE id IN ({$id_sql})",
				ARRAY_A
			);
			if ( is_array( $type_rows ) ) {
				foreach ( self::hydrate_rows( $type_rows ) as $type_row ) {
					$types[ (int) $type_row['id'] ] = $type_row;
				}
			}
		}

		$items = [];
		foreach ( $rows as $row ) {
			$last_id = (int) ( $row['last_id'] ?? 0 );
			$last    = $types[ $last_id ] ?? null;
			$items[] = [
				'post_id'        => (int) ( $row['post_id'] ?? 0 ),
				'event_count'    => (int) ( $row['event_count'] ?? 0 ),
				'last_at'        => (string) ( $row['last_at'] ?? '' ),
				'last_type'      => is_array( $last ) ? (string) $last['event_type'] : '',
				'last_snapshot'  => is_array( $last ) ? (array) $last['snapshot'] : [],
			];
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Newest events of one type per post (newest first).
	 *
	 * @param list<int> $post_ids Post IDs.
	 * @return array<int, list<array<string, mixed>>>
	 */
	public static function latest_typed( array $post_ids, string $type, int $each = 2 ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		$type     = sanitize_key( $type );
		$each     = max( 1, min( 5, $each ) );
		if ( [] === $post_ids || ! in_array( $type, self::TYPES, true ) || ! HistorySchema::tables_exist() ) {
			return [];
		}

		global $wpdb;
		$table  = HistorySchema::table_name();
		$id_sql = implode( ',', $post_ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, post_id, event_type, occurred_at, snapshot
				FROM {$table}
				WHERE event_type = %s AND post_id IN ({$id_sql})
				ORDER BY post_id ASC, id DESC",
				$type
			),
			ARRAY_A
		);

		$grouped = [];
		foreach ( self::hydrate_rows( is_array( $rows ) ? $rows : [] ) as $row ) {
			$pid = (int) $row['post_id'];
			if ( ! isset( $grouped[ $pid ] ) ) {
				$grouped[ $pid ] = [];
			}
			if ( count( $grouped[ $pid ] ) >= $each ) {
				continue;
			}
			$grouped[ $pid ][] = $row;
		}

		return $grouped;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function recent( int $limit = 25 ): array {
		return self::query_rows(
			'ORDER BY occurred_at DESC, id DESC LIMIT %d',
			[ max( 1, min( 100, $limit ) ) ]
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function for_post( int $post_id, int $limit = 40 ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return [];
		}

		return self::query_rows(
			'WHERE post_id = %d ORDER BY occurred_at DESC, id DESC LIMIT %d',
			[ $post_id, max( 1, min( self::MAX_PER_POST, $limit ) ) ]
		);
	}

	/**
	 * Latest event of each type for a post.
	 *
	 * @return array<string, array<string, mixed>|null>
	 */
	public static function latest_by_type( int $post_id ): array {
		$latest = array_fill_keys( self::TYPES, null );

		foreach ( self::for_post( $post_id ) as $row ) {
			$type = (string) ( $row['event_type'] ?? '' );
			if ( array_key_exists( $type, $latest ) && null === $latest[ $type ] ) {
				$latest[ $type ] = $row;
			}
		}

		return $latest;
	}

	public static function count_since( string $gmt_datetime ): int {
		if ( ! HistorySchema::tables_exist() ) {
			return 0;
		}

		global $wpdb;
		$table = HistorySchema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE occurred_at >= %s",
				$gmt_datetime
			)
		);
	}

	public static function distinct_posts_since( string $gmt_datetime ): int {
		if ( ! HistorySchema::tables_exist() ) {
			return 0;
		}

		global $wpdb;
		$table = HistorySchema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$table} WHERE occurred_at >= %s",
				$gmt_datetime
			)
		);
	}

	/**
	 * Index requests that still have no later crawl event.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function waiting_for_crawl( int $limit = 10 ): array {
		if ( ! HistorySchema::tables_exist() ) {
			return [];
		}

		global $wpdb;
		$table = HistorySchema::table_name();
		$limit = max( 1, min( 50, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.id, h.post_id, h.event_type, h.occurred_at, h.snapshot
				FROM {$table} h
				INNER JOIN (
					SELECT post_id, MAX(occurred_at) AS occurred_at
					FROM {$table}
					WHERE event_type = %s
					GROUP BY post_id
				) latest ON latest.post_id = h.post_id AND latest.occurred_at = h.occurred_at AND h.event_type = %s
				WHERE NOT EXISTS (
					SELECT 1 FROM {$table} c
					WHERE c.post_id = h.post_id
					AND c.event_type = %s
					AND c.occurred_at >= h.occurred_at
				)
				ORDER BY h.occurred_at DESC
				LIMIT %d",
				self::TYPE_INDEX_REQUEST,
				self::TYPE_INDEX_REQUEST,
				self::TYPE_CRAWL,
				$limit
			),
			ARRAY_A
		);

		return self::hydrate_rows( is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Post IDs already in history (bounded) — used after GSC sync instead of scanning the whole site.
	 *
	 * @return list<int>
	 */
	public static function tracked_post_ids( int $limit = 200 ): array {
		$ids   = [];
		$queue = new PriorityQueue();
		foreach ( $queue->get_lanes() as $lane_ids ) {
			foreach ( $lane_ids as $id ) {
				$ids[] = (int) $id;
			}
		}

		if ( HistorySchema::tables_exist() ) {
			global $wpdb;
			$table = HistorySchema::table_name();
			$since = gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$history_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$table}
					WHERE occurred_at >= %s
					ORDER BY post_id DESC
					LIMIT %d",
					$since,
					max( 1, min( 400, $limit ) )
				)
			);

			if ( is_array( $history_ids ) ) {
				foreach ( $history_ids as $id ) {
					$ids[] = (int) $id;
				}
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		return array_slice( $ids, 0, max( 1, min( 400, $limit ) ) );
	}

	private static function last_fingerprint( int $post_id, string $type ): string {
		if ( ! HistorySchema::tables_exist() ) {
			return '';
		}

		global $wpdb;
		$table = HistorySchema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT fingerprint FROM {$table}
				WHERE post_id = %d AND event_type = %s
				ORDER BY occurred_at DESC, id DESC
				LIMIT 1",
				$post_id,
				$type
			)
		);

		return is_string( $value ) ? $value : '';
	}

	private static function prune_post( int $post_id ): void {
		global $wpdb;
		$table = HistorySchema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE post_id = %d",
				$post_id
			)
		);

		if ( $count <= self::MAX_PER_POST ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$keep = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE post_id = %d
				ORDER BY occurred_at DESC, id DESC
				LIMIT %d",
				$post_id,
				self::MAX_PER_POST
			)
		);

		if ( ! is_array( $keep ) || [] === $keep ) {
			return;
		}

		$keep_sql = implode( ',', array_map( 'absint', $keep ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE post_id = %d AND id NOT IN ({$keep_sql})",
				$post_id
			)
		);
	}

	/**
	 * @param list<mixed> $args
	 * @return list<array<string, mixed>>
	 */
	private static function query_rows( string $sql_tail, array $args ): array {
		if ( ! HistorySchema::tables_exist() ) {
			return [];
		}

		global $wpdb;
		$table    = HistorySchema::table_name();
		$sql      = "SELECT id, post_id, event_type, occurred_at, snapshot FROM {$table} {$sql_tail}";
		$prepared = $wpdb->prepare( $sql, ...$args );

		if ( ! is_string( $prepared ) || '' === $prepared ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $prepared, ARRAY_A );

		return self::hydrate_rows( is_array( $rows ) ? $rows : [] );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private static function hydrate_rows( array $rows ): array {
		$hydrated = [];

		foreach ( $rows as $row ) {
			$snapshot = [];
			$raw      = (string) ( $row['snapshot'] ?? '' );
			if ( '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$snapshot = self::sanitize_snapshot( $decoded );
				}
			}

			$hydrated[] = [
				'id'          => (int) ( $row['id'] ?? 0 ),
				'post_id'     => (int) ( $row['post_id'] ?? 0 ),
				'event_type'  => sanitize_key( (string) ( $row['event_type'] ?? '' ) ),
				'occurred_at' => (string) ( $row['occurred_at'] ?? '' ),
				'snapshot'    => $snapshot,
			];
		}

		return $hydrated;
	}

	/**
	 * @param array<string, mixed> $snapshot
	 */
	private static function fingerprint( string $type, array $snapshot ): string {
		$slice = match ( $type ) {
			self::TYPE_CONTENT => [ 'chars' => $snapshot['chars'] ?? 0 ],
			self::TYPE_SEO     => [
				'score'    => $snapshot['score'] ?? 0,
				'title'    => $snapshot['title'] ?? '',
				'desc_len' => $snapshot['desc_len'] ?? 0,
				'keys'     => $snapshot['keys'] ?? [],
				'og'       => $snapshot['og'] ?? 0,
			],
			self::TYPE_GSC     => [
				'clicks' => $snapshot['clicks'] ?? 0,
				'impr'   => $snapshot['impr'] ?? 0,
				'pos'    => $snapshot['pos'] ?? 0,
			],
			self::TYPE_CRAWL   => [ 'crawl' => $snapshot['crawl'] ?? '' ],
			self::TYPE_INSPECT => [
				'cov'   => $snapshot['cov'] ?? '',
				'crawl' => $snapshot['crawl'] ?? '',
			],
			default            => $snapshot,
		};

		$encoded = wp_json_encode( $slice );

		return md5( is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * @param array<string, mixed> $snapshot
	 * @return array<string, mixed>
	 */
	private static function sanitize_snapshot( array $snapshot ): array {
		$keys = [];
		foreach ( (array) ( $snapshot['keys'] ?? [] ) as $key ) {
			$key = sanitize_text_field( (string) $key );
			if ( '' !== $key ) {
				$keys[] = self::clip( $key, 80 );
			}
			if ( count( $keys ) >= 10 ) {
				break;
			}
		}

		$sugg = [];
		foreach ( (array) ( $snapshot['sugg'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$query = self::clip( sanitize_text_field( (string) ( $row['q'] ?? '' ) ), 80 );
			if ( '' === $query ) {
				continue;
			}
			$sugg[] = [
				'q' => $query,
				'c' => (int) ( $row['c'] ?? 0 ),
				'i' => (int) ( $row['i'] ?? 0 ),
				'p' => round( (float) ( $row['p'] ?? 0 ), 1 ),
			];
			if ( count( $sugg ) >= 5 ) {
				break;
			}
		}

		$clean = [
			'chars'    => max( 0, (int) ( $snapshot['chars'] ?? 0 ) ),
			'score'    => max( 0, min( 100, (int) ( $snapshot['score'] ?? 0 ) ) ),
			'title'    => self::clip( sanitize_text_field( (string) ( $snapshot['title'] ?? '' ) ), 80 ),
			'desc_len' => max( 0, (int) ( $snapshot['desc_len'] ?? 0 ) ),
			'keys'     => $keys,
			'sugg'     => $sugg,
			'clicks'   => max( 0, (int) ( $snapshot['clicks'] ?? 0 ) ),
			'impr'     => max( 0, (int) ( $snapshot['impr'] ?? 0 ) ),
			'pos'      => round( (float) ( $snapshot['pos'] ?? 0 ), 1 ),
			'ctr'      => round( (float) ( $snapshot['ctr'] ?? 0 ), 2 ),
			'cov'      => self::clip( sanitize_text_field( (string) ( $snapshot['cov'] ?? '' ) ), 80 ),
			'crawl'    => self::clip( sanitize_text_field( (string) ( $snapshot['crawl'] ?? '' ) ), 40 ),
			'og'       => empty( $snapshot['og'] ) ? 0 : 1,
		];

		return array_filter(
			$clean,
			static function ( $value ): bool {
				if ( is_array( $value ) ) {
					return [] !== $value;
				}
				if ( is_int( $value ) || is_float( $value ) ) {
					return 0 !== $value && 0.0 !== $value;
				}

				return '' !== $value;
			}
		);
	}

	private static function clip( string $value, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}

		return substr( $value, 0, $max );
	}

	private static function normalize_datetime( ?string $occurred_at ): string {
		if ( is_string( $occurred_at ) && '' !== $occurred_at ) {
			$ts = strtotime( $occurred_at );
			if ( false !== $ts ) {
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		return gmdate( 'Y-m-d H:i:s' );
	}
}
