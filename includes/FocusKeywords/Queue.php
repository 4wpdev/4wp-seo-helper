<?php
/**
 * P1–P3 lanes for unique focus keywords (same idea as inventory priority).
 */

namespace Forwp\SeoHelper\FocusKeywords;

use Forwp\SeoHelper\Inventory\PriorityQueue;
use Forwp\SeoHelper\SeoMeta\FocusKeyphrases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Queue {
	public const OPTION_KEY = 'forwp_seo_helper_focus_keyword_priority';

	/**
	 * @return array{1: list<string>, 2: list<string>, 3: list<string>}
	 */
	public static function get_lanes(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		$lanes = [
			'1' => [],
			'2' => [],
			'3' => [],
		];
		$seen = [];

		foreach ( PriorityQueue::LANE_IDS as $lane_id ) {
			foreach ( (array) ( $saved[ $lane_id ] ?? [] ) as $key ) {
				$key = FocusKeyphrases::normalize_key( (string) $key );
				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}
				$lanes[ $lane_id ][] = $key;
				$seen[ $key ]        = true;
			}
		}

		return $lanes;
	}

	/**
	 * @param array{1?: list<string>, 2?: list<string>, 3?: list<string>} $lanes
	 * @return array{1: list<string>, 2: list<string>, 3: list<string>}
	 */
	public static function set_lanes( array $lanes ): array {
		$normalized = [
			'1' => [],
			'2' => [],
			'3' => [],
		];
		$seen = [];

		foreach ( PriorityQueue::LANE_IDS as $lane_id ) {
			foreach ( (array) ( $lanes[ $lane_id ] ?? [] ) as $key ) {
				$key = FocusKeyphrases::normalize_key( (string) $key );
				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}
				$normalized[ $lane_id ][] = $key;
				$seen[ $key ]             = true;
			}
		}

		update_option( self::OPTION_KEY, $normalized, false );

		return $normalized;
	}

	public static function assign( string $key, ?int $priority ): array {
		$key   = FocusKeyphrases::normalize_key( $key );
		$lanes = self::get_lanes();

		foreach ( PriorityQueue::LANE_IDS as $lane_id ) {
			$lanes[ $lane_id ] = array_values(
				array_filter(
					$lanes[ $lane_id ],
					static function ( string $item ) use ( $key ): bool {
						return $item !== $key;
					}
				)
			);
		}

		if ( null !== $priority && $priority >= 1 && $priority <= 3 && '' !== $key ) {
			$lanes[ (string) $priority ][] = $key;
		}

		return self::set_lanes( $lanes );
	}

	/**
	 * @param array<string|int, list<string>> $visible_lanes
	 * @param list<string>                    $visible_ids
	 * @return array{1: list<string>, 2: list<string>, 3: list<string>}
	 */
	public static function merge_visible_lanes( array $visible_lanes, array $visible_ids ): array {
		$stored      = self::get_lanes();
		$visible_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $id ): string {
							return FocusKeyphrases::normalize_key( (string) $id );
						},
						$visible_ids
					)
				)
			)
		);
		$visible_set = array_fill_keys( $visible_ids, true );

		$incoming_by_lane = [
			'1' => [],
			'2' => [],
			'3' => [],
		];
		$seen_incoming = [];

		foreach ( PriorityQueue::LANE_IDS as $lane_id ) {
			foreach ( (array) ( $visible_lanes[ $lane_id ] ?? [] ) as $id ) {
				$id = FocusKeyphrases::normalize_key( (string) $id );
				if ( '' === $id || ! isset( $visible_set[ $id ] ) || isset( $seen_incoming[ $id ] ) ) {
					continue;
				}
				$incoming_by_lane[ $lane_id ][] = $id;
				$seen_incoming[ $id ]           = true;
			}
		}

		$merged = [
			'1' => [],
			'2' => [],
			'3' => [],
		];

		foreach ( PriorityQueue::LANE_IDS as $lane_id ) {
			$stripped  = [];
			$insert_at = null;

			foreach ( $stored[ $lane_id ] as $id ) {
				if ( isset( $visible_set[ $id ] ) ) {
					if ( null === $insert_at ) {
						$insert_at = count( $stripped );
					}
					continue;
				}
				$stripped[] = $id;
			}

			$incoming = $incoming_by_lane[ $lane_id ];
			if ( [] === $incoming ) {
				$merged[ $lane_id ] = $stripped;
				continue;
			}

			if ( null === $insert_at ) {
				$insert_at = 0;
			}

			array_splice( $stripped, $insert_at, 0, $incoming );
			$merged[ $lane_id ] = $stripped;
		}

		return self::set_lanes( $merged );
	}

	public static function get_priority( string $key ): int {
		$key = FocusKeyphrases::normalize_key( $key );
		if ( '' === $key ) {
			return 0;
		}

		foreach ( self::get_lanes() as $lane_id => $keys ) {
			if ( in_array( $key, $keys, true ) ) {
				return (int) $lane_id;
			}
		}

		return 0;
	}

	/**
	 * @return array{1: int, 2: int, 3: int}
	 */
	public static function get_counts(): array {
		$lanes = self::get_lanes();

		return [
			'1' => count( $lanes['1'] ),
			'2' => count( $lanes['2'] ),
			'3' => count( $lanes['3'] ),
		];
	}
}
