<?php
/**
 * SEO inventory REST endpoints.
 */

namespace Forwp\SeoHelper\Inventory\Rest;

use Forwp\SeoHelper\Inventory\BulkUpdater;
use Forwp\SeoHelper\Inventory\Module;
use Forwp\SeoHelper\Inventory\PostTypeDiscovery;
use Forwp\SeoHelper\Inventory\PriorityQueue;
use Forwp\SeoHelper\Inventory\Repository;
use Forwp\SeoHelper\Inventory\TaxonomyDiscovery;
use Forwp\SeoHelper\Multilingual\Registry as MultilingualRegistry;
use Forwp\SeoHelper\SeoMeta\Registry as SeoMetaRegistry;
use Forwp\SeoHelper\SeoMeta\YoastAdapter;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InventoryRest {
	private static $instance = null;

	private Repository $repository;
	private BulkUpdater $bulk_updater;
	private PriorityQueue $priority_queue;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->repository      = new Repository();
		$this->bulk_updater    = new BulkUpdater( $this->repository );
		$this->priority_queue  = new PriorityQueue( $this->repository );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			'forwp-seo-helper/v1',
			'/seo-inventory',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list_items' ],
					'permission_callback' => [ Auth::class, 'can_access' ],
					'args'                => $this->get_list_args(),
				],
			]
		);

		register_rest_route(
			'forwp-seo-helper/v1',
			'/seo-inventory/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => [ Auth::class, 'can_access' ],
				'args'                => [
					'post_type' => [ 'type' => 'string' ],
					'status'    => [ 'type' => 'string' ],
				],
			]
		);

		register_rest_route(
			'forwp-seo-helper/v1',
			'/seo-inventory/export',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'export_items' ],
				'permission_callback' => [ Auth::class, 'can_access' ],
				'args'                => array_merge(
					$this->get_list_args(),
					[
						'format' => [
							'type'    => 'string',
							'default' => 'json',
							'enum'    => [ 'json', 'csv' ],
						],
					]
				),
			]
		);

		register_rest_route(
			'forwp-seo-helper/v1',
			'/seo-inventory/bulk',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'bulk_update' ],
				'permission_callback' => [ Auth::class, 'can_access' ],
			]
		);

		register_rest_route(
			'forwp-seo-helper/v1',
			'/seo-inventory/(?P<post_id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ Auth::class, 'can_access' ],
					'args'                => [
						'post_id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'patch_item' ],
					'permission_callback' => [ Auth::class, 'can_access' ],
					'args'                => [
						'post_id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			'forwp-seo-helper/v1',
			'/seo-inventory/priority-queue/(?P<post_id>\d+)',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'assign_priority' ],
				'permission_callback' => [ Auth::class, 'can_edit_priority_queue' ],
				'args'                => [
					'post_id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			'forwp-seo-helper/v1',
			'/seo-inventory/priority-queue',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_priority_queue' ],
					'permission_callback' => [ Auth::class, 'can_access' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_priority_queue' ],
					'permission_callback' => [ Auth::class, 'can_edit_priority_queue' ],
				],
			]
		);

		register_rest_route(
			'forwp-seo-helper/v1',
			'/seo-inventory/meta',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_meta' ],
				'permission_callback' => [ Auth::class, 'can_access' ],
			]
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function get_list_args(): array {
		return [
			'page'      => [ 'type' => 'integer', 'default' => 1 ],
			'per_page'  => [ 'type' => 'integer', 'default' => 50 ],
			'post_type' => [ 'type' => 'string' ],
			'taxonomy'  => [ 'type' => 'string' ],
			'term'      => [ 'type' => 'integer' ],
			'lang'      => [ 'type' => 'string' ],
			'status'    => [ 'type' => 'string', 'default' => 'publish' ],
			'missing'   => [ 'type' => 'string' ],
			'search'    => [ 'type' => 'string' ],
			'min_score' => [ 'type' => 'integer' ],
			'max_score' => [ 'type' => 'integer' ],
		];
	}

	public function list_items( WP_REST_Request $request ) {
		if ( ! Module::get_instance()->is_enabled() ) {
			return new \WP_Error( 'inventory_disabled', __( 'SEO inventory is disabled.', '4wp-seo-helper' ), [ 'status' => 403 ] );
		}

		$result = $this->repository->query( $this->request_to_query_args( $request ) );

		return [
			'meta' => $this->build_response_meta(),
			'data' => $result,
		];
	}

	public function get_stats( WP_REST_Request $request ) {
		if ( ! Module::get_instance()->is_enabled() ) {
			return new \WP_Error( 'inventory_disabled', __( 'SEO inventory is disabled.', '4wp-seo-helper' ), [ 'status' => 403 ] );
		}

		return [
			'meta'  => $this->build_response_meta(),
			'stats' => $this->repository->get_stats(
				[
					'post_type' => (string) $request->get_param( 'post_type' ),
					'status'    => (string) $request->get_param( 'status' ),
				]
			),
		];
	}

	public function export_items( WP_REST_Request $request ) {
		if ( ! Module::get_instance()->is_enabled() ) {
			return new \WP_Error( 'inventory_disabled', __( 'SEO inventory is disabled.', '4wp-seo-helper' ), [ 'status' => 403 ] );
		}

		$args   = $this->request_to_query_args( $request );
		$args['per_page'] = 200;
		$args['page']     = 1;

		$all_items = [];
		do {
			$result    = $this->repository->query( $args );
			$all_items = array_merge( $all_items, $result['items'] );
			++$args['page'];
		} while ( count( $result['items'] ) === $args['per_page'] && $args['page'] <= 20 );

		$format = sanitize_key( (string) $request->get_param( 'format' ) );
		if ( 'csv' === $format ) {
			return $this->to_csv_response( $all_items );
		}

		return [
			'meta'  => $this->build_response_meta(),
			'count' => count( $all_items ),
			'items' => $all_items,
		];
	}

	public function get_item( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$record  = $this->repository->get_record( $post_id );

		if ( null === $record ) {
			return new \WP_Error( 'not_found', __( 'Record not found.', '4wp-seo-helper' ), [ 'status' => 404 ] );
		}

		return [
			'meta' => $this->build_response_meta(),
			'item' => $record,
		];
	}

	public function patch_item( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$fields  = $request->get_json_params();

		if ( ! is_array( $fields ) ) {
			$fields = [];
		}

		$result = $this->bulk_updater->update(
			[
				[
					'post_id' => $post_id,
					'fields'  => $fields,
				],
			]
		);

		if ( ! empty( $result['errors'] ) ) {
			return new \WP_Error(
				'update_failed',
				$result['errors'][0]['message'],
				[
					'status' => 400,
					'errors' => $result['errors'],
				]
			);
		}

		if ( $this->is_quick_edit_request( $request ) && 'yoast' === SeoMetaRegistry::get_active()->get_id() ) {
			YoastAdapter::rebuild_indexable( $post_id );
		}

		return [
			'meta'   => $this->build_response_meta(),
			'item'   => $this->repository->get_record( $post_id ),
			'updated'=> $result['updated'],
		];
	}

	public function bulk_update( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$items  = is_array( $params['items'] ?? null ) ? $params['items'] : [];

		if ( empty( $items ) ) {
			return new \WP_Error( 'invalid_items', __( 'Items array is required.', '4wp-seo-helper' ), [ 'status' => 400 ] );
		}

		$result = $this->bulk_updater->update( $items );

		return [
			'meta'    => $this->build_response_meta(),
			'updated' => $result['updated'],
			'errors'  => $result['errors'],
		];
	}

	public function get_priority_queue() {
		if ( ! Module::get_instance()->is_enabled() ) {
			return new \WP_Error( 'inventory_disabled', __( 'SEO inventory is disabled.', '4wp-seo-helper' ), [ 'status' => 403 ] );
		}

		return [
			'meta' => $this->build_response_meta(),
			'data' => $this->priority_queue->get_lanes_with_items(),
		];
	}

	public function update_priority_queue( WP_REST_Request $request ) {
		if ( ! Module::get_instance()->is_enabled() ) {
			return new \WP_Error( 'inventory_disabled', __( 'SEO inventory is disabled.', '4wp-seo-helper' ), [ 'status' => 403 ] );
		}

		$params = $request->get_json_params();
		$lanes  = is_array( $params['lanes'] ?? null ) ? $params['lanes'] : null;

		if ( null === $lanes ) {
			return new \WP_Error( 'invalid_lanes', __( 'Lanes object is required.', '4wp-seo-helper' ), [ 'status' => 400 ] );
		}

		$visible_ids = $params['visible_ids'] ?? null;
		if ( is_array( $visible_ids ) ) {
			$saved = $this->priority_queue->merge_visible_lanes( $lanes, $visible_ids );
		} else {
			$saved = $this->priority_queue->set_lanes( $lanes );
		}
		PriorityQueue::reset_cache();

		return [
			'meta'  => $this->build_response_meta(),
			'lanes' => $saved,
			'data'  => $this->priority_queue->get_lanes_with_items(),
		];
	}

	public function assign_priority( WP_REST_Request $request ) {
		if ( ! Module::get_instance()->is_enabled() ) {
			return new \WP_Error( 'inventory_disabled', __( 'SEO inventory is disabled.', '4wp-seo-helper' ), [ 'status' => 403 ] );
		}

		$post_id = (int) $request->get_param( 'post_id' );
		if ( null === $this->repository->get_record( $post_id ) ) {
			return new \WP_Error( 'not_found', __( 'Record not found.', '4wp-seo-helper' ), [ 'status' => 404 ] );
		}

		$params   = $request->get_json_params();
		$priority = array_key_exists( 'priority', $params ) ? $params['priority'] : null;

		if ( null === $priority || '' === $priority ) {
			$priority_value = null;
		} else {
			$priority_value = (int) $priority;
			if ( $priority_value < 1 || $priority_value > 3 ) {
				return new \WP_Error( 'invalid_priority', __( 'Priority must be 1, 2, 3, or empty.', '4wp-seo-helper' ), [ 'status' => 400 ] );
			}
		}

		$this->priority_queue->assign_post( $post_id, $priority_value );
		PriorityQueue::reset_cache();

		return [
			'meta' => $this->build_response_meta(),
			'item' => $this->repository->get_record( $post_id ),
		];
	}

	public function get_meta() {
		$seo  = SeoMetaRegistry::get_active();
		$ml   = MultilingualRegistry::get_active();
		$base = rest_url( 'forwp-seo-helper/v1/seo-inventory' );

		return [
			'version'            => defined( 'FORWP_SEO_HELPER_VERSION' ) ? FORWP_SEO_HELPER_VERSION : '0.0.0',
			'seo_adapter'        => [
				'id'    => $seo->get_id(),
				'label' => $seo->get_label(),
			],
			'multilingual'       => [
				'id'        => $ml->get_id(),
				'label'     => $ml->get_label(),
				'languages' => $ml->get_languages(),
			],
			'post_types'         => PostTypeDiscovery::get_slugs(),
			'post_types_labeled' => PostTypeDiscovery::get_labeled(),
			'taxonomies'         => TaxonomyDiscovery::get_labeled(),
			'inventory_enabled'  => Module::get_instance()->is_enabled(),
			'auth'               => [
				'type'   => 'bearer',
				'header' => 'Authorization: Bearer <token>',
			],
			'endpoints'          => [
				'list'           => $base,
				'stats'          => $base . '/stats',
				'export'         => $base . '/export',
				'bulk'           => $base . '/bulk',
				'meta'           => $base . '/meta',
				'priority_queue' => $base . '/priority-queue',
			],
			'writable_fields'    => [
				'seo_title',
				'meta_description',
				'focus_keyword',
				'focus_keyphrases',
				'canonical',
				'noindex',
				'og_title',
				'og_description',
				'og_image',
			],
			'filters'            => [
				'post_type',
				'taxonomy',
				'term',
				'lang',
				'status',
				'missing',
				'search',
				'min_score',
				'max_score',
				'page',
				'per_page',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function request_to_query_args( WP_REST_Request $request ): array {
		return [
			'page'      => (int) $request->get_param( 'page' ),
			'per_page'  => (int) $request->get_param( 'per_page' ),
			'post_type' => (string) $request->get_param( 'post_type' ),
			'taxonomy'  => (string) $request->get_param( 'taxonomy' ),
			'term'      => (int) $request->get_param( 'term' ),
			'lang'      => (string) $request->get_param( 'lang' ),
			'status'    => (string) $request->get_param( 'status' ),
			'missing'   => (string) $request->get_param( 'missing' ),
			'search'    => (string) $request->get_param( 'search' ),
			'min_score' => $request->get_param( 'min_score' ),
			'max_score' => $request->get_param( 'max_score' ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_response_meta(): array {
		$seo = SeoMetaRegistry::get_active();
		$ml  = MultilingualRegistry::get_active();

		return [
			'seo_adapter'  => $seo->get_id(),
			'multilingual' => $ml->get_id(),
		];
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	private function to_csv_response( array $items ): \WP_REST_Response {
		$columns = [
			'post_id',
			'lang',
			'post_type',
			'status',
			'wp_title',
			'url',
			'seo_title',
			'meta_description',
			'focus_keyword',
			'focus_keyphrases_text',
			'priority',
			'queue_position',
			'completeness',
			'missing',
			'taxonomies_text',
		];

		$lines = [ implode( ',', $columns ) ];

		foreach ( $items as $item ) {
			$row = [];
			foreach ( $columns as $column ) {
				$value = $item[ $column ] ?? '';
				if ( 'missing' === $column && is_array( $value ) ) {
					$value = implode( '|', $value );
				}
				$row[] = $this->csv_escape( (string) $value );
			}
			$lines[] = implode( ',', $row );
		}

		$response = new \WP_REST_Response( implode( "\n", $lines ), 200 );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="seo-inventory.csv"' );

		return $response;
	}

	private function is_quick_edit_request( WP_REST_Request $request ): bool {
		return 'quick-edit' === strtolower( (string) $request->get_header( 'x_forwp_seo_context' ) );
	}

	private function csv_escape( string $value ): string {
		$value = str_replace( '"', '""', $value );
		return '"' . $value . '"';
	}
}
