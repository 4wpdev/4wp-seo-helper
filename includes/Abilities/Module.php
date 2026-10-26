<?php
/**
 * WordPress Abilities API: SEO meta abilities for AI agents (MCP via 4wp-mcp-connector).
 *
 * Abilities read and write SEO meta through the active SEO plugin adapter
 * (Yoast SEO, All in One SEO), so values land in that plugin's own meta keys.
 */

namespace Forwp\SeoHelper\Abilities;

use Forwp\SeoHelper\Inventory\BulkUpdater;
use Forwp\SeoHelper\Inventory\Module as InventoryModule;
use Forwp\SeoHelper\Inventory\Repository;
use Forwp\SeoHelper\SeoMeta\Registry as SeoMetaRegistry;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Module {
	public const CATEGORY = 'forwp-seo-helper';

	private const QUERY_BATCH = 500;

	private static $instance = null;

	private Repository $repository;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->repository = new Repository();

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( '4WP SEO Helper', '4wp-seo-helper' ),
				'description' => __( 'SEO meta of posts and pages: audit, search, edit.', '4wp-seo-helper' ),
			]
		);
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! InventoryModule::get_instance()->is_enabled() ) {
			return;
		}

		$readonly = [
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		];

		wp_register_ability(
			'forwp-seo-helper/list-missing-meta',
			[
				'label'               => __( 'List posts with missing SEO meta', '4wp-seo-helper' ),
				'description'         => __( 'Published posts and pages whose SEO title and/or meta description are not set manually (the SEO plugin falls back to its template). filter "none": both missing; "incomplete": exactly one missing; "any": at least one. Shows the template value the SEO plugin outputs instead.', '4wp-seo-helper' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'filter'    => [
							'type'        => 'string',
							'enum'        => [ 'none', 'incomplete', 'any' ],
							'default'     => 'any',
							'description' => 'none = no SEO title and no description; incomplete = one of them missing; any = at least one missing.',
						],
						'post_type' => [
							'type'        => 'string',
							'description' => 'Post type slug, e.g. post or page. Empty = all supported types.',
						],
						'page'      => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'per_page'  => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						],
					],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'items'      => [
							'type'  => 'array',
							'items' => $this->item_schema(),
						],
						'total'      => [ 'type' => 'integer' ],
						'page'       => [ 'type' => 'integer' ],
						'per_page'   => [ 'type' => 'integer' ],
						'seo_plugin' => [ 'type' => 'string' ],
					],
					'required'   => [ 'items', 'total', 'page', 'per_page', 'seo_plugin' ],
				],
				'execute_callback'    => [ $this, 'list_missing_meta' ],
				'permission_callback' => [ $this, 'can_read' ],
				'meta'                => [ 'annotations' => $readonly ],
			]
		);

		wp_register_ability(
			'forwp-seo-helper/get-meta',
			[
				'label'               => __( 'Get SEO meta of a post', '4wp-seo-helper' ),
				'description'         => __( 'SEO meta of one post or page by ID or slug: values set manually (raw) and what search engines see after SEO plugin templates (effective).', '4wp-seo-helper' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->target_schema( [] ),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'         => [ 'type' => 'integer' ],
						'title'      => [ 'type' => 'string' ],
						'slug'       => [ 'type' => 'string' ],
						'post_type'  => [ 'type' => 'string' ],
						'url'        => [ 'type' => 'string' ],
						'edit_url'   => [ 'type' => 'string' ],
						'seo_plugin' => [ 'type' => 'string' ],
						'raw'        => [ 'type' => 'object' ],
						'effective'  => [ 'type' => 'object' ],
						'missing'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
					'required'   => [ 'id', 'seo_plugin', 'raw', 'effective', 'missing' ],
				],
				'execute_callback'    => [ $this, 'get_meta' ],
				'permission_callback' => [ $this, 'can_read' ],
				'meta'                => [ 'annotations' => $readonly ],
			]
		);

		wp_register_ability(
			'forwp-seo-helper/update-meta',
			[
				'label'               => __( 'Update SEO meta of a post', '4wp-seo-helper' ),
				'description'         => __( 'Sets SEO title, meta description and/or focus keyword of one post or page by ID or slug. Saved into the active SEO plugin (e.g. Yoast SEO) and live immediately. Returns previous values (before) for rollback. Pass an empty string to clear a field and fall back to the SEO plugin template.', '4wp-seo-helper' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->target_schema(
					[
						'title'         => [
							'type'        => 'string',
							'description' => 'SEO title. Yoast variables like %%sitename%% are allowed.',
						],
						'description'   => [
							'type'        => 'string',
							'description' => 'Meta description, ideally 120–160 characters.',
						],
						'focus_keyword' => [
							'type'        => 'string',
							'description' => 'Primary focus keyword / keyphrase.',
						],
					]
				),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'         => [ 'type' => 'integer' ],
						'seo_plugin' => [ 'type' => 'string' ],
						'before'     => [ 'type' => 'object' ],
						'after'      => [ 'type' => 'object' ],
						'edit_url'   => [ 'type' => 'string' ],
					],
					'required'   => [ 'id', 'seo_plugin', 'before', 'after' ],
				],
				'execute_callback'    => [ $this, 'update_meta' ],
				'permission_callback' => [ $this, 'can_edit' ],
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			]
		);

		wp_register_ability(
			'forwp-seo-helper/search',
			[
				'label'               => __( 'Search posts for SEO', '4wp-seo-helper' ),
				'description'         => __( 'Finds published posts and pages by title / slug (query) and/or by focus keyword (keyword). Returns IDs to use with get-meta and update-meta.', '4wp-seo-helper' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'query'     => [
							'type'        => 'string',
							'description' => 'Words from the title, or an exact slug.',
						],
						'keyword'   => [
							'type'        => 'string',
							'description' => 'Part of the focus keyword / keyphrases.',
						],
						'post_type' => [
							'type'        => 'string',
							'description' => 'Post type slug. Empty = all supported types.',
						],
						'limit'     => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 20,
						],
					],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'items'      => [
							'type'  => 'array',
							'items' => $this->item_schema(),
						],
						'seo_plugin' => [ 'type' => 'string' ],
					],
					'required'   => [ 'items', 'seo_plugin' ],
				],
				'execute_callback'    => [ $this, 'search' ],
				'permission_callback' => [ $this, 'can_read' ],
				'meta'                => [ 'annotations' => $readonly ],
			]
		);
	}

	public function can_read(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * @param array<string, mixed>|null $input
	 */
	public function can_edit( $input = null ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$post = $this->resolve_post( is_array( $input ) ? $input : [] );

		// Unknown targets pass here and fail in execute with a clear error.
		return ! $post instanceof WP_Post || current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * @param array<string, mixed>|null $input
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_missing_meta( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		$adapter_error = $this->adapter_error();
		if ( null !== $adapter_error ) {
			return $adapter_error;
		}

		$filter   = in_array( $input['filter'] ?? 'any', [ 'none', 'incomplete', 'any' ], true ) ? (string) ( $input['filter'] ?? 'any' ) : 'any';
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );

		$matched = [];
		foreach ( $this->records( (string) ( $input['post_type'] ?? '' ), '' ) as $record ) {
			$count = count( array_intersect( [ 'title', 'description' ], (array) $record['missing'] ) );

			if ( ( 'none' === $filter && 2 === $count )
				|| ( 'incomplete' === $filter && 1 === $count )
				|| ( 'any' === $filter && $count > 0 ) ) {
				$matched[] = $this->item( $record );
			}
		}

		return [
			'items'      => array_slice( $matched, ( $page - 1 ) * $per_page, $per_page ),
			'total'      => count( $matched ),
			'page'       => $page,
			'per_page'   => $per_page,
			'seo_plugin' => SeoMetaRegistry::get_active()->get_id(),
		];
	}

	/**
	 * @param array<string, mixed>|null $input
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_meta( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		$adapter_error = $this->adapter_error();
		if ( null !== $adapter_error ) {
			return $adapter_error;
		}

		$record = $this->target_record( $input );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$item = $this->item( $record );

		return [
			'id'         => $item['id'],
			'title'      => $item['title'],
			'slug'       => $item['slug'],
			'post_type'  => $item['post_type'],
			'url'        => $item['url'],
			'edit_url'   => $item['edit_url'],
			'seo_plugin' => (string) $record['seo_adapter'],
			'raw'        => $this->raw( $record ),
			'effective'  => [
				'title'       => (string) $record['seo_title'],
				'description' => (string) $record['meta_description'],
			],
			'missing'    => array_values( (array) $record['missing'] ),
		];
	}

	/**
	 * @param array<string, mixed>|null $input
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_meta( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		$adapter_error = $this->adapter_error();
		if ( null !== $adapter_error ) {
			return $adapter_error;
		}

		$record = $this->target_record( $input );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$fields = [];
		foreach ( [ 'title', 'description', 'focus_keyword' ] as $key ) {
			if ( array_key_exists( $key, $input ) && is_string( $input[ $key ] ) ) {
				$fields[ $key ] = $input[ $key ];
			}
		}
		if ( [] === $fields ) {
			return new WP_Error( 'no_fields', __( 'Provide at least one of: title, description, focus_keyword.', '4wp-seo-helper' ) );
		}

		$post_id = (int) $record['post_id'];
		$before  = $this->raw( $record );
		$result  = ( new BulkUpdater( $this->repository ) )->update(
			[
				[
					'post_id' => $post_id,
					'fields'  => $fields,
				],
			]
		);

		if ( 1 !== $result['updated'] ) {
			$message = $result['errors'][0]['message'] ?? __( 'Failed to write SEO meta.', '4wp-seo-helper' );
			return new WP_Error( 'update_failed', $message );
		}

		$after = $this->repository->get_record( $post_id );

		return [
			'id'         => $post_id,
			'seo_plugin' => (string) $record['seo_adapter'],
			'before'     => $before,
			'after'      => null === $after ? [] : $this->raw( $after ),
			'edit_url'   => (string) get_edit_post_link( $post_id, 'raw' ),
		];
	}

	/**
	 * @param array<string, mixed>|null $input
	 * @return array<string, mixed>|WP_Error
	 */
	public function search( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		$adapter_error = $this->adapter_error();
		if ( null !== $adapter_error ) {
			return $adapter_error;
		}

		$query     = trim( (string) ( $input['query'] ?? '' ) );
		$keyword   = trim( (string) ( $input['keyword'] ?? '' ) );
		$post_type = (string) ( $input['post_type'] ?? '' );
		$limit     = min( 50, max( 1, (int) ( $input['limit'] ?? 20 ) ) );

		if ( '' === $query && '' === $keyword ) {
			return new WP_Error( 'no_query', __( 'Provide query and/or keyword.', '4wp-seo-helper' ) );
		}

		$items = [];

		// Exact slug first.
		if ( '' !== $query ) {
			$post = $this->resolve_post(
				[
					'slug'      => sanitize_title( $query ),
					'post_type' => $post_type,
				]
			);
			$record = $post instanceof WP_Post ? $this->repository->get_record( $post->ID ) : null;
			if ( null !== $record && $this->matches_keyword( $record, $keyword ) ) {
				$items[ (int) $record['post_id'] ] = $this->item( $record );
			}
		}

		foreach ( $this->records( $post_type, $query ) as $record ) {
			if ( count( $items ) >= $limit ) {
				break;
			}
			if ( $this->matches_keyword( $record, $keyword ) ) {
				$items[ (int) $record['post_id'] ] = $this->item( $record );
			}
		}

		return [
			'items'      => array_slice( array_values( $items ), 0, $limit ),
			'seo_plugin' => SeoMetaRegistry::get_active()->get_id(),
		];
	}

	/**
	 * All published post records (no terms) of the inventory scope.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function records( string $post_type, string $search ): array {
		$post_type = sanitize_key( $post_type );
		$types     = '' === $post_type ? $this->repository->get_supported_post_types() : [ $post_type ];
		$records   = [];

		foreach ( $types as $type ) {
			$page = 1;
			do {
				$result = $this->repository->query(
					[
						'post_type' => $type,
						'search'    => $search,
						'page'      => $page,
						'per_page'  => self::QUERY_BATCH,
					]
				);
				foreach ( $result['items'] as $record ) {
					if ( 'post' === ( $record['object_kind'] ?? 'post' ) ) {
						$records[] = $record;
					}
				}
				++$page;
			} while ( count( $result['items'] ) === self::QUERY_BATCH && ( $page - 1 ) * self::QUERY_BATCH < (int) $result['total'] );
		}

		return $records;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	private function target_record( array $input ) {
		if ( empty( $input['id'] ) && empty( $input['slug'] ) ) {
			return new WP_Error( 'no_target', __( 'Provide id or slug.', '4wp-seo-helper' ) );
		}

		$post = $this->resolve_post( $input );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', '4wp-seo-helper' ) );
		}

		$record = $this->repository->get_record( $post->ID );
		if ( null === $record ) {
			return new WP_Error( 'out_of_scope', __( 'Post is not in the SEO inventory scope.', '4wp-seo-helper' ) );
		}

		return $record;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function resolve_post( array $input ): ?WP_Post {
		$id = (int) ( $input['id'] ?? 0 );
		if ( $id > 0 ) {
			$post = get_post( $id );
			return $post instanceof WP_Post ? $post : null;
		}

		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return null;
		}

		$post_type = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
		$types     = '' === $post_type ? $this->repository->get_supported_post_types() : [ $post_type ];

		foreach ( $types as $type ) {
			$post = get_page_by_path( $slug, OBJECT, $type );
			if ( $post instanceof WP_Post ) {
				return $post;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function matches_keyword( array $record, string $keyword ): bool {
		if ( '' === $keyword ) {
			return true;
		}

		$haystack = (string) ( $record['focus_keyphrases_text'] ?? '' ) . ' ' . (string) ( $record['focus_keyword'] ?? '' );

		return false !== mb_stripos( $haystack, $keyword );
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private function item( array $record ): array {
		$post_id = (int) $record['post_id'];

		return [
			'id'                          => $post_id,
			'title'                       => (string) $record['wp_title'],
			'slug'                        => (string) get_post_field( 'post_name', $post_id ),
			'post_type'                   => (string) $record['post_type'],
			'url'                         => (string) $record['url'],
			'edit_url'                    => (string) get_edit_post_link( $post_id, 'raw' ),
			'seo_title'                   => (string) $record['seo_title'],
			'seo_title_is_template'       => '' === (string) $record['seo_title_raw'],
			'seo_description'             => (string) $record['meta_description'],
			'seo_description_is_template' => '' === (string) $record['meta_description_raw'],
			'focus_keyword'               => (string) $record['focus_keyword'],
			'missing'                     => array_values( (array) $record['missing'] ),
		];
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private function raw( array $record ): array {
		return [
			'title'         => (string) $record['seo_title_raw'],
			'description'   => (string) $record['meta_description_raw'],
			'focus_keyword' => (string) $record['focus_keyword'],
			'canonical'     => (string) $record['canonical'],
			'noindex'       => (bool) $record['noindex'],
		];
	}

	private function adapter_error(): ?WP_Error {
		if ( 'none' === SeoMetaRegistry::get_active()->get_id() ) {
			return new WP_Error( 'no_seo_adapter', __( 'No supported SEO plugin is active (Yoast SEO or All in One SEO).', '4wp-seo-helper' ) );
		}

		return null;
	}

	/**
	 * @param array<string, array<string, mixed>> $extra
	 * @return array<string, mixed>
	 */
	private function target_schema( array $extra ): array {
		return [
			'type'                 => 'object',
			'properties'           => array_merge(
				[
					'id'        => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Post ID. Either id or slug is required.',
					],
					'slug'      => [
						'type'        => 'string',
						'description' => 'Post slug. Either id or slug is required.',
					],
					'post_type' => [
						'type'        => 'string',
						'description' => 'Post type for slug lookup, e.g. post or page. Empty = all supported types.',
					],
				],
				$extra
			),
			'additionalProperties' => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function item_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'                          => [ 'type' => 'integer' ],
				'title'                       => [ 'type' => 'string' ],
				'slug'                        => [ 'type' => 'string' ],
				'post_type'                   => [ 'type' => 'string' ],
				'url'                         => [ 'type' => 'string' ],
				'edit_url'                    => [ 'type' => 'string' ],
				'seo_title'                   => [ 'type' => 'string' ],
				'seo_title_is_template'       => [ 'type' => 'boolean' ],
				'seo_description'             => [ 'type' => 'string' ],
				'seo_description_is_template' => [ 'type' => 'boolean' ],
				'focus_keyword'               => [ 'type' => 'string' ],
				'missing'                     => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		];
	}
}
