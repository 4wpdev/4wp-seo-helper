<?php
/**
 * Google Search Console API client.
 */

namespace Forwp\SeoHelper\Gsc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Client {
	private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	private const SITES_ENDPOINT = 'https://searchconsole.googleapis.com/webmasters/v3/sites';
	private const INSPECTION_ENDPOINT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

	private const DEFAULT_TIMEOUT = 15;

	private const INSPECTION_TIMEOUT = 60;

	/**
	 * @return array<string, mixed>
	 */
	private function auth_headers( string $access_token ): array {
		return [
			'Authorization' => 'Bearer ' . $access_token,
			'Content-Type'  => 'application/json',
		];
	}

	private function default_timeout(): int {
		/**
		 * @param int $seconds Default HTTP timeout for GSC API requests.
		 */
		return max( 5, (int) apply_filters( 'forwp_seo_gsc_request_timeout', self::DEFAULT_TIMEOUT ) );
	}

	private function inspection_timeout(): int {
		/**
		 * URL Inspection asks Google to live-check a URL and often exceeds 5s.
		 *
		 * @param int $seconds HTTP timeout for urlInspection/index:inspect.
		 */
		return max( 15, (int) apply_filters( 'forwp_seo_gsc_inspection_timeout', self::INSPECTION_TIMEOUT ) );
	}

	public function get_authorization_url( string $client_id, string $redirect_uri, string $state ): string {
		$params = [
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'scope'         => implode(
				' ',
				[
					'https://www.googleapis.com/auth/webmasters',
					'https://www.googleapis.com/auth/webmasters.readonly',
					\Forwp\SeoHelper\Ga\Client::SCOPE,
				]
			),
			'state'         => $state,
		];

		return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	public function exchange_code( string $client_id, string $client_secret, string $redirect_uri, string $code ): array {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			[
				'body' => [
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				],
			]
		);

		return $this->parse_token_response( $response );
	}

	public function refresh_token( string $client_id, string $client_secret, string $refresh_token ): array {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			[
				'body' => [
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				],
			]
		);

		return $this->parse_token_response( $response );
	}

	public function list_sites( string $access_token ): array {
		$response = wp_remote_get(
			self::SITES_ENDPOINT,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
				],
				'timeout' => $this->default_timeout(),
			]
		);

		return $this->parse_json_response( $response );
	}

	public function inspect_url( string $access_token, string $site_url, string $url ): array {
		$body = [
			'inspectionUrl' => $url,
			'siteUrl'       => PropertyResolver::normalize_site_url_for_api( $site_url ),
		];

		$response = wp_remote_post(
			self::INSPECTION_ENDPOINT,
			[
				'headers' => $this->auth_headers( $access_token ),
				'body'    => wp_json_encode( $body ),
				'timeout' => $this->inspection_timeout(),
			]
		);

		return $this->parse_json_response( $response );
	}

	public function search_analytics( string $access_token, string $site_url, string $url, string $start_date, string $end_date ): array {
		return $this->search_analytics_query(
			$access_token,
			$site_url,
			[
				'startDate'  => $start_date,
				'endDate'    => $end_date,
				'dimensions' => [ 'page' ],
				'type'       => 'web',
				'dimensionFilterGroups' => [
					[
						'filters' => [
							[
								'dimension'  => 'page',
								'operator'   => 'equals',
								'expression' => $url,
							],
						],
					],
				],
			]
		);
	}

	/**
	 * @param array{
	 *   startDate?: string,
	 *   endDate?: string,
	 *   dimensions?: list<string>,
	 *   type?: string,
	 *   rowLimit?: int,
	 *   startRow?: int,
	 *   dimensionFilterGroups?: list<array<string, mixed>>
	 * } $args
	 * @return array<string, mixed>
	 */
	public function search_analytics_query( string $access_token, string $site_url, array $args ): array {
		$body = [
			'startDate'  => (string) ( $args['startDate'] ?? '' ),
			'endDate'    => (string) ( $args['endDate'] ?? '' ),
			'dimensions' => $args['dimensions'] ?? [],
			'type'       => (string) ( $args['type'] ?? 'web' ),
			'rowLimit'   => max( 1, min( 25000, (int) ( $args['rowLimit'] ?? 25000 ) ) ),
			'startRow'   => max( 0, (int) ( $args['startRow'] ?? 0 ) ),
		];

		if ( ! empty( $args['dimensionFilterGroups'] ) ) {
			$body['dimensionFilterGroups'] = $args['dimensionFilterGroups'];
		}

		$response = wp_remote_post(
			$this->site_endpoint( $site_url, '/searchAnalytics/query' ),
			[
				'headers' => $this->auth_headers( $access_token ),
				'body'    => wp_json_encode( $body ),
				'timeout' => $this->default_timeout(),
			]
		);

		return $this->parse_json_response( $response );
	}

	private function site_endpoint( string $site_url, string $resource_path ): string {
		return self::SITES_ENDPOINT . '/' . rawurlencode( $site_url ) . '/' . ltrim( $resource_path, '/' );
	}

	/**
	 * Paginate through all rows for a query (respects API 25k cap per request).
	 *
	 * @param array<string, mixed> $args
	 * @return array{rows:list<array<string, mixed>>, error?: string}
	 */
	public function search_analytics_fetch_all( string $access_token, string $site_url, array $args ): array {
		$all_rows  = [];
		$start_row = 0;
		$limit     = max( 1, min( 25000, (int) ( $args['rowLimit'] ?? 25000 ) ) );

		do {
			$args['startRow'] = $start_row;
			$args['rowLimit'] = $limit;
			$response         = $this->search_analytics_query( $access_token, $site_url, $args );

			if ( isset( $response['error'] ) ) {
				return [
					'rows'  => $all_rows,
					'error' => (string) $response['error'],
				];
			}

			$batch = $response['rows'] ?? [];
			if ( ! is_array( $batch ) || empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $row ) {
				if ( is_array( $row ) ) {
					$all_rows[] = $row;
				}
			}

			if ( count( $batch ) < $limit ) {
				break;
			}

			$start_row += $limit;
			usleep( 250000 );
		} while ( $start_row < 100000 );

		return [ 'rows' => $all_rows ];
	}

	private function parse_token_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			return [
				'error' => $response->get_error_message(),
			];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return [
				'error' => 'Invalid token response.',
			];
		}

		if ( isset( $body['error'] ) ) {
			return [
				'error' => $body['error_description'] ?? $body['error'],
			];
		}

		return $body;
	}

	private function parse_json_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			return [
				'error' => $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return [
				'error' => 401 === $code || 403 === $code
					? __( 'Google rejected the request. Try disconnecting and reconnecting, and confirm Search Console API is enabled in Google Cloud.', '4wp-seo-helper' )
					: __( 'Invalid API response.', '4wp-seo-helper' ),
			];
		}

		if ( isset( $body['error'] ) ) {
			$message = is_array( $body['error'] ) ? (string) ( $body['error']['message'] ?? 'API error' ) : (string) $body['error'];
			if ( 403 === $code && ( str_contains( strtolower( $message ), 'not enabled' ) || str_contains( strtolower( $message ), 'accessnotconfigured' ) ) ) {
				$message = __( 'Search Console API is not enabled for this Google Cloud project. Open APIs & Services → Library, enable “Google Search Console API”, then disconnect and reconnect.', '4wp-seo-helper' );
			} elseif ( 401 === $code ) {
				$message = __( 'Google access token expired or was revoked. Disconnect and connect again.', '4wp-seo-helper' );
			}

			return [
				'error' => $message,
			];
		}

		if ( $code >= 400 ) {
			return [
				'error' => sprintf(
					/* translators: %d: HTTP status code from Google API. */
					__( 'Google API request failed (HTTP %d).', '4wp-seo-helper' ),
					$code
				),
			];
		}

		return $body;
	}
}


















