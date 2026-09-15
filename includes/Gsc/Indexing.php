<?php
/**
 * Editor Search Console inspect + request indexing.
 */

namespace Forwp\SeoHelper\Gsc;

use Forwp\SeoHelper\Inventory\HistoryLogger;
use Forwp\SeoHelper\SeoMeta\Registry as SeoMetaRegistry;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Indexing {
	public const META_REQUESTED_AT = '_forwp_seo_gsc_index_requested_at';
	public const META_LAST_STATUS  = '_forwp_seo_gsc_index_last_status';

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	public function register_rest_routes(): void {
		register_rest_route(
			'forwp-seo/v1',
			'/gsc/post-index',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_get_status' ],
				'permission_callback' => [ $this, 'can_manage_post' ],
				'args'                => [
					'post_id' => [
						'type'     => 'integer',
						'required' => true,
					],
					'inspect' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		register_rest_route(
			'forwp-seo/v1',
			'/gsc/request-index',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_request_index' ],
				'permission_callback' => [ $this, 'can_manage_post' ],
				'args'                => [
					'post_id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);
	}

	public function can_manage_post( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$post_id = (int) $request->get_param( 'post_id' );
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public function handle_get_status( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			$this->build_status(
				(int) $request->get_param( 'post_id' ),
				(bool) $request->get_param( 'inspect' )
			)
		);
	}

	public function handle_request_index( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->request_index( (int) $request->get_param( 'post_id' ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function request_index( int $post_id ): array {
		$status = $this->build_status( $post_id, false );
		if ( empty( $status['ready'] ) ) {
			return $status;
		}

		$url     = (string) $status['inspectionUrl'];
		$token   = Admin::get_instance()->get_access_token_for_sync();
		$inspect = $this->inspect_and_store( $post_id, $url, $token, (string) $status['property'], true );

		update_post_meta( $post_id, self::META_REQUESTED_AT, (string) time() );

		$result            = $this->build_status( $post_id, false );
		$result['ok']      = true;
		$result['inspect'] = $inspect['inspect'] ?? $result['inspect'];
		if ( ! empty( $inspect['inspect']['inspectLink'] ) ) {
			$result['gscInspectUrl'] = (string) $inspect['inspect']['inspectLink'];
		}
		$result['message'] = __( 'Opened Search Console for this URL. Click Request indexing on that page.', '4wp-seo-helper' );

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_status( int $post_id, bool $live_inspect ): array {
		$post = get_post( $post_id );
		$admin = Admin::get_instance();
		$module = Module::get_instance();

		$base = [
			'ok'             => false,
			'ready'          => false,
			'enabled'        => $module->is_enabled(),
			'connected'      => $admin->is_connected(),
			'published'      => $post instanceof \WP_Post && 'publish' === $post->post_status,
			'permalink'      => $post instanceof \WP_Post ? (string) get_permalink( $post ) : '',
			'inspectionUrl'  => '',
			'gscInspectUrl'  => '',
			'property'       => Admin::get_site_property(),
			'settingsUrl'    => $admin->get_settings_url(),
			'requestedAt'    => (int) get_post_meta( $post_id, self::META_REQUESTED_AT, true ),
			'inspect'        => $this->read_stored_inspect( $post_id ),
			'seo'            => $post instanceof \WP_Post ? $this->seo_payload( $post_id ) : null,
			'message'        => '',
		];

		if ( ! $module->is_enabled() ) {
			$base['message'] = __( 'Search Console is disabled.', '4wp-seo-helper' );
			return $base;
		}

		if ( ! $admin->is_connected() ) {
			$base['message'] = __( 'Connect Google in Settings → Search Console first.', '4wp-seo-helper' );
			return $base;
		}

		if ( ! ( $post instanceof \WP_Post ) ) {
			$base['message'] = __( 'Post not found.', '4wp-seo-helper' );
			return $base;
		}

		if ( 'publish' !== $post->post_status ) {
			$base['message'] = __( 'Publish the post before requesting indexing.', '4wp-seo-helper' );
			return $base;
		}

		$site = Admin::get_site_property();
		if ( '' === $site ) {
			$base['message'] = __( 'Select a Search Console property in Settings.', '4wp-seo-helper' );
			return $base;
		}

		$url = PropertyResolver::rewrite_url_for_property( (string) get_permalink( $post ), $site );
		$base['inspectionUrl'] = $url;
		$base['gscInspectUrl'] = $this->resolve_gsc_inspect_url( $post_id, $site, $url );

		if ( ! PropertyResolver::url_belongs_to_property( $url, $site ) ) {
			$base['message'] = __( 'This URL is not part of the selected Search Console property.', '4wp-seo-helper' );
			return $base;
		}

		$base['ready'] = true;

		if ( $live_inspect ) {
			$token = $admin->get_access_token_for_sync();
			$inspect_result      = $this->inspect_and_store( $post_id, $url, $token, $site, false );
			$base['inspect']     = $inspect_result['inspect'];
			$base['gscInspectUrl'] = $this->resolve_gsc_inspect_url( $post_id, $site, $url );
		}

		return $base;
	}

	/**
	 * @return array{inspect: array<string, string>}
	 */
	private function inspect_and_store( int $post_id, string $url, string $token, string $site, bool $index_request = false ): array {
		$inspect = [
			'coverage'         => '',
			'verdict'          => '',
			'lastCrawl'        => '',
			'userCanonical'    => '',
			'googleCanonical'  => '',
			'inspectLink'      => '',
			'error'            => '',
		];

		if ( '' === $token ) {
			$inspect['error'] = __( 'Missing Google access token.', '4wp-seo-helper' );
			return [ 'inspect' => $inspect ];
		}

		$client = new Client();
		$result = $client->inspect_url( $token, $site, $url );
		if ( isset( $result['error'] ) ) {
			$inspect['error'] = (string) $result['error'];
			update_post_meta( $post_id, self::META_LAST_STATUS, wp_json_encode( $inspect ) );
			return [ 'inspect' => $inspect ];
		}

		$index = $result['inspectionResult']['indexStatusResult'] ?? [];
		$inspect['coverage']        = sanitize_text_field( (string) ( $index['coverageState'] ?? '' ) );
		$inspect['verdict']         = sanitize_text_field( (string) ( $index['verdict'] ?? '' ) );
		$inspect['lastCrawl']       = sanitize_text_field( (string) ( $index['lastCrawlTime'] ?? '' ) );
		$inspect['userCanonical']   = esc_url_raw( (string) ( $index['userCanonical'] ?? '' ) );
		$inspect['googleCanonical'] = esc_url_raw( (string) ( $index['googleCanonical'] ?? '' ) );
		$inspect['inspectLink']     = esc_url_raw( (string) ( $result['inspectionResult']['inspectionResultLink'] ?? '' ) );

		update_post_meta( $post_id, self::META_LAST_STATUS, wp_json_encode( $inspect ) );
		HistoryLogger::on_inspect( $post_id, $inspect, $index_request );

		return [ 'inspect' => $inspect ];
	}

	private function resolve_gsc_inspect_url( int $post_id, string $site, string $url ): string {
		unset( $url );

		$stored = $this->read_stored_inspect( $post_id );
		$link   = (string) ( $stored['inspectLink'] ?? '' );
		if ( PropertyResolver::is_valid_inspection_result_link( $link ) ) {
			return $link;
		}

		return PropertyResolver::search_console_property_url( $site );
	}

	/**
	 * Stored inspect + request data for SEO Inventory (no live API call).
	 *
	 * @return array{
	 *     gsc_coverage: string,
	 *     gsc_verdict: string,
	 *     gsc_last_crawl: string,
	 *     gsc_index_requested_at: int,
	 *     gsc_inspect_link: string,
	 *     gsc_inspect_error: string
	 * }
	 */
	public static function inventory_fields( int $post_id ): array {
		$raw     = get_post_meta( $post_id, self::META_LAST_STATUS, true );
		$inspect = [
			'coverage'    => '',
			'verdict'     => '',
			'lastCrawl'   => '',
			'inspectLink' => '',
			'error'       => '',
		];

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$inspect['coverage']    = sanitize_text_field( (string) ( $decoded['coverage'] ?? '' ) );
				$inspect['verdict']     = sanitize_text_field( (string) ( $decoded['verdict'] ?? '' ) );
				$inspect['lastCrawl']   = sanitize_text_field( (string) ( $decoded['lastCrawl'] ?? '' ) );
				$inspect['inspectLink'] = esc_url_raw( (string) ( $decoded['inspectLink'] ?? '' ) );
				$inspect['error']       = sanitize_text_field( (string) ( $decoded['error'] ?? '' ) );
			}
		}

		return [
			'gsc_coverage'             => $inspect['coverage'],
			'gsc_verdict'              => $inspect['verdict'],
			'gsc_last_crawl'           => $inspect['lastCrawl'],
			'gsc_index_requested_at'   => (int) get_post_meta( $post_id, self::META_REQUESTED_AT, true ),
			'gsc_inspect_link'           => $inspect['inspectLink'],
			'gsc_inspect_error'          => $inspect['error'],
		];
	}

	/**
	 * Sort rank: 0 not indexed / error, 1 other, 2 not inspected, 3 indexed.
	 *
	 * @param array<string, mixed> $item Inventory record.
	 */
	public static function inventory_status_rank( array $item ): int {
		$coverage = (string) ( $item['gsc_coverage'] ?? '' );
		$verdict  = (string) ( $item['gsc_verdict'] ?? '' );
		$error    = (string) ( $item['gsc_inspect_error'] ?? '' );

		if ( '' !== $error && '' === $coverage && '' === $verdict ) {
			return 0;
		}

		if ( '' === $coverage && '' === $verdict ) {
			return 2;
		}

		$text = $coverage . ' ' . $verdict;
		if ( preg_match( '/indexed/i', $text ) && ! preg_match( '/not indexed|excluded/i', $text ) ) {
			return 3;
		}

		if ( preg_match( '/not indexed|excluded|error|fail/i', $text ) ) {
			return 0;
		}

		return 1;
	}

	/**
	 * @param array<string, mixed> $item Inventory record.
	 */
	public static function inventory_status_label( array $item ): string {
		$coverage = trim( (string) ( $item['gsc_coverage'] ?? '' ) );
		if ( '' !== $coverage ) {
			return $coverage;
		}

		$verdict = trim( (string) ( $item['gsc_verdict'] ?? '' ) );
		if ( '' !== $verdict ) {
			return $verdict;
		}

		return trim( (string) ( $item['gsc_inspect_error'] ?? '' ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function read_stored_inspect( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_LAST_STATUS, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function seo_payload( int $post_id ): array {
		$adapter = SeoMetaRegistry::get_active();
		$scores  = $adapter->read_scores( $post_id );
		$meta    = $adapter->read( $post_id );

		return [
			'adapter'       => $adapter->get_id(),
			'adapterLabel'  => $adapter->get_label(),
			'seo'           => $scores['seo'],
			'readability'   => $scores['readability'],
			'label'         => (string) $scores['label'],
			'noFocus'       => (bool) $scores['no_focus'],
			'focusKeyword'  => (string) ( $meta['focus_keyword'] ?? '' ),
		];
	}
}
