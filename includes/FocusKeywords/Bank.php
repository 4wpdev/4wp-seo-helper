<?php
/**
 * Keyword bank writes: manual add + CSV import (phrase only).
 */

namespace Forwp\SeoHelper\FocusKeywords;

use Forwp\SeoHelper\SeoMeta\FocusKeyphrases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bank {
	/**
	 * Upsert phrases into the bank without touching usage rows.
	 *
	 * @param list<string> $phrases
	 * @return array{added: int, updated: int, skipped: int}
	 */
	public static function upsert_phrases( array $phrases, string $topic = '' ): array {
		global $wpdb;

		if ( ! Schema::tables_exist() ) {
			Schema::install();
		} else {
			Schema::ensure_topic_column();
		}

		$table   = Schema::keywords_table();
		$now     = current_time( 'mysql' );
		$topic   = self::normalize_topic( $topic );
		$added   = 0;
		$updated = 0;
		$skipped = 0;
		$seen    = [];

		foreach ( $phrases as $raw ) {
			$phrase = trim( (string) $raw );
			$key    = FocusKeyphrases::normalize_key( $phrase );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				++$skipped;
				continue;
			}
			$seen[ $key ] = true;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE phrase_key = %s LIMIT 1",
					$key
				)
			);

			if ( $existing_id > 0 ) {
				$data = [
					'phrase'     => $phrase,
					'updated_at' => $now,
				];
				$format = [ '%s', '%s' ];
				if ( '' !== $topic ) {
					$data['topic'] = $topic;
					$format[]      = '%s';
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					$data,
					[ 'id' => $existing_id ],
					$format,
					[ '%d' ]
				);
				++$updated;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->insert(
				$table,
				[
					'phrase_key' => $key,
					'phrase'     => $phrase,
					'topic'      => $topic,
					'use_count'  => 0,
					'updated_at' => $now,
				],
				[ '%s', '%s', '%s', '%d', '%s' ]
			);

			if ( false === $ok ) {
				++$skipped;
				continue;
			}
			++$added;
		}

		return [
			'added'   => $added,
			'updated' => $updated,
			'skipped' => $skipped,
		];
	}

	public static function normalize_topic( string $topic ): string {
		$topic = trim( sanitize_text_field( $topic ) );
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $topic, 0, 191 );
		}
		return substr( $topic, 0, 191 );
	}

	/**
	 * @param list<int> $ids
	 */
	public static function set_topic_for_ids( array $ids, string $topic ): int {
		global $wpdb;

		if ( ! Schema::tables_exist() ) {
			return 0;
		}
		Schema::ensure_topic_column();

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);
		if ( [] === $ids ) {
			return 0;
		}

		$topic    = self::normalize_topic( $topic );
		$table    = Schema::keywords_table();
		$now      = current_time( 'mysql' );
		$in       = implode( ',', $ids );
		$prepared = $wpdb->prepare(
			"UPDATE {$table} SET topic = %s, updated_at = %s WHERE id IN ({$in})",
			$topic,
			$now
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$updated = (int) $wpdb->query( $prepared );

		return max( 0, $updated );
	}

	/**
	 * Save per-row topic map: [ id => topic ].
	 *
	 * @param array<int|string, string> $map
	 */
	public static function save_topic_map( array $map ): int {
		global $wpdb;

		if ( ! Schema::tables_exist() || [] === $map ) {
			return 0;
		}
		Schema::ensure_topic_column();

		$table   = Schema::keywords_table();
		$now     = current_time( 'mysql' );
		$updated = 0;

		foreach ( $map as $id => $topic ) {
			$id = (int) $id;
			if ( $id < 1 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->update(
				$table,
				[
					'topic'      => self::normalize_topic( (string) $topic ),
					'updated_at' => $now,
				],
				[ 'id' => $id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			if ( false !== $ok ) {
				$updated += (int) $ok;
			}
		}

		return $updated;
	}

	/**
	 * Apply topic to every keyword in the bank.
	 */
	public static function set_topic_for_all( string $topic ): int {
		global $wpdb;

		if ( ! Schema::tables_exist() ) {
			return 0;
		}
		Schema::ensure_topic_column();

		$table = Schema::keywords_table();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET topic = %s, updated_at = %s",
				self::normalize_topic( $topic ),
				$now
			)
		);

		return max( 0, $updated );
	}

	/**
	 * Delete bank keywords and their usage rows.
	 *
	 * @param list<int> $ids
	 * @return int Deleted keyword count.
	 */
	public static function delete_ids( array $ids ): int {
		global $wpdb;

		if ( ! Schema::tables_exist() ) {
			return 0;
		}

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);
		if ( [] === $ids ) {
			return 0;
		}

		$keywords = Schema::keywords_table();
		$uses     = Schema::uses_table();
		$in       = implode( ',', $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$uses} WHERE keyword_id IN ({$in})" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = (int) $wpdb->query( "DELETE FROM {$keywords} WHERE id IN ({$in})" );

		return max( 0, $deleted );
	}

	/**
	 * Parse CSV / plain text into phrase list (first useful column only).
	 *
	 * @return list<string>
	 */
	public static function phrases_from_upload( string $path ): array {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw || '' === trim( $raw ) ) {
			return [];
		}

		// Strip UTF-8 BOM.
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}

		$lines = preg_split( '/\R/u', $raw ) ?: [];
		if ( [] === $lines ) {
			return [];
		}

		$delimiter = self::detect_delimiter( (string) $lines[0] );
		$header    = str_getcsv( (string) $lines[0], $delimiter, '"', '\\' );
		$col       = self::phrase_column_index( $header );
		$start     = null !== $col ? 1 : 0;
		if ( null === $col ) {
			$col = 0;
		}

		$phrases = [];
		$total   = count( $lines );
		for ( $i = $start; $i < $total; $i++ ) {
			$line = trim( (string) $lines[ $i ] );
			if ( '' === $line ) {
				continue;
			}
			$row = str_getcsv( $line, $delimiter, '"', '\\' );
			if ( ! is_array( $row ) || ! isset( $row[ $col ] ) ) {
				continue;
			}
			$phrase = trim( (string) $row[ $col ] );
			if ( '' === $phrase ) {
				continue;
			}
			// Skip accidental header repeats.
			if ( self::looks_like_header_cell( $phrase ) ) {
				continue;
			}
			$phrases[] = $phrase;
		}

		return $phrases;
	}

	/**
	 * @param list<string>|array<int, string|null> $header
	 */
	private static function phrase_column_index( array $header ): ?int {
		foreach ( $header as $i => $cell ) {
			$cell = FocusKeyphrases::normalize_key( (string) $cell );
			if ( '' === $cell ) {
				continue;
			}
			if (
				str_contains( $cell, 'ключов' )
				|| str_contains( $cell, 'keyword' )
				|| str_contains( $cell, 'keyphrase' )
				|| str_contains( $cell, 'query' )
				|| 'phrase' === $cell
			) {
				return (int) $i;
			}
		}

		return null;
	}

	private static function looks_like_header_cell( string $phrase ): bool {
		$key = FocusKeyphrases::normalize_key( $phrase );
		return str_contains( $key, 'ключов' )
			|| str_contains( $key, 'keyword' )
			|| str_contains( $key, 'keyphrase' );
	}

	private static function detect_delimiter( string $line ): string {
		$comma = substr_count( $line, ',' );
		$semi  = substr_count( $line, ';' );
		$tab   = substr_count( $line, "\t" );
		if ( $tab >= $comma && $tab >= $semi && $tab > 0 ) {
			return "\t";
		}
		if ( $semi > $comma ) {
			return ';';
		}
		return ',';
	}
}
