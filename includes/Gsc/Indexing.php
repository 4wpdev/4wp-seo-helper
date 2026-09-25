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
						'required' => false,
						'default'  => 0,
					],
					'term_id' => [
						'type'     => 'integer',
						'required' => false,
						'default'  => 0,
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
						'required' => false,
						'default'  => 0,
					],
					'term_id' => [
						'type'     => 'integer',
						'required' => false,
						'default'  => 0,
					],
				],
			]
		);
	}

	public function can_manage_post( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return null !== $this->target_from_request( $request );
	}

	public function handle_get_status( WP_REST_Request $request ): WP_REST_Response {
		$target = $this->target_from_request( $request );
		if ( null === $target ) {
			return new WP_REST_Response(
				[
					'ok'      => false,
					'ready'   => false,
					'message' => __( 'Missing post or taxonomy term.', '4wp-seo-helper' ),
				],
				400
			);
		}

		return new WP_REST_Response(
			$this->build_status(
				$target['id'],
				$target['kind'],
				(bool) $request->get_param( 'inspect' )
			)
		);
	}

	public function handle_request_index( WP_REST_Request $request ): WP_REST_Response {
		$target = $this->target_from_request( $request );
		if ( null === $target ) {
			return new WP_REST_Response(
				[
					'ok'      => false,
					'ready'   => false,
					'message' => __( 'Missing post or taxonomy term.', '4wp-seo-helper' ),
				],
				400
			);
		}

		return new WP_REST_Response( $this->request_index( $target['id'], $target['kind'] ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function request_index( int $object_id, string $kind = 'post' ): array {
		$status = $this->build_status( $object_id, $kind, false );
		if ( empty( $status['ready'] ) ) {
			return $status;
		}

		$url     = (string) $status['inspectionUrl'];
		$token   = Admin::get_instance()->get_access_token_for_sync();
		$inspect = $this->inspect_and_store( $object_id, $kind, $url, $token, (string) $status['property'], true );

		self::update_object_meta( $object_id, $kind, self::META_REQUESTED_AT, (string) time() );

		$result            = $this->build_status( $object_id, $kind, false );
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
	private function build_status( int $object_id, string $kind, bool $live_inspect ): array {
		$kind   = 'term' === $kind ? 'term' : 'post';
		$admin  = Admin::get_instance();
		$module = Module::get_instance();
		$post   = 'post' === $kind ? get_post( $object_id ) : null;
		$term   = 'term' === $kind ? get_term( $object_id ) : null;
		$link   = $this->object_url( $kind, $post, $term );

		$base = [
			'ok'            => false,
			'ready'         => false,
			'enabled'       => $module->is_enabled(),
			'connected'     => $admin->is_connected(),
			'published'     => $this->is_indexable( $kind, $post, $term ),
			'permalink'     => $link,
			'inspectionUrl' => '',
			'gscInspectUrl' => '',
			'property'      => Admin::get_site_property(),
			'settingsUrl'   => $admin->get_settings_url(),
			'requestedAt'   => (int) self::get_object_meta( $object_id, $kind, self::META_REQUESTED_AT ),
			'inspect'       => $this->read_stored_inspect( $object_id, $kind ),
			'seo'           => $post instanceof \WP_Post ? $this->seo_payload( $object_id ) : null,
			'message'       => '',
		];

		if ( ! $module->is_enabled() ) {
			$base['message'] = __( 'Search Console is disabled.', '4wp-seo-helper' );
			return $base;
		}

		if ( ! $admin->is_connected() ) {
			$base['message'] = __( 'Connect Google in Settings → Search Console first.', '4wp-seo-helper' );
			return $base;
		}

		if ( 'post' === $kind && ! ( $post instanceof \WP_Post ) ) {
			$base['message'] = __( 'Post not found.', '4wp-seo-helper' );
			return $base;
		}

		if ( 'term' === $kind && ! ( $term instanceof \WP_Term ) ) {
			$base['message'] = __( 'Taxonomy term not found.', '4wp-seo-helper' );
			return $base;
		}

		if ( 'post' === $kind && $post instanceof \WP_Post && 'publish' !== $post->post_status ) {
			$base['message'] = __( 'Publish the post before requesting indexing.', '4wp-seo-helper' );
			return $base;
		}

		if ( '' === $link ) {
			$base['message'] = __( 'This archive has no public URL.', '4wp-seo-helper' );
			return $base;
		}

		$site = Admin::get_site_property();
		if ( '' === $site ) {
			$base['message'] = __( 'Select a Search Console property in Settings.', '4wp-seo-helper' );
			return $base;
		}

		$url                   = PropertyResolver::rewrite_url_for_property( $link, $site );
		$base['inspectionUrl'] = $url;
		$base['gscInspectUrl'] = $this->resolve_gsc_inspect_url( $object_id, $kind, $site );

		if ( ! PropertyResolver::url_belongs_to_property( $url, $site ) ) {
			$base['message'] = __( 'This URL is not part of the selected Search Console property.', '4wp-seo-helper' );
			return $base;
		}

		$base['ready'] = true;

		if ( $live_inspect ) {
			$token                 = $admin->get_access_token_for_sync();
			$inspect_result        = $this->inspect_and_store( $object_id, $kind, $url, $token, $site, false );
			$base['inspect']       = $inspect_result['inspect'];
			$base['gscInspectUrl'] = $this->resolve_gsc_inspect_url( $object_id, $kind, $site );
		}

		return $base;
	}

	/**
	 * @return array{inspect: array<string, string>}
	 */
	private function inspect_and_store( int $object_id, string $kind, string $url, string $token, string $site, bool $index_request = false ): array {
		$inspect = [
			'coverage'        => '',
			'verdict'         => '',
			'lastCrawl'       => '',
			'userCanonical'   => '',
			'googleCanonical' => '',
			'inspectLink'     => '',
			'error'           => '',
		];

		if ( '' === $token ) {
			$inspect['error'] = __( 'Missing Google access token.', '4wp-seo-helper' );
			return [ 'inspect' => $inspect ];
		}

		$client = new Client();
		$result = $client->inspect_url( $token, $site, $url );
		if ( isset( $result['error'] ) ) {
			$inspect['error'] = (string) $result['error'];
			self::update_object_meta( $object_id, $kind, self::META_LAST_STATUS, (string) wp_json_encode( $inspect ) );
			return [ 'inspect' => $inspect ];
		}

		$index                      = $result['inspectionResult']['indexStatusResult'] ?? [];
		$inspect['coverage']        = sanitize_text_field( (string) ( $index['coverageState'] ?? '' ) );
		$inspect['verdict']         = sanitize_text_field( (string) ( $index['verdict'] ?? '' ) );
		$inspect['lastCrawl']       = sanitize_text_field( (string) ( $index['lastCrawlTime'] ?? '' ) );
		$inspect['userCanonical']   = esc_url_raw( (string) ( $index['userCanonical'] ?? '' ) );
		$inspect['googleCanonical'] = esc_url_raw( (string) ( $index['googleCanonical'] ?? '' ) );
		$inspect['inspectLink']     = esc_url_raw( (string) ( $result['inspectionResult']['inspectionResultLink'] ?? '' ) );

		self::update_object_meta( $object_id, $kind, self::META_LAST_STATUS, (string) wp_json_encode( $inspect ) );
		if ( 'post' === $kind ) {
			HistoryLogger::on_inspect( $object_id, $inspect, $index_request );
		}

		return [ 'inspect' => $inspect ];
	}

	private function resolve_gsc_inspect_url( int $object_id, string $kind, string $site ): string {
		$stored = $this->read_stored_inspect( $object_id, $kind );
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
	public static function inventory_fields( int $object_id, string $kind = 'post' ): array {
		$kind    = 'term' === $kind ? 'term' : 'post';
		$raw     = self::get_object_meta( $object_id, $kind, self::META_LAST_STATUS );
		$inspect = [
			'coverage'    => '',
			'verdict'     => '',
			'lastCrawl'   => '',
			'inspectLink' => '',
			'error'       => '',
		];

		if ( '' !== $raw ) {
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
			'gsc_coverage'           => $inspect['coverage'],
			'gsc_verdict'            => $inspect['verdict'],
			'gsc_last_crawl'         => $inspect['lastCrawl'],
			'gsc_index_requested_at' => (int) self::get_object_meta( $object_id, $kind, self::META_REQUESTED_AT ),
			'gsc_inspect_link'       => $inspect['inspectLink'],
			'gsc_inspect_error'      => $inspect['error'],
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
	private function read_stored_inspect( int $object_id, string $kind ): array {
		$raw = self::get_object_meta( $object_id, $kind, self::META_LAST_STATUS );
		if ( '' === $raw ) {
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
			'adapter'      => $adapter->get_id(),
			'adapterLabel' => $adapter->get_label(),
			'seo'          => $scores['seo'],
			'readability'  => $scores['readability'],
			'label'        => (string) $scores['label'],
			'noFocus'      => (bool) $scores['no_focus'],
			'focusKeyword' => (string) ( $meta['focus_keyword'] ?? '' ),
		];
	}

	/**
	 * @return array{kind: string, id: int}|null
	 */
	private function target_from_request( WP_REST_Request $request ): ?array {
		$term_id = (int) $request->get_param( 'term_id' );
		if ( $term_id > 0 ) {
			$term = get_term( $term_id );
			if ( $term instanceof \WP_Term ) {
				return [
					'kind' => 'term',
					'id'   => $term_id,
				];
			}

			return null;
		}

		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id > 0 && get_post( $post_id ) instanceof \WP_Post ) {
			return [
				'kind' => 'post',
				'id'   => $post_id,
			];
		}

		return null;
	}

	private function object_url( string $kind, ?\WP_Post $post, ?\WP_Term $term ): string {
		if ( 'term' === $kind ) {
			if ( ! $term instanceof \WP_Term ) {
				return '';
			}
			$link = get_term_link( $term );

			return is_wp_error( $link ) ? '' : (string) $link;
		}

		return $post instanceof \WP_Post ? (string) get_permalink( $post ) : '';
	}

	private function is_indexable( string $kind, ?\WP_Post $post, ?\WP_Term $term ): bool {
		if ( 'term' === $kind ) {
			return $term instanceof \WP_Term;
		}

		return $post instanceof \WP_Post && 'publish' === $post->post_status;
	}

	private static function get_object_meta( int $object_id, string $kind, string $key ): string {
		$raw = 'term' === $kind
			? get_term_meta( $object_id, $key, true )
			: get_post_meta( $object_id, $key, true );

		if ( is_string( $raw ) ) {
			return $raw;
		}

		return is_numeric( $raw ) ? (string) $raw : '';
	}

	private static function update_object_meta( int $object_id, string $kind, string $key, string $value ): void {
		if ( 'term' === $kind ) {
			update_term_meta( $object_id, $key, $value );
			return;
		}

		update_post_meta( $object_id, $key, $value );
	}
}
