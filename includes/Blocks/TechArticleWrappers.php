<?php
/**
 * TechArticle section wrappers — any inner blocks, Schema.org semantics (4wp-faq pattern).
 */

namespace Forwp\SeoHelper\Blocks;

use Forwp\SeoHelper\Core\Release;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TechArticleWrappers {
	public const GOAL_BLOCK    = 'forwp-seo/techarticle-goal';
	public const CONTEXT_BLOCK = 'forwp-seo/techarticle-context';
	public const ISSUES_BLOCK  = 'forwp-seo/techarticle-issues';
	public const STEPS_BLOCK      = 'forwp-seo/techarticle-steps';
	public const COMPLETION_BLOCK = 'forwp-seo/techarticle-completion';

	/** @deprecated Use STEPS_BLOCK — kept for schema/sidebar compatibility. */
	public const STEP_BLOCK = 'forwp-seo/techarticle-step';

	private const SCRIPT_HANDLE = 'forwp-seo-techarticle-wrappers';

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_blocks' ], 9 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
	}

	public function enqueue_styles(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! has_block( self::COMPLETION_BLOCK, $post ) ) {
			return;
		}

		wp_enqueue_style(
			'forwp-seo-techarticle-completion',
			FORWP_SEO_HELPER_URL . 'assets/css/techarticle-completion.css',
			[],
			FORWP_SEO_HELPER_VERSION
		);
	}

	public function register_blocks(): void {
		wp_register_script(
			self::SCRIPT_HANDLE,
			FORWP_SEO_HELPER_URL . 'assets/js/techarticle-wrappers.js',
			[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n' ],
			FORWP_SEO_HELPER_VERSION,
			true
		);

		wp_register_style(
			'forwp-seo-techarticle-completion',
			FORWP_SEO_HELPER_URL . 'assets/css/techarticle-completion.css',
			[],
			FORWP_SEO_HELPER_VERSION
		);

		$wrappers = [
			self::GOAL_BLOCK    => 'forwp-seo-techarticle-goal',
			self::CONTEXT_BLOCK => 'forwp-seo-techarticle-context',
			self::ISSUES_BLOCK  => 'forwp-seo-techarticle-issues',
			self::STEPS_BLOCK   => 'forwp-seo-techarticle-steps',
		];

		foreach ( $wrappers as $block_name => $class_name ) {
			register_block_type(
				$block_name,
				[
					'api_version'     => 3,
					'editor_script'   => self::SCRIPT_HANDLE,
					'supports'        => Release::block_inserter_supports(),
					'render_callback' => function ( array $attributes, string $content, \WP_Block $block ) use ( $class_name ): string {
						return $this->render_wrapper( $class_name, $content, $attributes, 'section', $block );
					},
				]
			);
		}

		// Legacy inner step wrapper — optional inside Steps; still parsed for schema.
		register_block_type(
			self::STEP_BLOCK,
			[
				'api_version'     => 3,
				'editor_script'   => self::SCRIPT_HANDLE,
				'supports'        => Release::block_inserter_supports(),
				'attributes'      => [
					'stepNumber' => [
						'type' => 'number',
					],
				],
				'render_callback' => function ( array $attributes, string $content, \WP_Block $block ): string {
					return $this->render_wrapper( 'forwp-seo-techarticle-step', $content, $attributes, 'div', $block );
				},
			]
		);

		register_block_type(
			self::COMPLETION_BLOCK,
			[
				'api_version'     => 3,
				'supports'        => Release::block_inserter_supports(),
				'attributes'      => [
					'perfect'  => [
						'type'    => 'array',
						'default' => [],
						'items'   => [
							'type' => 'string',
						],
					],
					'optimize' => [
						'type'    => 'array',
						'default' => [],
						'items'   => [
							'type' => 'string',
						],
					],
					'next'     => [
						'type'    => 'array',
						'default' => [],
						'items'   => [
							'type' => 'string',
						],
					],
				],
				'editor_script'   => self::SCRIPT_HANDLE,
				'editor_style'    => 'forwp-seo-techarticle-completion',
				'style'           => 'forwp-seo-techarticle-completion',
				'render_callback' => [ $this, 'render_completion' ],
			]
		);
	}

	private function render_wrapper( string $class_name, string $content, array $attributes, string $tag, ?\WP_Block $block = null ): string {
		if ( '' === trim( $content ) && $block instanceof \WP_Block && ! empty( $block->inner_blocks ) ) {
			$parts = [];
			foreach ( $block->inner_blocks as $inner_block ) {
				if ( $inner_block instanceof \WP_Block ) {
					$parts[] = $inner_block->render();
				}
			}
			$content = implode( '', $parts );
		}

		if ( '' === trim( $content ) ) {
			return '';
		}

		$classes = [ $class_name ];
		if ( ! empty( $attributes['className'] ) && is_string( $attributes['className'] ) ) {
			foreach ( preg_split( '/\s+/', trim( $attributes['className'] ) ) as $custom_class ) {
				if ( '' !== $custom_class ) {
					$classes[] = sanitize_html_class( $custom_class );
				}
			}
		}

		$data_attrs = '';
		if ( isset( $attributes['stepNumber'] ) && is_numeric( $attributes['stepNumber'] ) ) {
			$data_attrs = sprintf( ' data-step="%d"', (int) $attributes['stepNumber'] );
		}

		return sprintf(
			'<%1$s class="%2$s"%3$s>%4$s</%1$s>',
			$tag,
			esc_attr( implode( ' ', array_filter( $classes ) ) ),
			$data_attrs,
			wp_kses_post( $content )
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render_completion( array $attributes ): string {
		$columns = [
			'perfect'  => __( 'What you nailed', '4wp-seo-helper' ),
			'optimize' => __( 'Level up', '4wp-seo-helper' ),
			'next'     => __( 'What\'s next', '4wp-seo-helper' ),
		];

		$html = '';
		foreach ( $columns as $key => $title ) {
			$items = $attributes[ $key ] ?? [];
			if ( ! is_array( $items ) || empty( $items ) ) {
				continue;
			}

			$list = '';
			foreach ( $items as $item ) {
				if ( ! is_string( $item ) || '' === trim( $item ) ) {
					continue;
				}
				$list .= '<li>' . esc_html( $item ) . '</li>';
			}

			if ( '' === $list ) {
				continue;
			}

			$html .= sprintf(
				'<div class="forwp-seo-techarticle-completion__column forwp-seo-techarticle-completion__column--%1$s"><h2 class="forwp-seo-techarticle-completion__title">%2$s</h2><ul class="forwp-seo-techarticle-completion__list">%3$s</ul></div>',
				esc_attr( $key ),
				esc_html( $title ),
				$list
			);
		}

		if ( '' === $html ) {
			return '';
		}

		return sprintf(
			'<section class="forwp-seo-techarticle-completion"><div class="forwp-seo-techarticle-completion__grid">%s</div></section>',
			$html
		);
	}
}
