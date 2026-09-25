<?php
/**
 * Read stored focus keywords.
 */

namespace Forwp\SeoHelper\FocusKeywords;

use Forwp\SeoHelper\Admin\InventoryPage;
use Forwp\SeoHelper\Positions\Client;
use Forwp\SeoHelper\Positions\Repository as PositionsRepository;
use Forwp\SeoHelper\Positions\Schema as PositionsSchema;
use Forwp\SeoHelper\Positions\Target;
use Forwp\SeoHelper\SeoMeta\FocusKeyphrases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Repository {
	/**
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public static function list( string $search = '', string $topic = '', int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$empty = [
			'items' => [],
			'total' => 0,
		];

		if ( ! Schema::tables_exist() ) {
			return $empty;
		}
		Schema::ensure_topic_column();

		$table    = Schema::keywords_table();
		$search   = FocusKeyphrases::normalize_key( $search );
		$topic    = Bank::normalize_topic( $topic );
		$page     = max( 1, $page );
		$per_page = max( 1, min( 500, $per_page ) );
		$where    = [];
		$args     = [];

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(phrase_key LIKE %s OR phrase LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}

		if ( '__none__' === $topic ) {
			$where[] = "(topic = '' OR topic IS NULL)";
		} elseif ( '' !== $topic ) {
			$where[] = 'topic = %s';
			$args[]  = $topic;
		}

		$where_sql = [] !== $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$count_sql = "SELECT COUNT(*) FROM {$table}{$where_sql}";

		if ( [] !== $args ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $count_sql );
		}

		if ( $total < 1 ) {
			return $empty;
		}

		$offset     = ( $page - 1 ) * $per_page;
		$sql        = "SELECT * FROM {$table}{$where_sql} ORDER BY topic ASC, use_count DESC, phrase ASC LIMIT %d OFFSET %d";
		$query_args = array_merge( $args, [ $per_page, $offset ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A );
		if ( ! is_array( $rows ) || [] === $rows ) {
			return [
				'items' => [],
				'total' => $total,
			];
		}

		$ids     = array_map( 'intval', array_column( $rows, 'id' ) );
		$uses    = self::uses_by_keyword( $ids );
		$history = self::positions_history();

		$out = [];
		foreach ( $rows as $row ) {
			$id     = (int) ( $row['id'] ?? 0 );
			$key    = (string) ( $row['phrase_key'] ?? '' );
			$series = $history[ $key ] ?? [
				'dates' => [],
				'ranks' => [],
			];
			$usages = self::attach_history( $uses[ $id ] ?? [], $series );
			$out[]  = [
				'id'      => $id,
				'key'     => $key,
				'label'   => (string) ( $row['phrase'] ?? $key ),
				'topic'   => (string) ( $row['topic'] ?? '' ),
				'use'     => (int) ( $row['use_count'] ?? count( $usages ) ),
				'uses'    => $usages,
				'dates'   => $series['dates'],
				'tracked' => isset( $history[ $key ] ),
				'updated' => (string) ( $row['updated_at'] ?? '' ),
			];
		}

		return [
			'items' => $out,
			'total' => $total,
		];
	}

	/**
	 * @return list<string>
	 */
	public static function list_topics(): array {
		return Topics::all();
	}

	/**
	 * Keyword counts keyed by topic name. Empty-topic count under `__none__`.
	 *
	 * @return array<string, int>
	 */
	public static function topic_counts(): array {
		global $wpdb;

		if ( ! Schema::tables_exist() ) {
			return [];
		}
		Schema::ensure_topic_column();

		$table = Schema::keywords_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT topic, COUNT(*) AS cnt FROM {$table} GROUP BY topic",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [
			'__none__' => 0,
		];
		foreach ( $rows as $row ) {
			$name = Bank::normalize_topic( (string) ( $row['topic'] ?? '' ) );
			$cnt  = (int) ( $row['cnt'] ?? 0 );
			if ( '' === $name ) {
				$out['__none__'] += $cnt;
				continue;
			}
			$out[ $name ] = $cnt;
		}

		return $out;
	}

	/**
	 * @return array{at: string, keywords: int, uses: int}|null
	 */
	public static function last_scan(): ?array {
		$saved = get_option( Schema::OPTION_LAST_SCAN, [] );
		if ( ! is_array( $saved ) || empty( $saved['at'] ) ) {
			return null;
		}

		return [
			'at'       => (string) $saved['at'],
			'keywords' => (int) ( $saved['keywords'] ?? 0 ),
			'uses'     => (int) ( $saved['uses'] ?? 0 ),
		];
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, list<array<string, mixed>>>
	 */
	private static function uses_by_keyword( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( $ids ) );
		if ( [] === $ids ) {
			return [];
		}

		$table = Schema::uses_table();
		$in    = implode( ',', array_map( 'intval', $ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE keyword_id IN ({$in}) ORDER BY title ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			$kid  = (int) ( $row['keyword_id'] ?? 0 );
			$kind = (string) ( $row['object_kind'] ?? 'post' );
			$oid  = (int) ( $row['object_id'] ?? 0 );
			$type = (string) ( $row['object_type'] ?? '' );
			$edit = '';
			if ( 'term' === $kind && $oid > 0 ) {
				$link = get_edit_term_link( $oid, $type );
				$edit = is_string( $link ) ? $link : '';
			} elseif ( $oid > 0 ) {
				$edit = InventoryPage::url_for_post( $oid );
			}

			$out[ $kid ][] = [
				'kind'     => $kind,
				'id'       => $oid,
				'type'     => $type,
				'title'    => (string) ( $row['title'] ?? '' ),
				'url'      => (string) ( $row['url'] ?? '' ),
				'edit'     => $edit,
				'url_key'  => (string) ( $row['url_key'] ?? '' ),
				'path_key' => Target::path_key( (string) ( $row['url'] ?? '' ) ),
				'by_date'  => [],
			];
		}

		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $uses
	 * @param array{dates: list<string>, ranks: array<string, array<string, int>>} $series
	 * @return list<array<string, mixed>>
	 */
	private static function attach_history( array $uses, array $series ): array {
		$dates  = $series['dates'] ?? [];
		$ranks  = $series['ranks'] ?? [];
		$labels = $series['labels'] ?? [];
		$seen   = [];

		$links = $series['links'] ?? [];

		foreach ( $uses as &$use ) {
			$path            = (string) ( $use['path_key'] ?? '' );
			$seen[ $path ]   = true;
			$use['by_date']  = self::ranks_for_path( $path, $dates, $ranks );
			$use['position'] = self::latest_rank( $use['by_date'] );
			$live            = (string) ( $links[ $path ] ?? '' );
			if ( '' !== $live ) {
				$use['url'] = $live;
			}
		}
		unset( $use );

		foreach ( $labels as $path => $label ) {
			$path = (string) $path;
			if ( '' === $path || isset( $seen[ $path ] ) ) {
				continue;
			}
			$by_date = self::ranks_for_path( $path, $dates, $ranks );
			if ( null === self::latest_rank( $by_date ) ) {
				continue;
			}
			$uses[] = [
				'kind'     => 'serp',
				'id'       => 0,
				'type'     => '',
				'title'    => (string) $label,
				'url'      => (string) ( $links[ $path ] ?? '' ),
				'edit'     => '',
				'url_key'  => '',
				'path_key' => $path,
				'by_date'  => $by_date,
				'position' => self::latest_rank( $by_date ),
			];
		}

		return $uses;
	}

	/**
	 * @param list<string> $dates
	 * @param array<string, array<string, int>> $ranks
	 * @return array<string, int|null>
	 */
	private static function ranks_for_path( string $path, array $dates, array $ranks ): array {
		$by_date = [];
		foreach ( $dates as $date ) {
			$rank = $ranks[ $date ][ $path ] ?? null;
			$by_date[ $date ] = is_int( $rank ) ? $rank : null;
		}

		return $by_date;
	}

	/**
	 * @param array<string, int|null> $by_date
	 */
	private static function latest_rank( array $by_date ): ?int {
		$latest = null;
		foreach ( $by_date as $rank ) {
			if ( is_int( $rank ) ) {
				$latest = $rank;
			}
		}

		return $latest;
	}

	/**
	 * @return array<string, array{dates: list<string>, ranks: array<string, array<string, int>>}>
	 */
	private static function positions_history(): array {
		$out = [];

		if ( ! PositionsSchema::tables_exist() ) {
			return $out;
		}

		foreach ( PositionsRepository::list_check_snapshots() as $row ) {
			$query = FocusKeyphrases::normalize_key( (string) ( $row['query_text'] ?? '' ) );
			$at    = (string) ( $row['checked_at'] ?? '' );
			$date  = $at ? substr( $at, 0, 10 ) : '';
			if ( '' === $query || '' === $date ) {
				continue;
			}

			if ( ! isset( $out[ $query ] ) ) {
				$out[ $query ] = [
					'dates'  => [],
					'ranks'  => [],
					'labels' => [],
					'links'  => [],
				];
			}

			if ( ! in_array( $date, $out[ $query ]['dates'], true ) ) {
				$out[ $query ]['dates'][] = $date;
			}

			$out[ $query ]['ranks'][ $date ] = [];
			foreach ( Client::organic_from_payload( (string) ( $row['payload'] ?? '' ) ) as $hit ) {
				$link = (string) ( $hit['link'] ?? '' );
				$rank = (int) ( $hit['rank'] ?? 0 );
				if ( $rank < 1 || '' === $link ) {
					continue;
				}
				if ( empty( $hit['ours'] ) && ! Target::matches_url( $link ) ) {
					continue;
				}
				$path = Target::path_key( $link );
				if ( '' === $path ) {
					continue;
				}
				$existing = $out[ $query ]['ranks'][ $date ][ $path ] ?? 0;
				if ( 0 === $existing || $rank < $existing ) {
					$out[ $query ]['ranks'][ $date ][ $path ] = $rank;
				}
				if ( ! isset( $out[ $query ]['labels'][ $path ] ) ) {
					$title = trim( (string) ( $hit['title'] ?? '' ) );
					$out[ $query ]['labels'][ $path ] = '' !== $title ? $title : Target::link_label( $link );
				}
				if ( ! isset( $out[ $query ]['links'][ $path ] ) ) {
					$out[ $query ]['links'][ $path ] = $link;
				}
			}
		}

		return $out;
	}
}
