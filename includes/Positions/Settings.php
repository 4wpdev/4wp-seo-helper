<?php
/**
 * Rank-tracking provider settings (Serper first).
 */

namespace Forwp\SeoHelper\Positions;

use Forwp\SeoHelper\Admin\Menu;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	public const OPTION_PROVIDER   = 'forwp_seo_positions_provider';
	public const OPTION_SERPER_KEY = 'forwp_seo_positions_serper_key';
	public const OPTION_GL         = 'forwp_seo_positions_gl';
	public const OPTION_HL         = 'forwp_seo_positions_hl';

	public const PROVIDER_SERPER = 'serper';

	public const SERPER_SEARCH_URL = 'https://google.serper.dev/search';
	public const SERPER_SITE_URL   = 'https://serper.dev/';
	public const SERPER_KEYS_URL   = 'https://serper.dev/api-key';
	public const SERPER_DOCS_URL   = 'https://serper.dev/';

	/**
	 * Position lookup providers shown in Settings → Positions.
	 *
	 * @return list<array{id: string, label: string, slug: string, live: bool, blurb: string, site_url: string, site_label: string, extra_links?: list<array{url: string, label: string}>}>
	 */
	public static function providers(): array {
		$configured = self::is_configured();

		return [
			[
				'id'         => self::PROVIDER_SERPER,
				'label'      => 'Serper',
				'slug'       => 'serper.dev',
				'live'       => true,
				'configured' => $configured,
				'blurb'      => __( 'Google Search API for rank checks. Paste a key, then test the connection.', '4wp-seo-helper' ),
				'site_url'   => self::SERPER_SITE_URL,
				'site_label' => 'serper.dev',
				'extra_links' => [
					[
						'url'   => self::SERPER_KEYS_URL,
						'label' => __( 'API keys', '4wp-seo-helper' ),
					],
				],
			],
			[
				'id'         => 'dataforseo',
				'label'      => 'DataForSEO',
				'slug'       => 'dataforseo.com',
				'live'       => false,
				'configured' => false,
				'blurb'      => __( 'SERP and keyword APIs. Connector is on the roadmap.', '4wp-seo-helper' ),
				'site_url'   => 'https://dataforseo.com/',
				'site_label' => 'dataforseo.com',
			],
			[
				'id'         => 'serpapi',
				'label'      => 'SerpApi',
				'slug'       => 'serpapi.com',
				'live'       => false,
				'configured' => false,
				'blurb'      => __( 'Google SERP JSON API. Connector is on the roadmap.', '4wp-seo-helper' ),
				'site_url'   => 'https://serpapi.com/',
				'site_label' => 'serpapi.com',
			],
		];
	}

	public static function requested_provider(): string {
		$ids = array_column( self::providers(), 'id' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only panel deep link.
		$raw = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( (string) $_GET['provider'] ) ) : '';

		return in_array( $raw, $ids, true ) ? $raw : '';
	}

	public static function get_provider(): string {
		$provider = sanitize_key( (string) get_option( self::OPTION_PROVIDER, self::PROVIDER_SERPER ) );

		return self::PROVIDER_SERPER === $provider ? $provider : self::PROVIDER_SERPER;
	}

	public static function get_serper_key(): string {
		return (string) get_option( self::OPTION_SERPER_KEY, '' );
	}

	public static function get_gl(): string {
		return SerperLocales::normalize_gl( (string) get_option( self::OPTION_GL, 'us' ) );
	}

	public static function get_hl(): string {
		return SerperLocales::normalize_hl( (string) get_option( self::OPTION_HL, 'en' ) );
	}

	public static function is_configured(): bool {
		return self::PROVIDER_SERPER === self::get_provider() && '' !== self::get_serper_key();
	}

	public static function settings_url(): string {
		return add_query_arg(
			[
				'page' => Menu::SETTINGS_PAGE_SLUG,
				'tab'  => 'positions',
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @return array{ok: bool, message: string}
	 */
	public static function test_connection(): array {
		$key = self::get_serper_key();
		if ( '' === $key ) {
			return [
				'ok'      => false,
				'message' => __( 'Add a Serper API key first.', '4wp-seo-helper' ),
			];
		}

		$response = wp_remote_post(
			self::SERPER_SEARCH_URL,
			[
				'timeout' => 15,
				'headers' => [
					'X-API-KEY'    => $key,
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'q'  => 'wordpress',
						'gl' => self::get_gl(),
						'hl' => self::get_hl(),
						'num' => 1,
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'message' => $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return [
				'ok'      => true,
				'message' => __( 'Serper connection works.', '4wp-seo-helper' ),
			];
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );
		if ( is_array( $json ) && ! empty( $json['message'] ) ) {
			$body = (string) $json['message'];
		}

		return [
			'ok'      => false,
			'message' => sprintf(
				/* translators: 1: HTTP status, 2: API error body */
				__( 'Serper returned HTTP %1$d. %2$s', '4wp-seo-helper' ),
				$code,
				$body
			),
		];
	}

	public static function save_from_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in Page::process_settings_post().
		$provider = sanitize_key( (string) ( $_POST['forwp_seo_positions_provider'] ?? self::PROVIDER_SERPER ) );
		if ( self::PROVIDER_SERPER !== $provider ) {
			$provider = self::PROVIDER_SERPER;
		}
		update_option( self::OPTION_PROVIDER, $provider, false );

		$key = trim( (string) wp_unslash( $_POST['forwp_seo_positions_serper_key'] ?? '' ) );
		if ( '' !== $key ) {
			update_option( self::OPTION_SERPER_KEY, sanitize_text_field( $key ), false );
		}

		if ( ! empty( $_POST['forwp_seo_positions_clear_key'] ) ) {
			delete_option( self::OPTION_SERPER_KEY );
		}

		$gl = SerperLocales::normalize_gl( (string) wp_unslash( (string) ( $_POST['forwp_seo_positions_gl'] ?? 'us' ) ) );
		$hl = SerperLocales::normalize_hl( (string) wp_unslash( (string) ( $_POST['forwp_seo_positions_hl'] ?? 'en' ) ) );
		update_option( self::OPTION_GL, $gl, false );
		update_option( self::OPTION_HL, $hl, false );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
