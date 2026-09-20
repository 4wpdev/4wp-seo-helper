<?php
/**
 * Import Google Trends batches.
 */

namespace Forwp\SeoHelper\Trends;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Importer {
	public const PREVIEW_TRANSIENT = 'forwp_trends_preview_';

	/**
	 * @param array{rows: list<array<string, mixed>>, snapshot_hint: string} $parsed
	 * @return array{new_keys: int, existing_keys: int, snapshots: int, keys: int}
	 */
	public static function summarize( array $parsed ): array {
		$pairs    = [];
		$existing = 0;

		foreach ( $parsed['rows'] as $row ) {
			$key = $row['query'] . "\n" . $row['region'];
			if ( isset( $pairs[ $key ] ) ) {
				continue;
			}
			$pairs[ $key ] = true;
			if ( Repository::key_id( $row['query'], $row['region'] ) > 0 ) {
				++$existing;
			}
		}

		$total = count( $pairs );

		return [
			'new_keys'      => max( 0, $total - $existing ),
			'existing_keys' => $existing,
			'snapshots'     => count( $parsed['rows'] ),
			'keys'          => $total,
		];
	}

	/**
	 * @param array{rows: list<array<string, mixed>>, snapshot_hint: string} $parsed
	 * @return array{batch_id: int, summary: array<string, int>}
	 */
	public static function import( array $parsed, string $filename ): array {
		global $wpdb;

		$summary = self::summarize( $parsed );
		$now     = current_time( 'mysql' );
		$batches = Schema::batches_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$batches,
			[
				'filename'      => substr( $filename, 0, 255 ),
				'source'        => 'google_trends',
				'snapshot_hint' => (string) ( $parsed['snapshot_hint'] ?? '' ),
				'rows_count'    => (int) $summary['snapshots'],
				'new_keys'      => (int) $summary['new_keys'],
				'created_at'    => $now,
			],
			[ '%s', '%s', '%s', '%d', '%d', '%s' ]
		);

		$batch_id = (int) $wpdb->insert_id;
		$new_keys = 0;

		foreach ( $parsed['rows'] as $row ) {
			$key_id = Repository::key_id( $row['query'], $row['region'] );
			if ( $key_id < 1 ) {
				$key_id = Repository::insert_key( $row['query'], $row['region'], (string) ( $row['language'] ?? '' ) );
				++$new_keys;
			} elseif ( '' !== (string) ( $row['language'] ?? '' ) ) {
				Repository::maybe_set_language( $key_id, (string) $row['language'] );
			}

			Repository::insert_snapshot(
				$key_id,
				$batch_id,
				(string) $row['snapshot'],
				(float) $row['interest'],
				is_array( $row['payload'] ?? null ) ? $row['payload'] : null
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$batches,
			[ 'new_keys' => $new_keys ],
			[ 'id' => $batch_id ],
			[ '%d' ],
			[ '%d' ]
		);

		$summary['new_keys'] = $new_keys;
		$summary['batch_id'] = $batch_id;

		return [
			'batch_id' => $batch_id,
			'summary'  => $summary,
		];
	}

	public static function delete_batch( int $batch_id ): bool {
		return Repository::delete_batch( $batch_id );
	}
}
