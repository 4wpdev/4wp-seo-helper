<?php
/**
 * Google Analytics 4 API client (Data API + Admin API, read-only).
 */

namespace Forwp\SeoHelper\Ga;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Client {
	public const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

	private const ACCOUNT_SUMMARIES = 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200';
	private const DATA_ENDPOINT     = 'https://analyticsdata.googleapis.com/v1beta/';

	/**
	 * GA4 properties the token can read.
	 *
	 * @return array{properties?: list<array{id: string, name: string, account: string}>, error?: string}
	 */
	public function list_properties( string $access_token ): array {
		$response = wp_remote_get(
			self::ACCOUNT_SUMMARIES,
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
				'timeout' => 15,
			]
		);
		$body = $this->parse( $response );
		if ( isset( $body['error'] ) ) {
			return [ 'error' => (string) $body['error'] ];
		}

		$out = [];
		foreach ( (array) ( $body['accountSummaries'] ?? [] ) as $account ) {
			foreach ( (array) ( $account['propertySummaries'] ?? [] ) as $property ) {
				$id = str_replace( 'properties/', '', (string) ( $property['property'] ?? '' ) );
				if ( '' === $id ) {
					continue;
				}
				$out[] = [
					'id'      => $id,
					'name'    => (string) ( $property['displayName'] ?? $id ),
					'account' => (string) ( $account['displayName'] ?? '' ),
				];
			}
		}

		return [ 'properties' => $out ];
	}

	/**
	 * Totals for a date range (no dimensions).
	 *
	 * @param list<string> $metrics GA4 metric API names (activeUsers, sessions, screenPageViews…).
	 * @return array{totals?: array<string, int>, error?: string}
	 */
	public function totals( string $access_token, string $property_id, string $start, string $end, array $metrics ): array {
		$response = wp_remote_post(
			self::DATA_ENDPOINT . 'properties/' . rawurlencode( $property_id ) . ':runReport',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'dateRanges' => [
							[
								'startDate' => $start,
								'endDate'   => $end,
							],
						],
						'metrics'    => array_map(
							static function ( string $name ): array {
								return [ 'name' => $name ];
							},
							$metrics
						),
					]
				),
				'timeout' => 60,
			]
		);
		$body = $this->parse( $response );
		if ( isset( $body['error'] ) ) {
			return [ 'error' => (string) $body['error'] ];
		}

		$totals = array_fill_keys( $metrics, 0 );
		$row    = $body['rows'][0]['metricValues'] ?? [];
		foreach ( $metrics as $i => $name ) {
			$totals[ $name ] = (int) round( (float) ( $row[ $i ]['value'] ?? 0 ) );
		}

		return [ 'totals' => $totals ];
	}

	/**
	 * Values per day (dimension date).
	 *
	 * @param list<string> $metrics GA4 metric API names.
	 * @return array{days?: array<string, array<string, int>>, error?: string} days: Y-m-d => [metric => value]
	 */
	public function daily( string $access_token, string $property_id, string $start, string $end, array $metrics ): array {
		$response = wp_remote_post(
			self::DATA_ENDPOINT . 'properties/' . rawurlencode( $property_id ) . ':runReport',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'dateRanges' => [
							[
								'startDate' => $start,
								'endDate'   => $end,
							],
						],
						'dimensions' => [ [ 'name' => 'date' ] ],
						'metrics'    => array_map(
							static function ( string $name ): array {
								return [ 'name' => $name ];
							},
							$metrics
						),
						'limit'      => 1000,
					]
				),
				'timeout' => 30,
			]
		);
		$body = $this->parse( $response );
		if ( isset( $body['error'] ) ) {
			return [ 'error' => (string) $body['error'] ];
		}

		$days = [];
		foreach ( (array) ( $body['rows'] ?? [] ) as $row ) {
			$raw  = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
			$date = substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 2 ) . '-' . substr( $raw, 6, 2 );
			foreach ( $metrics as $i => $name ) {
				$days[ $date ][ $name ] = (int) round( (float) ( $row['metricValues'][ $i ]['value'] ?? 0 ) );
			}
		}

		return [ 'days' => $days ];
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @return array<string, mixed>
	 */
	private function parse( $response ): array {
		if ( is_wp_error( $response ) ) {
			return [ 'error' => $response->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return [ 'error' => 'Invalid response (HTTP ' . $code . ').' ];
		}
		if ( isset( $body['error'] ) ) {
			$message = is_array( $body['error'] ) ? (string) ( $body['error']['message'] ?? 'API error' ) : (string) $body['error'];
			if ( 403 === $code || 401 === $code ) {
				$message .= ' — reconnect Google in Search Console settings to grant Analytics read access.';
			}
			return [ 'error' => $message ];
		}
		return $body;
	}
}
