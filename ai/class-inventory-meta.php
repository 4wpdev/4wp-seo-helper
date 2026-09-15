<?php
/**
 * SEO inventory case: title, description, focus keyphrases.
 *
 * @package Forwp\SeoHelper
 */

namespace Forwp\SeoHelper\Ai;

use Forwp\SeoHelper\Admin\Menu;
use Forwp\SeoHelper\Gsc\Admin as GscAdmin;
use Forwp\SeoHelper\Gsc\Module as GscModule;
use Forwp\SeoHelper\Gsc\PageMetrics;
use Forwp\SeoHelper\Inventory\Repository;
use ForWP\AI\Client;
use ForWP\AI\Panel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entity case: inventory quick-edit SEO fields from one prompt.
 */
class Inventory_Meta {
	public const CONTEXT_LINK    = 'link';
	public const CONTEXT_CONTENT = 'content';

	/**
	 * @return list<array{id: string, field: string, label: string}>
	 */
	public static function slots(): array {
		return [
			[
				'id'    => 'seo_title',
				'field' => '.forwp-seo-field-seo-title',
				'label' => __( 'SEO title', '4wp-seo-helper' ),
			],
			[
				'id'    => 'meta_description',
				'field' => '.forwp-seo-field-meta-description',
				'label' => __( 'Meta description', '4wp-seo-helper' ),
			],
			[
				'id'    => 'focus_keyphrases',
				'field' => '.forwp-seo-field-focus-keyphrases',
				'label' => __( 'Focus keyphrases', '4wp-seo-helper' ),
			],
		];
	}

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 20 );
	}

	public static function register_routes(): void {
		register_rest_route(
			'forwp-seo-helper/v1',
			'/posts/(?P<id>\d+)/ai-prompt',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_prompt' ],
				'permission_callback' => [ __CLASS__, 'can_edit_post' ],
				'args'                => [
					'id'      => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'context' => [
						'type'              => 'string',
						'enum'              => [ self::CONTEXT_LINK, self::CONTEXT_CONTENT ],
						'default'           => self::CONTEXT_CONTENT,
						'sanitize_callback' => [ __CLASS__, 'sanitize_context' ],
					],
				],
			]
		);

		register_rest_route(
			'forwp-seo-helper/v1',
			'/posts/(?P<id>\d+)/ai-generate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'generate_fields' ],
				'permission_callback' => [ __CLASS__, 'can_edit_post' ],
				'args'                => [
					'id'      => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'prompt'  => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'context' => [
						'type'              => 'string',
						'enum'              => [ self::CONTEXT_LINK, self::CONTEXT_CONTENT ],
						'default'           => self::CONTEXT_CONTENT,
						'sanitize_callback' => [ __CLASS__, 'sanitize_context' ],
					],
				],
			]
		);
	}

	/**
	 * @param mixed $value Raw context.
	 */
	public static function sanitize_context( $value ): string {
		$context = sanitize_key( (string) $value );
		return self::CONTEXT_LINK === $context ? self::CONTEXT_LINK : self::CONTEXT_CONTENT;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public static function can_edit_post( $request ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$post = get_post( (int) $request['id'] );
		return $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue_assets( $hook ): void {
		unset( $hook );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'inventory';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( Menu::INVENTORY_PAGE_SLUG !== $page || 'queue' === $view ) {
			return;
		}

		if ( ! Client::is_available() ) {
			return;
		}

		Panel::enqueue(
			[
				'slots'           => self::slots(),
				'prompt_path'     => '/forwp-seo-helper/v1/posts/{id}/ai-prompt',
				'generate_path'   => '/forwp-seo-helper/v1/posts/{id}/ai-generate',
				'lazy'            => true,
				'default_context' => self::CONTEXT_CONTENT,
				'context_modes'   => [
					[
						'id'    => self::CONTEXT_LINK,
						'label' => __( 'Page URL', '4wp-seo-helper' ),
					],
					[
						'id'    => self::CONTEXT_CONTENT,
						'label' => __( 'Page content', '4wp-seo-helper' ),
					],
				],
				'storage_key'     => 'forwpAiSeoInventoryPos',
				'i18n'            => [
					'generate'    => __( 'Generate with AI', '4wp-seo-helper' ),
					'fab'         => __( 'AI prompt', '4wp-seo-helper' ),
					'title'       => __( 'SEO copy', '4wp-seo-helper' ),
					'prompt'      => __( 'Prompt', '4wp-seo-helper' ),
					'send'        => __( 'Send', '4wp-seo-helper' ),
					'clear'       => __( 'Clear', '4wp-seo-helper' ),
					'restore'     => __( 'Restore default', '4wp-seo-helper' ),
					'apply'       => __( 'Apply', '4wp-seo-helper' ),
					'applyAll'    => __( 'Apply all', '4wp-seo-helper' ),
					'refine'      => __( 'Refine', '4wp-seo-helper' ),
					'close'       => __( 'Close', '4wp-seo-helper' ),
					'move'        => __( 'Drag to move', '4wp-seo-helper' ),
					'working'     => __( 'Generating…', '4wp-seo-helper' ),
					'loading'     => __( 'Loading prompt…', '4wp-seo-helper' ),
					'done'        => __( 'Inserted into the form. Review, then Update.', '4wp-seo-helper' ),
					'confirm'     => __( 'Replace existing values in the form?', '4wp-seo-helper' ),
					'empty'       => __( 'Write or restore the prompt first.', '4wp-seo-helper' ),
					'error'       => __( 'Could not generate SEO copy.', '4wp-seo-helper' ),
					'contextHelp' => __( 'URL uses fewer tokens. Page content is more accurate (plain text from WordPress, not a live crawl).', '4wp-seo-helper' ),
				],
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_prompt( $request ) {
		$post = self::get_editable_post( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! Client::is_available() ) {
			return self::unavailable_error();
		}

		$context = self::sanitize_context( $request->get_param( 'context' ) );

		return new \WP_REST_Response(
			[
				'prompt'  => self::build_prompt( $post, $context ),
				'context' => $context,
			],
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function generate_fields( $request ) {
		$post = self::get_editable_post( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! Client::is_available() ) {
			return self::unavailable_error();
		}

		$keys   = self::slot_ids();
		$fields = Client::generate_json(
			(string) $request->get_param( 'prompt' ),
			self::system_instruction(),
			400,
			$keys
		);

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( ! self::has_copy( $fields ) ) {
			return new \WP_Error(
				'forwp_ai_empty',
				__( 'The model returned empty text.', '4wp-seo-helper' ),
				[ 'status' => 502 ]
			);
		}

		return new \WP_REST_Response(
			[
				'fields' => $fields,
			],
			200
		);
	}

	/**
	 * @param int $post_id Post ID.
	 * @return \WP_Post|\WP_Error
	 */
	private static function get_editable_post( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'forwp_seo_post_invalid',
				__( 'Item not found.', '4wp-seo-helper' ),
				[ 'status' => 404 ]
			);
		}

		return $post;
	}

	/**
	 * @return \WP_Error
	 */
	private static function unavailable_error() {
		return new \WP_Error(
			'forwp_seo_ai_unavailable',
			__( 'AI is not available. Configure a connector under Settings → Connectors.', '4wp-seo-helper' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * @return string[]
	 */
	private static function slot_ids(): array {
		$ids = [];
		foreach ( self::slots() as $slot ) {
			$ids[] = $slot['id'];
		}

		return $ids;
	}

	/**
	 * @param array<string, mixed> $fields Mapped fields.
	 */
	private static function has_copy( array $fields ): bool {
		foreach ( $fields as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return true;
			}
			if ( is_array( $value ) && ! empty( $value ) ) {
				return true;
			}
		}

		return false;
	}

	private static function system_instruction(): string {
		return implode(
			"\n",
			[
				'You write on-page SEO copy for a public website.',
				'Output JSON only. No markdown, no extra keys.',
				'Schema: {"seo_title":"","meta_description":"","focus_keyphrases":""}',
				'seo_title: document title, under 60 characters, no site name suffix.',
				'meta_description: meta description, under 160 characters.',
				'focus_keyphrases: 3 to 5 phrases, one per line. First line is the primary keyphrase.',
				'Use the language from the prompt. Do not invent facts beyond the provided context.',
			]
		);
	}

	/**
	 * Connectors cannot browse. URL mode is cheap tokens; content mode sends stripped post_content.
	 *
	 * @param \WP_Post $post    Post.
	 * @param string   $context link|content.
	 */
	private static function build_prompt( \WP_Post $post, string $context ): string {
		$record  = ( new Repository() )->get_record( $post->ID );
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$url     = get_permalink( $post );
		$excerpt = self::excerpt_text( $post );
		$queries = self::search_queries( $post->ID );

		$lines   = [];
		$lines[] = 'Write SEO title, meta description, and focus keyphrases for this page. Fill every field in the JSON schema.';
		$lines[] = 'Site: ' . $site;
		$lines[] = 'Language: ' . self::language_label( $post->ID );
		$lines[] = 'WordPress title: ' . get_the_title( $post );
		$lines[] = 'URL: ' . ( is_string( $url ) ? $url : '' );
		$lines[] = 'Post type: ' . $post->post_type;

		if ( '' !== $excerpt ) {
			$lines[] = 'Excerpt: ' . $excerpt;
		}

		if ( ! empty( $queries ) ) {
			$lines[] = 'Search Console queries already sending traffic:';
			foreach ( $queries as $query ) {
				$lines[] = '- ' . $query;
			}
		}

		$current = [
			'seo_title'         => is_array( $record ) ? trim( (string) ( $record['seo_title'] ?? '' ) ) : '',
			'meta_description'  => is_array( $record ) ? trim( (string) ( $record['meta_description'] ?? '' ) ) : '',
			'focus_keyphrases'  => is_array( $record ) ? trim( (string) ( $record['focus_keyphrases_text'] ?? '' ) ) : '',
		];
		$filled = [];
		foreach ( $current as $key => $value ) {
			if ( '' !== $value ) {
				$filled[] = $key . ': ' . $value;
			}
		}
		if ( ! empty( $filled ) ) {
			$lines[] = 'Current values (revise if useful, do not copy blindly):';
			foreach ( $filled as $line ) {
				$lines[] = '- ' . $line;
			}
		}

		if ( self::CONTEXT_CONTENT === $context ) {
			$body = self::page_text( $post );
			if ( '' !== $body ) {
				$lines[] = 'Page content (plain text from WordPress, truncated):';
				$lines[] = $body;
			}
		} else {
			$lines[] = 'Context mode: URL only. Do not invent body facts. Use the title, URL, excerpt, and queries.';
		}

		$lines[] = 'Do not invent facts beyond this context.';

		return implode( "\n", $lines );
	}

	private static function excerpt_text( \WP_Post $post ): string {
		$excerpt = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
		if ( '' !== $excerpt ) {
			return $excerpt;
		}

		$body = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$body = trim( preg_replace( '/\s+/u', ' ', $body ) ?? $body );

		return wp_trim_words( $body, 40, '' );
	}

	private static function page_text( \WP_Post $post ): string {
		$text = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
		if ( '' === $text ) {
			return '';
		}

		$max = 2800;
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text ) > $max ) {
				return mb_substr( $text, 0, $max ) . '…';
			}
			return $text;
		}

		if ( strlen( $text ) > $max ) {
			return substr( $text, 0, $max ) . '…';
		}

		return $text;
	}

	/**
	 * @return string[]
	 */
	private static function search_queries( int $post_id ): array {
		if ( ! GscModule::get_instance()->is_enabled() ) {
			return [];
		}

		$property = GscAdmin::get_site_property();
		if ( '' === $property ) {
			return [];
		}

		$record = ( new Repository() )->get_record( $post_id );
		if ( ! is_array( $record ) ) {
			return [];
		}

		$enriched = ( new PageMetrics() )->enrich_records( [ $record ], $property );
		$rows     = $enriched[0]['gsc_top_queries'] ?? [];
		$out      = [];

		foreach ( $rows as $row ) {
			$query = is_array( $row ) ? trim( (string) ( $row['query'] ?? '' ) ) : trim( (string) $row );
			if ( '' === $query ) {
				continue;
			}
			$out[] = $query;
			if ( count( $out ) >= 8 ) {
				break;
			}
		}

		return $out;
	}

	private static function language_label( int $post_id ): string {
		$slug = '';
		if ( function_exists( 'pll_get_post_language' ) ) {
			$got  = pll_get_post_language( $post_id );
			$slug = is_string( $got ) ? $got : '';
		}

		if ( '' === $slug ) {
			$locale = get_locale();
			$slug   = strtolower( substr( (string) $locale, 0, 2 ) );
		}

		$map = [
			'en' => 'English',
			'es' => 'Spanish',
			'de' => 'German',
			'ru' => 'Russian',
			'uk' => 'Ukrainian',
		];

		return isset( $map[ $slug ] ) ? $map[ $slug ] : $slug;
	}
}
