<?php
/**
 * Parse Google Trends JSON (rows or interest_over_time).
 */

namespace Forwp\SeoHelper\Trends;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Parser {
	/**
	 * @return array{rows: list<array<string, mixed>>, snapshot_hint: string}|\WP_Error
	 */
	public static function parse_file( string $raw, string $filename ): array|\WP_Error {
		if ( preg_match( '/\.csv$/i', $filename ) || self::looks_like_csv( $raw ) ) {
			return self::parse_csv( $raw, $filename );
		}

		return self::parse( $raw );
	}

	/**
	 * Google Trends CSV:
	 * - related queries: query, search interest, increase percent
	 * - time series: Time + one column per query (daily interest → monthly snapshots)
	 * Filename often includes geo + date range, e.g. …_Worldwide_20250918-2206_20260918-2206.csv
	 *
	 * @return array{rows: list<array<string, mixed>>, snapshot_hint: string}|\WP_Error
	 */
	public static function parse_csv( string $raw, string $filename ): array|\WP_Error {
		$raw  = preg_replace( '/^\xEF\xBB\xBF/', '', $raw ) ?? $raw;
		$meta = self::meta_from_filename( $filename );
		$table = self::csv_table( $raw );
		if ( is_wp_error( $table ) ) {
			return $table;
		}

		if ( self::is_time_series_header( $table['header'] ) ) {
			return self::rows_from_time_series( $table['header'], $table['body'], $meta );
		}

		return self::rows_from_related_queries( $table['header'], $table['body'], $meta );
	}

	/**
	 * @return array{header: list<string>, body: list<list<string>>}|\WP_Error
	 */
	private static function csv_table( string $raw ): array|\WP_Error {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		if ( ! is_array( $lines ) ) {
			return new \WP_Error( 'forwp_trends_csv', __( 'Could not read CSV.', '4wp-seo-helper' ) );
		}

		$header = null;
		$body   = [];

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$cells = str_getcsv( $line, ',', '"', '\\' );
			$cells = array_map(
				static function ( $cell ) {
					return trim( (string) $cell );
				},
				$cells
			);
			if ( null === $header ) {
				$candidate = array_map( 'strtolower', $cells );
				if ( ! self::is_csv_header( $candidate ) ) {
					continue;
				}
				$header = $candidate;
				continue;
			}
			$body[] = $cells;
		}

		if ( ! is_array( $header ) || empty( $header ) ) {
			return new \WP_Error( 'forwp_trends_csv', __( 'Could not read CSV.', '4wp-seo-helper' ) );
		}

		return [
			'header' => $header,
			'body'   => $body,
		];
	}

	/**
	 * @param list<string> $header
	 */
	private static function is_time_series_header( array $header ): bool {
		$first = $header[0] ?? '';
		if ( ! in_array( $first, [ 'time', 'date', 'week', 'day', 'month' ], true ) ) {
			return false;
		}

		return ! in_array( 'query', $header, true );
	}

	/**
	 * @param list<string> $header
	 */
	private static function is_csv_header( array $header ): bool {
		if ( self::is_time_series_header( $header ) ) {
			return true;
		}

		return in_array( 'query', $header, true )
			|| in_array( 'term', $header, true )
			|| in_array( 'keyword', $header, true );
	}

	/**
	 * @param list<string>             $header
	 * @param list<list<string>>       $body
	 * @param array{region: string, snapshot: string} $meta
	 * @return array{rows: list<array<string, mixed>>, snapshot_hint: string}|\WP_Error
	 */
	private static function rows_from_related_queries( array $header, array $body, array $meta ): array|\WP_Error {
		$region   = $meta['region'];
		$snapshot = $meta['snapshot'];
		$rows     = [];

		foreach ( $body as $cells ) {
			$assoc = [];
			foreach ( $header as $i => $key ) {
				$assoc[ $key ] = $cells[ $i ] ?? '';
			}

			$query = self::normalize_query( (string) ( $assoc['query'] ?? $assoc['term'] ?? $assoc['keyword'] ?? '' ) );
			if ( '' === $query ) {
				continue;
			}

			$interest = $assoc['search interest'] ?? $assoc['interest'] ?? $assoc['value'] ?? '';
			$increase = $assoc['increase percent'] ?? $assoc['increase'] ?? '';
			$row_geo  = self::normalize_region( (string) ( $assoc['region'] ?? $assoc['geo'] ?? $region ) );
			$label    = self::normalize_snapshot( (string) ( $assoc['snapshot'] ?? $assoc['date'] ?? $snapshot ) );

			if ( '' === $row_geo || '' === $label ) {
				continue;
			}

			$rows[] = [
				'query'    => $query,
				'region'   => $row_geo,
				'language' => self::normalize_language( (string) ( $assoc['language'] ?? $assoc['lang'] ?? '' ) ),
				'snapshot' => $label,
				'interest' => (float) $interest,
				'payload'  => [
					'increase_percent' => self::parse_increase( (string) $increase ),
					'increase_raw'     => trim( (string) $increase ),
				],
			];
		}

		if ( empty( $rows ) ) {
			return new \WP_Error(
				'forwp_trends_empty',
				__( 'No Google Trends rows in this CSV. Need a query column and search interest, or a Time column with query headers.', '4wp-seo-helper' )
			);
		}

		return [
			'rows'          => $rows,
			'snapshot_hint' => $snapshot,
		];
	}

	/**
	 * Daily/weekly series → one snapshot per query+month (average interest).
	 *
	 * @param list<string>             $header
	 * @param list<list<string>>       $body
	 * @param array{region: string, snapshot: string} $meta
	 * @return array{rows: list<array<string, mixed>>, snapshot_hint: string}|\WP_Error
	 */
	private static function rows_from_time_series( array $header, array $body, array $meta ): array|\WP_Error {
		$queries = [];
		foreach ( $header as $i => $name ) {
			if ( 0 === $i ) {
				continue;
			}
			$query = self::normalize_query( $name );
			if ( '' !== $query ) {
				$queries[ $i ] = $query;
			}
		}

		if ( empty( $queries ) ) {
			return new \WP_Error(
				'forwp_trends_empty',
				__( 'Time series CSV needs query names in the header after Time.', '4wp-seo-helper' )
			);
		}

		$buckets = [];
		foreach ( $body as $cells ) {
			$label = self::normalize_snapshot( (string) ( $cells[0] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			foreach ( $queries as $i => $query ) {
				$raw = $cells[ $i ] ?? '';
				if ( '' === $raw || ! is_numeric( $raw ) ) {
					continue;
				}
				$buckets[ $query ][ $label ][] = (float) $raw;
			}
		}

		$region = self::normalize_region( $meta['region'] );
		$rows   = [];
		$months = [];

		foreach ( $buckets as $query => $by_month ) {
			foreach ( $by_month as $label => $values ) {
				$months[ $label ] = true;
				$rows[]           = [
					'query'    => $query,
					'region'   => $region,
					'language' => '',
					'snapshot' => $label,
					'interest' => round( array_sum( $values ) / max( 1, count( $values ) ), 1 ),
					'payload'  => [
						'source' => 'time_series',
						'days'   => count( $values ),
					],
				];
			}
		}

		if ( empty( $rows ) ) {
			return new \WP_Error(
				'forwp_trends_empty',
				__( 'No dated interest values in this time series CSV.', '4wp-seo-helper' )
			);
		}

		$month_list = array_keys( $months );
		sort( $month_list );

		return [
			'rows'          => $rows,
			'snapshot_hint' => (string) end( $month_list ),
		];
	}

	/**
	 * @return array{region: string, snapshot: string}
	 */
	public static function meta_from_filename( string $filename ): array {
		$base = basename( $filename );
		$base = preg_replace( '/\.csv$/i', '', $base ) ?? $base;

		$region   = 'WORLDWIDE';
		$snapshot = '';

		if ( preg_match_all( '/(\d{8})/', $base, $dates ) && ! empty( $dates[1] ) ) {
			$last     = (string) end( $dates[1] );
			$snapshot = self::normalize_snapshot( substr( $last, 0, 4 ) . '-' . substr( $last, 4, 2 ) );
		}

		if ( preg_match( '/_(worldwide|global)_/i', $base ) ) {
			$region = 'WORLDWIDE';
		} elseif ( preg_match( '/_([A-Z]{2}(?:-[A-Z0-9]+)?)_/i', $base, $m ) ) {
			$region = self::normalize_region( $m[1] );
		}

		if ( '' === $snapshot ) {
			$snapshot = gmdate( 'Y-m' );
		}

		return [
			'region'   => $region,
			'snapshot' => $snapshot,
		];
	}

	private static function parse_increase( string $raw ): ?float {
		$raw = trim( $raw );
		if ( '' === $raw || 0 === strcasecmp( $raw, 'breakout' ) ) {
			return null;
		}
		$raw = str_replace( [ '%', '+' ], '', $raw );
		if ( ! is_numeric( $raw ) ) {
			return null;
		}

		return (float) $raw;
	}

	private static function looks_like_csv( string $raw ): bool {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$first = trim( (string) ( $lines[0] ?? '' ) );
		if ( false === strpos( $first, ',' ) ) {
			return false;
		}

		return false !== stripos( $first, 'query' )
			|| (bool) preg_match( '/^["\']?(time|date|week|day|month)["\']?,/i', $first );
	}

	/**
	 * @return array{rows: list<array<string, mixed>>, snapshot_hint: string}|\WP_Error
	 */
	public static function parse( string $raw ): array|\WP_Error {
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'forwp_trends_json',
				__( 'Not valid JSON.', '4wp-seo-helper' )
			);
		}

		if ( isset( $decoded['source'] ) && is_string( $decoded['source'] ) && '' !== $decoded['source'] ) {
			$source = strtolower( $decoded['source'] );
			if ( 'google_trends' !== $source && 'trends' !== $source ) {
				return new \WP_Error(
					'forwp_trends_source',
					__( 'Only Google Trends JSON is supported for now.', '4wp-seo-helper' )
				);
			}
		}

		$file_geo      = self::string_field( $decoded, [ 'geo', 'region', 'geo_scope' ] );
		$file_lang     = self::string_field( $decoded, [ 'language', 'lang' ] );
		$file_snapshot = self::normalize_snapshot(
			self::string_field( $decoded, [ 'snapshot', 'date', 'collection_date', 'period' ] )
		);

		$rows = [];

		if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
			foreach ( $decoded['rows'] as $row ) {
				if ( is_array( $row ) ) {
					$rows = array_merge( $rows, self::expand_row( $row, $file_geo, $file_lang, $file_snapshot ) );
				}
			}
		} elseif ( isset( $decoded['items'] ) && is_array( $decoded['items'] ) ) {
			foreach ( $decoded['items'] as $row ) {
				if ( is_array( $row ) ) {
					$rows = array_merge( $rows, self::expand_row( $row, $file_geo, $file_lang, $file_snapshot ) );
				}
			}
		} elseif ( isset( $decoded['queries'] ) && is_array( $decoded['queries'] ) ) {
			foreach ( $decoded['queries'] as $row ) {
				if ( is_array( $row ) ) {
					$rows = array_merge( $rows, self::expand_row( $row, $file_geo, $file_lang, $file_snapshot ) );
				}
			}
		} elseif ( self::is_list( $decoded ) ) {
			foreach ( $decoded as $row ) {
				if ( is_array( $row ) ) {
					$rows = array_merge( $rows, self::expand_row( $row, $file_geo, $file_lang, $file_snapshot ) );
				}
			}
		} elseif ( isset( $decoded['query'] ) || isset( $decoded['keyword'] ) ) {
			$rows = self::expand_row( $decoded, $file_geo, $file_lang, $file_snapshot );
		}

		$clean = [];
		foreach ( $rows as $row ) {
			$query  = self::normalize_query( (string) ( $row['query'] ?? '' ) );
			$region = self::normalize_region( (string) ( $row['region'] ?? '' ) );
			$label  = self::normalize_snapshot( (string) ( $row['snapshot'] ?? $file_snapshot ) );
			if ( '' === $query || '' === $region || '' === $label ) {
				continue;
			}
			$clean[] = [
				'query'    => $query,
				'region'   => $region,
				'language' => self::normalize_language( (string) ( $row['language'] ?? $file_lang ) ),
				'snapshot' => $label,
				'interest' => (float) ( $row['interest'] ?? 0 ),
			];
		}

		if ( empty( $clean ) ) {
			return new \WP_Error(
				'forwp_trends_empty',
				__( 'No Google Trends rows found. Need query, region, snapshot date, and interest.', '4wp-seo-helper' )
			);
		}

		return [
			'rows'          => $clean,
			'snapshot_hint' => $file_snapshot,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return list<array<string, mixed>>
	 */
	private static function expand_row( array $row, string $file_geo, string $file_lang, string $file_snapshot ): array {
		$query  = self::normalize_query( self::string_field( $row, [ 'query', 'keyword', 'term' ] ) );
		$region = self::normalize_region( self::string_field( $row, [ 'region', 'geo', 'geo_code' ] ) );
		if ( '' === $region ) {
			$region = self::normalize_region( $file_geo );
		}
		$lang = self::normalize_language( self::string_field( $row, [ 'language', 'lang' ] ) );
		if ( '' === $lang ) {
			$lang = self::normalize_language( $file_lang );
		}

		$series = $row['interest_over_time'] ?? $row['timeline'] ?? null;
		if ( is_array( $series ) && ! empty( $series ) ) {
			$out = [];
			foreach ( $series as $point ) {
				if ( ! is_array( $point ) ) {
					continue;
				}
				$label = self::normalize_snapshot(
					self::string_field( $point, [ 'date', 'snapshot', 'time', 'formattedTime', 'period' ] )
				);
				$value = $point['value'] ?? $point['interest'] ?? null;
				if ( is_array( $value ) ) {
					$value = $value[0] ?? 0;
				}
				if ( '' === $query || '' === $region || '' === $label ) {
					continue;
				}
				$out[] = [
					'query'    => $query,
					'region'   => $region,
					'language' => $lang,
					'snapshot' => $label,
					'interest' => (float) $value,
				];
			}
			if ( ! empty( $out ) ) {
				return $out;
			}
		}

		$interest = $row['interest'] ?? $row['value'] ?? $row['score'] ?? null;
		if ( is_array( $interest ) ) {
			$interest = $interest[0] ?? 0;
		}

		$snapshot = self::normalize_snapshot(
			self::string_field( $row, [ 'snapshot', 'date', 'period', 'collection_date', 'timeframe' ] )
		);
		if ( '' === $snapshot ) {
			$snapshot = $file_snapshot;
		}

		if ( '' === $query || '' === $region || '' === $snapshot || null === $interest ) {
			return [];
		}

		return [
			[
				'query'    => $query,
				'region'   => $region,
				'language' => $lang,
				'snapshot' => $snapshot,
				'interest' => (float) $interest,
			],
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string>         $keys
	 */
	private static function string_field( array $data, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				return trim( (string) $data[ $key ] );
			}
		}

		return '';
	}

	public static function normalize_query( string $query ): string {
		$query = strtolower( trim( wp_strip_all_tags( $query ) ) );
		$query = preg_replace( '/\s+/', ' ', $query ) ?? $query;

		return $query;
	}

	public static function normalize_region( string $region ): string {
		return strtoupper( trim( $region ) );
	}

	public static function normalize_language( string $lang ): string {
		$lang = strtolower( trim( $lang ) );
		if ( strlen( $lang ) > 2 ) {
			$lang = substr( $lang, 0, 2 );
		}

		return $lang;
	}

	public static function normalize_snapshot( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^(\d{4})[-.\/](\d{1,2})/', $raw, $m ) ) {
			return sprintf( '%04d-%02d', (int) $m[1], (int) $m[2] );
		}

		if ( preg_match( '/^(\d{1,2})$/', $raw, $m ) ) {
			$month = (int) $m[1];
			if ( $month >= 1 && $month <= 12 ) {
				return sprintf( '%04d-%02d', (int) gmdate( 'Y' ), $month );
			}
		}

		$ts = strtotime( $raw );
		if ( false !== $ts ) {
			return gmdate( 'Y-m', $ts );
		}

		return sanitize_title( $raw );
	}

	/**
	 * @param array<mixed> $data
	 */
	private static function is_list( array $data ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $data );
		}

		return array_keys( $data ) === range( 0, count( $data ) - 1 );
	}
}
