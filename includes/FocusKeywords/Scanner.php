<?php
/**
 * Scan post/term focus keyphrases into the stored tables.
 */

namespace Forwp\SeoHelper\FocusKeywords;

use Forwp\SeoHelper\Inventory\PostTypeDiscovery;
use Forwp\SeoHelper\SeoMeta\FocusKeyphrases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scanner {
	/**
	 * @return array{keywords: int, uses: int}
	 */
	public static function run(): array {
		if ( ! Schema::tables_exist() ) {
			Schema::install();
		}

		$groups = [];
		self::collect_posts( $groups );
		self::collect_terms( $groups );

		return self::persist( $groups );
	}

	/**
	 * @param array<string, array{key: string, label: string, uses: array<string, array<string, mixed>>}> $groups
	 */
	private static function collect_posts( array &$groups ): void {
		global $wpdb;

		$types = PostTypeDiscovery::get_slugs();
		if ( [] === $types ) {
			return;
		}

		$type_in = implode(
			',',
			array_map(
				static function ( string $type ) use ( $wpdb ): string {
					return $wpdb->prepare( '%s', $type );
				},
				$types
			)
		);

		$sql = $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type,
				MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS forwp,
				MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS yoast,
				MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS aioseo
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm
				ON pm.post_id = p.ID AND pm.meta_key IN (%s, %s, %s)
			WHERE p.post_status = 'publish' AND p.post_type IN ({$type_in})
			GROUP BY p.ID",
			FocusKeyphrases::META_KEY,
			'_yoast_wpseo_focuskw',
			'_aioseo_keywords',
			FocusKeyphrases::META_KEY,
			'_yoast_wpseo_focuskw',
			'_aioseo_keywords'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$phrases = self::phrases_from_sources(
				(string) ( $row['forwp'] ?? '' ),
				(string) ( $row['yoast'] ?? '' ),
				(string) ( $row['aioseo'] ?? '' )
			);
			if ( [] === $phrases ) {
				continue;
			}

			$post_id = (int) ( $row['ID'] ?? 0 );
			$url     = $post_id > 0 ? (string) get_permalink( $post_id ) : '';
			$title   = (string) ( $row['post_title'] ?? '' );
			$type    = (string) ( $row['post_type'] ?? '' );
			$usage   = [
				'kind'  => 'post',
				'id'    => $post_id,
				'type'  => $type,
				'title' => $title,
				'url'   => $url,
				'ukey'  => self::url_key( $url ),
			];

			foreach ( $phrases as $phrase ) {
				self::add_use( $groups, $phrase, $usage );
			}
		}
	}

	/**
	 * @param array<string, array{key: string, label: string, uses: array<string, array<string, mixed>>}> $groups
	 */
	private static function collect_terms( array &$groups ): void {
		$seen = [];

		$yoast = get_option( 'wpseo_taxonomy_meta', [] );
		if ( is_array( $yoast ) ) {
			foreach ( $yoast as $taxonomy => $terms ) {
				if ( ! is_array( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term_id => $meta ) {
					$term_id = (int) $term_id;
					$kw      = is_array( $meta ) ? (string) ( $meta['wpseo_focuskw'] ?? '' ) : '';
					if ( '' === trim( $kw ) ) {
						continue;
					}
					self::add_term_phrases( $groups, $seen, (string) $taxonomy, $term_id, '', $kw );
				}
			}
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value <> ''",
				FocusKeyphrases::META_KEY
			),
			ARRAY_A
		);
		if ( ! is_array( $meta_rows ) ) {
			return;
		}

		foreach ( $meta_rows as $row ) {
			$term_id = (int) ( $row['term_id'] ?? 0 );
			$term    = $term_id > 0 ? get_term( $term_id ) : null;
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			self::add_term_phrases( $groups, $seen, $term->taxonomy, $term_id, (string) ( $row['meta_value'] ?? '' ), '' );
		}
	}

	/**
	 * @param array<string, array{key: string, label: string, uses: array<string, array<string, mixed>>}> $groups
	 * @param array<string, true> $seen
	 */
	private static function add_term_phrases( array &$groups, array &$seen, string $taxonomy, int $term_id, string $forwp, string $yoast ): void {
		if ( $term_id < 1 ) {
			return;
		}

		$dup = 'term:' . $term_id;
		if ( isset( $seen[ $dup ] ) && '' === $forwp ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			$term = get_term( $term_id );
		}
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		if ( '' === $forwp ) {
			$forwp = (string) get_term_meta( $term->term_id, FocusKeyphrases::META_KEY, true );
		}

		$phrases = self::phrases_from_sources( $forwp, $yoast, '' );
		if ( [] === $phrases ) {
			return;
		}

		$seen[ $dup ] = true;
		$link         = get_term_link( $term );
		$url          = is_wp_error( $link ) ? '' : (string) $link;
		$usage        = [
			'kind'  => 'term',
			'id'    => (int) $term->term_id,
			'type'  => (string) $term->taxonomy,
			'title' => (string) $term->name,
			'url'   => $url,
			'ukey'  => self::url_key( $url ),
		];

		foreach ( $phrases as $phrase ) {
			self::add_use( $groups, $phrase, $usage );
		}
	}

	/**
	 * @return list<string>
	 */
	private static function phrases_from_sources( string $forwp, string $yoast, string $aioseo ): array {
		$forwp = trim( $forwp );
		if ( '' !== $forwp ) {
			return FocusKeyphrases::parse( $forwp );
		}

		$yoast = trim( $yoast );
		if ( '' !== $yoast ) {
			return FocusKeyphrases::parse( $yoast );
		}

		$aioseo = trim( $aioseo );
		if ( '' !== $aioseo ) {
			return FocusKeyphrases::parse( str_replace( ',', "\n", $aioseo ) );
		}

		return [];
	}

	/**
	 * @param array<string, array{key: string, label: string, uses: array<string, array<string, mixed>>}> $groups
	 * @param array{kind: string, id: int, type: string, title: string, url: string, ukey: string} $usage
	 */
	private static function add_use( array &$groups, string $phrase, array $usage ): void {
		$phrase = trim( $phrase );
		$key    = FocusKeyphrases::normalize_key( $phrase );
		if ( '' === $key ) {
			return;
		}

		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = [
				'key'   => $key,
				'label' => $phrase,
				'uses'  => [],
			];
		}

		$dup = $usage['kind'] . ':' . (int) $usage['id'];
		if ( isset( $groups[ $key ]['uses'][ $dup ] ) ) {
			return;
		}

		$groups[ $key ]['uses'][ $dup ] = $usage;
	}

	/**
	 * Persist scan into the bank.
	 *
	 * Rebuilds usage rows from the site. Keeps bank phrases that are not
	 * currently used as focus (imports / manual adds) with use_count = 0.
	 *
	 * @param array<string, array{key: string, label: string, uses: array<string, array<string, mixed>>}> $groups
	 * @return array{keywords: int, uses: int}
	 */
	private static function persist( array $groups ): array {
		global $wpdb;

		Schema::ensure_topic_column();

		$keywords = Schema::keywords_table();
		$uses     = Schema::uses_table();
		$now      = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$uses}" );

		// Reset usage counts; bank rows stay.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$keywords} SET use_count = 0" );

		$use_total = 0;

		foreach ( $groups as $row ) {
			$count = count( $row['uses'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$keyword_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$keywords} WHERE phrase_key = %s LIMIT 1",
					$row['key']
				)
			);

			if ( $keyword_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$keywords,
					[
						'phrase'     => $row['label'],
						'use_count'  => $count,
						'updated_at' => $now,
					],
					[ 'id' => $keyword_id ],
					[ '%s', '%d', '%s' ],
					[ '%d' ]
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$keywords,
					[
						'phrase_key' => $row['key'],
						'phrase'     => $row['label'],
						'topic'      => '',
						'use_count'  => $count,
						'updated_at' => $now,
					],
					[ '%s', '%s', '%s', '%d', '%s' ]
				);
				$keyword_id = (int) $wpdb->insert_id;
			}

			if ( $keyword_id < 1 ) {
				continue;
			}

			foreach ( $row['uses'] as $usage ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$uses,
					[
						'keyword_id'  => $keyword_id,
						'object_kind' => (string) $usage['kind'],
						'object_id'   => (int) $usage['id'],
						'object_type' => (string) $usage['type'],
						'title'       => (string) $usage['title'],
						'url'         => (string) $usage['url'],
						'url_key'     => (string) $usage['ukey'],
					],
					[ '%d', '%s', '%d', '%s', '%s', '%s', '%s' ]
				);
				++$use_total;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bank_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$keywords}" );

		update_option(
			Schema::OPTION_LAST_SCAN,
			[
				'at'       => $now,
				'keywords' => $bank_total,
				'uses'     => $use_total,
			],
			false
		);

		return [
			'keywords' => $bank_total,
			'uses'     => $use_total,
		];
	}

	public static function url_key( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return untrailingslashit( strtolower( $url ) );
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$host = preg_replace( '/^www\./', '', $host ) ?? $host;
		$path = (string) ( $parts['path'] ?? '/' );
		$path = untrailingslashit( $path );
		if ( '' === $path ) {
			$path = '/';
		}

		return $host . $path;
	}
}
