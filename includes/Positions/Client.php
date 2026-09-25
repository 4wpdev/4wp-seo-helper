<?php
/**
 * Serper Search API for one query + country.
 */

namespace Forwp\SeoHelper\Positions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Client {
	public const PAGE_SIZE     = 10;
	public const MAX_PAGES     = 5;
	public const DEFAULT_PAGES = 1;

	/**
	 * @return array{ok: bool, checked_at: string, http_code: int, organic_count: int, site_position: int|null, payload: string, error: string, target_property: string, target_host: string, pages_fetched: int}
	 */
	public static function check_query( string $query, string $gl, string $hl, int $pages = self::DEFAULT_PAGES, string $tbs = '' ): array {
		$checked_at = current_time( 'mysql' );
		$gl         = SerperLocales::normalize_gl( $gl );
		$hl         = SerperLocales::normalize_hl( $hl );
		$tbs        = self::normalize_tbs( $tbs );
		$pages      = self::normalize_pages( $pages );
		$target     = Target::snapshot();
		$base       = [
			'ok'              => false,
			'checked_at'      => $checked_at,
			'http_code'       => 0,
			'organic_count'   => 0,
			'site_position'   => null,
			'payload'         => '',
			'error'           => '',
			'provider'        => Settings::PROVIDER_SERPER,
			'gl'              => $gl,
			'hl'              => $hl,
			'tbs'             => $tbs,
			'target_property' => $target['property'],
			'target_host'     => $target['host'],
			'pages_fetched'   => 0,
			'depth'           => $pages * self::PAGE_SIZE,
		];

		$key = Settings::get_serper_key();
		if ( '' === $key ) {
			$base['error']   = __( 'Add a Serper API key first.', '4wp-seo-helper' );
			$base['payload'] = self::encode_payload( $query, $base, [], [], [], [] );
			return $base;
		}

		if ( '' === $target['property'] ) {
			$base['error']   = __( 'Select a Search Console property so we know which domain to find.', '4wp-seo-helper' );
			$base['payload'] = self::encode_payload( $query, $base, [], [], [], [] );
			return $base;
		}

		$organic          = [];
		$serper_pages     = [];
		$people_also_ask  = [];
		$related_searches = [];

		for ( $page = 1; $page <= $pages; $page++ ) {
			$fetched           = self::fetch_page( $key, $query, $gl, $hl, $page, $tbs );
			$base['http_code'] = $fetched['http_code'];
			$serper_pages[]    = $fetched['json'];
			$people_also_ask   = self::merge_people_also_ask( $people_also_ask, $fetched['json'] );
			$related_searches  = self::merge_related_searches( $related_searches, $fetched['json'] );

			if ( ! $fetched['ok'] ) {
				$base['error']             = $fetched['error'];
				$base['pages_fetched']     = $page;
				$base['organic_count']     = count( $organic );
				$base['site_position']     = self::site_position( $organic );
				$base['people_also_ask']   = $people_also_ask;
				$base['related_searches']  = $related_searches;
				$base['payload']           = self::encode_payload( $query, $base, $organic, $serper_pages, $people_also_ask, $related_searches );
				return $base;
			}

			$page_rows = self::normalize_organic( $fetched['organic'], $page );
			$organic   = array_merge( $organic, $page_rows );
			$found     = self::site_position( $organic );
			$base['pages_fetched'] = $page;
			$base['organic_count'] = count( $organic );
			$base['site_position'] = $found;

			if ( null !== $found ) {
				break;
			}
		}

		$base['ok']                 = true;
		$base['people_also_ask']    = $people_also_ask;
		$base['related_searches']   = $related_searches;
		$base['payload']            = self::encode_payload( $query, $base, $organic, $serper_pages, $people_also_ask, $related_searches );

		return $base;
	}

	public static function normalize_pages( int $pages ): int {
		return max( 1, min( self::MAX_PAGES, $pages ) );
	}

	/**
	 * @return array<string, string>
	 */
	public static function tbs_options(): array {
		return [
			''      => __( 'Any time', '4wp-seo-helper' ),
			'qdr:h' => __( 'Past hour', '4wp-seo-helper' ),
			'qdr:d' => __( 'Past day', '4wp-seo-helper' ),
			'qdr:w' => __( 'Past week', '4wp-seo-helper' ),
			'qdr:m' => __( 'Past month', '4wp-seo-helper' ),
			'qdr:y' => __( 'Past year', '4wp-seo-helper' ),
		];
	}

	public static function normalize_tbs( string $tbs ): string {
		$tbs = trim( $tbs );

		return array_key_exists( $tbs, self::tbs_options() ) ? $tbs : '';
	}

	public static function tbs_label( string $tbs ): string {
		$options = self::tbs_options();
		$tbs     = self::normalize_tbs( $tbs );

		return $options[ $tbs ] ?? $options[''];
	}

	/**
	 * @return list<array{rank: int, title: string, link: string, snippet: string, ours: bool}>
	 */
	public static function organic_from_payload( string $payload ): array {
		$json = json_decode( $payload, true );
		if ( ! is_array( $json ) ) {
			return [];
		}

		if ( isset( $json['organic'] ) && is_array( $json['organic'] ) ) {
			$out = [];
			foreach ( $json['organic'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$out[] = [
					'rank'    => (int) ( $row['rank'] ?? 0 ),
					'title'   => (string) ( $row['title'] ?? '' ),
					'link'    => (string) ( $row['link'] ?? '' ),
					'snippet' => self::snippet_from_row( $row ),
					'ours'    => ! empty( $row['ours'] ),
				];
			}

			return $out;
		}

		$legacy = $json['serper']['organic'] ?? null;
		if ( is_array( $legacy ) ) {
			return self::normalize_organic( $legacy, 1 );
		}

		return [];
	}

	/**
	 * @return array{people_also_ask: list<array{question: string, snippet: string, title: string, link: string}>, related_searches: list<string>}
	 */
	public static function ideas_from_payload( string $payload ): array {
		$empty = [
			'people_also_ask'  => [],
			'related_searches' => [],
		];
		$json = json_decode( $payload, true );
		if ( ! is_array( $json ) ) {
			return $empty;
		}

		$paa = [];
		if ( isset( $json['people_also_ask'] ) && is_array( $json['people_also_ask'] ) ) {
			$paa = $json['people_also_ask'];
		} elseif ( isset( $json['serper_pages'] ) && is_array( $json['serper_pages'] ) ) {
			foreach ( $json['serper_pages'] as $page ) {
				if ( is_array( $page ) ) {
					$paa = self::merge_people_also_ask( $paa, $page );
				}
			}
		}

		$related = [];
		if ( isset( $json['related_searches'] ) && is_array( $json['related_searches'] ) ) {
			foreach ( $json['related_searches'] as $row ) {
				$related[] = is_array( $row ) ? (string) ( $row['query'] ?? '' ) : (string) $row;
			}
			$related = array_values( array_filter( array_map( 'trim', $related ) ) );
		} elseif ( isset( $json['serper_pages'] ) && is_array( $json['serper_pages'] ) ) {
			foreach ( $json['serper_pages'] as $page ) {
				if ( is_array( $page ) ) {
					$related = self::merge_related_searches( $related, $page );
				}
			}
		}

		return [
			'people_also_ask'  => is_array( $paa ) ? $paa : [],
			'related_searches' => $related,
		];
	}

	/**
	 * @return array{ok: bool, http_code: int, error: string, json: array<string, mixed>, organic: list<mixed>}
	 */
	private static function fetch_page( string $key, string $query, string $gl, string $hl, int $page, string $tbs = '' ): array {
		$body = [
			'q'    => $query,
			'gl'   => $gl,
			'hl'   => $hl,
			'num'  => self::PAGE_SIZE,
			'page' => $page,
		];
		if ( '' !== $tbs ) {
			$body['tbs'] = $tbs;
		}

		$response = wp_remote_post(
			Settings::SERPER_SEARCH_URL,
			[
				'timeout' => 20,
				'headers' => [
					'X-API-KEY'    => $key,
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'ok'        => false,
				'http_code' => 0,
				'error'     => $response->get_error_message(),
				'json'      => [],
				'organic'   => [],
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );
		$json = is_array( $json ) ? $json : [ 'raw' => $body ];

		if ( $code < 200 || $code >= 300 ) {
			$message = ! empty( $json['message'] ) ? (string) $json['message'] : $body;
			return [
				'ok'        => false,
				'http_code' => $code,
				'error'     => sprintf(
					/* translators: 1: HTTP status, 2: API error body */
					__( 'Serper returned HTTP %1$d. %2$s', '4wp-seo-helper' ),
					$code,
					$message
				),
				'json'      => $json,
				'organic'   => [],
			];
		}

		$organic = isset( $json['organic'] ) && is_array( $json['organic'] ) ? $json['organic'] : [];

		return [
			'ok'        => true,
			'http_code' => $code,
			'error'     => '',
			'json'      => $json,
			'organic'   => $organic,
		];
	}

	/**
	 * @param list<mixed> $organic
	 * @return list<array{rank: int, title: string, link: string, snippet: string, ours: bool}>
	 */
	private static function normalize_organic( array $organic, int $page ): array {
		$out   = [];
		$index = 0;
		foreach ( $organic as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			++$index;
			$pos  = (int) ( $row['position'] ?? 0 );
			$rank = $pos > 0 ? ( ( $page - 1 ) * self::PAGE_SIZE ) + $pos : ( ( $page - 1 ) * self::PAGE_SIZE ) + $index;
			$link = (string) ( $row['link'] ?? '' );
			$out[] = [
				'rank'    => $rank,
				'title'   => (string) ( $row['title'] ?? '' ),
				'link'    => $link,
				'snippet' => self::snippet_from_row( $row ),
				'ours'    => '' !== $link && Target::matches_url( $link ),
			];
		}

		return $out;
	}

	/**
	 * @param list<array{rank?: int, link?: string, ours?: bool}> $organic
	 * @param list<array<string, mixed>>                         $serper_pages
	 * @param list<array{question: string, snippet: string, title: string, link: string}> $people_also_ask
	 * @param list<string>                                       $related_searches
	 */
	private static function encode_payload(
		string $query,
		array $meta,
		array $organic,
		array $serper_pages,
		array $people_also_ask = [],
		array $related_searches = []
	): string {
		$encoded = wp_json_encode(
			[
				'checked_at'       => $meta['checked_at'] ?? current_time( 'mysql' ),
				'query'            => $query,
				'gl'               => $meta['gl'] ?? '',
				'hl'               => $meta['hl'] ?? '',
				'tbs'              => $meta['tbs'] ?? '',
				'provider'         => $meta['provider'] ?? Settings::PROVIDER_SERPER,
				'target'           => [
					'property' => $meta['target_property'] ?? '',
					'host'     => $meta['target_host'] ?? '',
				],
				'site_position'    => $meta['site_position'] ?? null,
				'organic_count'    => $meta['organic_count'] ?? count( $organic ),
				'pages_fetched'    => $meta['pages_fetched'] ?? count( $serper_pages ),
				'depth'            => $meta['depth'] ?? self::PAGE_SIZE,
				'http_code'        => $meta['http_code'] ?? 0,
				'error'            => $meta['error'] ?? '',
				'organic'          => $organic,
				'people_also_ask'  => $people_also_ask,
				'related_searches' => $related_searches,
				'serper_pages'     => $serper_pages,
			],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return is_string( $encoded ) ? $encoded : '{}';
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function snippet_from_row( array $row ): string {
		if ( ! empty( $row['snippet'] ) && is_string( $row['snippet'] ) ) {
			return $row['snippet'];
		}
		if ( ! empty( $row['description'] ) && is_string( $row['description'] ) ) {
			return $row['description'];
		}

		return '';
	}

	/**
	 * @param list<array{question: string, snippet: string, title: string, link: string}> $existing
	 * @param array<string, mixed>                                                        $page
	 * @return list<array{question: string, snippet: string, title: string, link: string}>
	 */
	private static function merge_people_also_ask( array $existing, array $page ): array {
		$seen = [];
		foreach ( $existing as $row ) {
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) ( $row['question'] ?? '' ) ) : strtolower( (string) ( $row['question'] ?? '' ) );
			if ( '' !== $key ) {
				$seen[ $key ] = true;
			}
		}

		$rows = $page['peopleAlsoAsk'] ?? $page['people_also_ask'] ?? [];
		if ( ! is_array( $rows ) ) {
			return $existing;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$question = trim( (string) ( $row['question'] ?? '' ) );
			if ( '' === $question ) {
				continue;
			}
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $question ) : strtolower( $question );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$existing[]   = [
				'question' => $question,
				'snippet'  => self::snippet_from_row( $row ),
				'title'    => (string) ( $row['title'] ?? '' ),
				'link'     => (string) ( $row['link'] ?? '' ),
			];
		}

		return $existing;
	}

	/**
	 * @param list<string>         $existing
	 * @param array<string, mixed> $page
	 * @return list<string>
	 */
	private static function merge_related_searches( array $existing, array $page ): array {
		$seen = [];
		foreach ( $existing as $query ) {
			$key = mb_strtolower( $query );
			if ( '' !== $key ) {
				$seen[ $key ] = true;
			}
		}

		$rows = $page['relatedSearches'] ?? $page['related_searches'] ?? [];
		if ( ! is_array( $rows ) ) {
			return $existing;
		}

		foreach ( $rows as $row ) {
			$query = is_array( $row ) ? trim( (string) ( $row['query'] ?? '' ) ) : trim( (string) $row );
			if ( '' === $query ) {
				continue;
			}
			$key = mb_strtolower( $query );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$existing[]   = $query;
		}

		return $existing;
	}

	/**
	 * @param list<array{rank?: int, link?: string, ours?: bool}> $organic
	 * @return list<array{rank: int, path: string, label: string}>
	 */
	public static function ours_hits( array $organic ): array {
		$out = [];
		foreach ( $organic as $row ) {
			if ( empty( $row['ours'] ) ) {
				continue;
			}
			$rank = (int) ( $row['rank'] ?? 0 );
			$link = (string) ( $row['link'] ?? '' );
			if ( $rank < 1 || '' === $link ) {
				continue;
			}
			$out[] = [
				'rank'  => $rank,
				'path'  => Target::path_key( $link ),
				'label' => Target::link_label( $link ),
				'link'  => $link,
			];
		}

		return $out;
	}

	/**
	 * @param list<array{rank?: int, ours?: bool}> $organic
	 */
	private static function site_position( array $organic ): ?int {
		foreach ( $organic as $row ) {
			if ( ! empty( $row['ours'] ) ) {
				$rank = (int) ( $row['rank'] ?? 0 );
				return $rank > 0 ? $rank : null;
			}
		}

		return null;
	}
}
