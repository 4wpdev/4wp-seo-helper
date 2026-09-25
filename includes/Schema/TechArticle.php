<?php
/**
 * TechArticle schema handling.
 */

namespace Forwp\SeoHelper\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TechArticle {
	public const META_KEY = '_forwp_seo_techarticle_enabled';
	private const LEGACY_META_KEY = '_4wp_seo_techarticle_enabled';
	public const STEPS_BLOCK = 'forwp-seo/techarticle-steps';
	public const STEP_BLOCK = 'forwp-seo/techarticle-step';

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp', [ $this, 'maybe_disable_advanced_code_schema' ], 1 );
		add_action( 'wp_head', [ $this, 'output_schema' ], 1 );
	}

	public function maybe_disable_advanced_code_schema(): void {
		$post = get_post();
		if ( ! $post || ! $this->should_output_schema( $post ) ) {
			return;
		}

		if ( class_exists( '\ForWP\Bundle\SeoHandler' ) ) {
			remove_action( 'wp_head', [ '\ForWP\Bundle\SeoHandler', 'outputJsonLd' ], 1 );
		}
	}

	public function output_schema(): void {
		$post = get_post();
		if ( ! $post || ! $this->should_output_schema( $post ) ) {
			return;
		}

		$schema = $this->build_schema( $post );
		if ( empty( $schema ) ) {
			return;
		}

		wp_print_inline_script_tag(
			wp_json_encode( $schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ),
			[ 'type' => 'application/ld+json' ]
		);
		echo PHP_EOL;
	}

	private function should_output_schema( \WP_Post $post ): bool {
		if ( ! is_singular() ) {
			return false;
		}

		return $this->is_post_valid( $post );
	}

	public function is_post_valid( \WP_Post $post ): bool {
		if ( ! $this->is_enabled_for_post( $post->ID ) ) {
			return false;
		}

		if ( $this->is_practice_case_sections_valid( $post ) ) {
			return true;
		}

		return $this->is_block_content_valid( $post );
	}

	/**
	 * Build TechArticle JSON-LD for a post (empty when invalid or disabled).
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	public function get_schema( \WP_Post $post ): array {
		if ( ! $this->is_enabled_for_post( $post->ID ) ) {
			return [];
		}

		return $this->build_schema( $post );
	}

	/**
	 * @param \WP_Post $post Post object.
	 * @return 'practice_case_sections'|'post_content_blocks'|''
	 */
	public function get_schema_source( \WP_Post $post ): string {
		if ( ! $this->is_enabled_for_post( $post->ID ) ) {
			return '';
		}

		if ( $this->is_practice_case_sections_valid( $post ) ) {
			return 'practice_case_sections';
		}

		if ( $this->is_block_content_valid( $post ) ) {
			return 'post_content_blocks';
		}

		return '';
	}

	private function is_block_content_valid( \WP_Post $post ): bool {
		$blocks = parse_blocks( $post->post_content );
		$flat   = $this->flatten_blocks( $blocks );

		return $this->has_required_blocks( $flat );
	}

	private function is_practice_case_sections_valid( \WP_Post $post ): bool {
		if ( ! $this->is_practice_case_post( $post ) ) {
			return false;
		}

		$sections = $this->get_practice_case_sections( $post->ID );
		if ( empty( $sections ) || ! is_array( $sections ) ) {
			return false;
		}

		$software_code = $this->extract_software_code_from_sections( $sections );
		$steps         = $this->extract_steps_from_sections( $sections );

		return ! empty( $software_code ) && ! empty( $steps );
	}

	public function is_enabled_for_post( int $post_id ): bool {
		$value = get_post_meta( $post_id, self::META_KEY, true );
		if ( $value !== '' ) {
			return (bool) $value;
		}

		return (bool) get_post_meta( $post_id, self::LEGACY_META_KEY, true );
	}

	/**
	 * TechArticle Steps is required for JSON-LD.
	 * WordPress Code (`core/code`) is optional — not TechArticle Context.
	 */
	private function has_required_blocks( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			if ( self::STEPS_BLOCK === $name || self::STEP_BLOCK === $name ) {
				return true;
			}
		}

		return false;
	}

	private function build_schema( \WP_Post $post ): array {
		$schema = $this->build_schema_from_practice_case_sections( $post );
		if ( ! empty( $schema ) ) {
			return $schema;
		}

		return $this->build_schema_from_blocks( $post );
	}

	private function build_schema_from_practice_case_sections( \WP_Post $post ): array {
		if ( ! $this->is_practice_case_post( $post ) ) {
			return [];
		}

		$sections = $this->get_practice_case_sections( $post->ID );
		if ( empty( $sections ) || ! is_array( $sections ) ) {
			return [];
		}

		$software_code = $this->extract_software_code_from_sections( $sections );
		$steps         = $this->extract_steps_from_sections( $sections );

		if ( empty( $software_code ) || empty( $steps ) ) {
			return [];
		}

		return $this->assemble_schema( $post, $software_code, $steps );
	}

	private function build_schema_from_blocks( \WP_Post $post ): array {
		$blocks = parse_blocks( $post->post_content );
		$slots  = TechArticleMapper::slots_from_blocks( $blocks );

		if ( ! $slots->has_required() ) {
			// Legacy path when mapper finds nothing usable.
			$flat          = $this->flatten_blocks( $blocks );
			$software_code = $this->extract_software_code( $flat );
			$steps         = $this->extract_steps( $flat );

			if ( empty( $steps ) ) {
				return [];
			}

			return $this->assemble_schema( $post, $software_code, $steps );
		}

		$context = [
			'headline'      => get_the_title( $post->ID ),
			'url'           => get_permalink( $post->ID ),
			'datePublished' => get_the_date( 'c', $post->ID ),
			'dateModified'  => get_the_modified_date( 'c', $post->ID ),
			'image'         => (string) get_the_post_thumbnail_url( $post->ID, 'large' ),
			'about'         => $this->build_about( $post ),
			'@id'           => trailingslashit( (string) get_permalink( $post->ID ) ) . '#techarticle',
		];

		$author = get_userdata( $post->post_author );
		if ( $author ) {
			$context['author'] = [
				'@type' => 'Person',
				'name'  => $author->display_name,
				'url'   => get_author_posts_url( $author->ID ),
			];
		}

		$schema = TechArticleMapper::to_schema( $context, $slots );
		return apply_filters( 'forwp_seo_techarticle_schema', $schema, $post );
	}

	/**
	 * @param array<int, array<string, mixed>> $software_code Schema.org SoftwareSourceCode entries in hasPart.
	 * @param array<int, array<string, mixed>> $steps         Schema.org HowToStep entries.
	 * @return array<string, mixed>
	 */
	private function assemble_schema( \WP_Post $post, array $software_code, array $steps ): array {
		$author     = get_userdata( $post->post_author );
		$author_url = (string) get_post_meta( $post->ID, '_lms4wp_practice_author_url', true );
		$image      = (string) get_post_meta( $post->ID, '_lms4wp_practice_image', true );

		if ( '' === $image ) {
			$image = (string) get_the_post_thumbnail_url( $post->ID, 'large' );
		}

		$author_data = null;
		if ( $author ) {
			$author_data = [ '@type' => 'Person', 'name' => $author->display_name ];
			$url = '' !== $author_url ? $author_url : get_author_posts_url( $author->ID );
			if ( '' !== $url ) {
				$author_data['url'] = $url;
			}
		}

		$has_part = $software_code;
		$has_part[] = [
			'@type' => 'HowTo',
			'step'  => $steps,
		];

		$schema = [
			'@context'      => 'https://schema.org',
			'@type'         => 'TechArticle',
			'headline'      => get_the_title( $post->ID ),
			'url'           => get_permalink( $post->ID ),
			'datePublished' => get_the_date( 'c', $post->ID ),
			'dateModified'  => get_the_modified_date( 'c', $post->ID ),
			'author'        => $author_data,
			'image'         => '' !== $image ? $image : null,
			'hasPart'       => $has_part,
			'about'         => $this->build_about( $post ),
		];

		foreach ( [ 'author', 'image', 'about' ] as $key ) {
			if ( empty( $schema[ $key ] ) ) {
				unset( $schema[ $key ] );
			}
		}

		return apply_filters( 'forwp_seo_techarticle_schema', $schema, $post );
	}

	/**
	 * @param array<string, mixed> $sections Practice case section meta.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_software_code_from_sections( array $sections ): array {
		$output = [];
		$steps  = $sections['steps'] ?? [];

		if ( ! is_array( $steps ) ) {
			return $output;
		}

		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$command = isset( $step['command'] ) ? trim( (string) $step['command'] ) : '';
			if ( '' === $command ) {
				continue;
			}

			$output[] = [
				'@type'               => 'SoftwareSourceCode',
				'codeSampleType'      => 'full',
				'programmingLanguage' => 'bash',
				'text'                => $command,
			];
		}

		return $output;
	}

	/**
	 * @param array<string, mixed> $sections Practice case section meta.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_steps_from_sections( array $sections ): array {
		$output = [];
		$steps  = $sections['steps'] ?? [];

		if ( ! is_array( $steps ) ) {
			return $output;
		}

		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$text = $this->format_practice_case_step_text( $step );
			if ( '' === $text ) {
				continue;
			}

			$output[] = [
				'@type' => 'HowToStep',
				'text'  => $text,
			];
		}

		return $output;
	}

	/**
	 * @param array<string, mixed> $step Practice case step row.
	 */
	private function format_practice_case_step_text( array $step ): string {
		$parts = [];

		if ( ! empty( $step['title'] ) && is_string( $step['title'] ) ) {
			$parts[] = trim( wp_strip_all_tags( $step['title'] ) );
		}

		$paragraphs = $step['paragraphs'] ?? [];
		if ( is_string( $paragraphs ) ) {
			$paragraphs = [ $paragraphs ];
		}

		if ( is_array( $paragraphs ) ) {
			foreach ( $paragraphs as $paragraph ) {
				if ( ! is_string( $paragraph ) || trim( $paragraph ) === '' ) {
					continue;
				}
				$parts[] = trim( wp_strip_all_tags( $paragraph ) );
			}
		}

		return trim( implode( "\n", array_filter( $parts ) ) );
	}

	private function is_practice_case_post( \WP_Post $post ): bool {
		if ( ! class_exists( '\ForWP\LMS\PostTypes\PracticeCase' ) ) {
			return false;
		}

		return \ForWP\LMS\PostTypes\PracticeCase::POST_TYPE === $post->post_type;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function get_practice_case_sections( int $post_id ): ?array {
		if ( class_exists( '\ForWP\LMS\Frontend\PracticeCaseRenderer' ) ) {
			return \ForWP\LMS\Frontend\PracticeCaseRenderer::resolveSections( $post_id );
		}

		if ( class_exists( '\ForWP\LMS\Content\PracticeCaseContent' ) ) {
			return \ForWP\LMS\Content\PracticeCaseContent::get( $post_id );
		}

		return null;
	}

	private function extract_software_code( array $blocks ): array {
		$output = [];
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) !== 'core/code' ) {
				continue;
			}

			$code = $this->extract_code_text( $block );
			if ( '' === $code ) {
				continue;
			}

			$language = $block['attrs']['language'] ?? 'auto';

			$item = [
				'@type'            => 'SoftwareSourceCode',
				'codeSampleType'   => 'full',
				'programmingLanguage' => $language,
				'text'             => $code,
			];

			$output[] = $item;
		}

		return $output;
	}

	private function extract_steps( array $blocks ): array {
		$steps = [];
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';

			if ( $name === self::STEPS_BLOCK && ! empty( $block['attrs']['steps'] ) ) {
				foreach ( (array) $block['attrs']['steps'] as $step ) {
					$text = is_array( $step ) ? ( $step['text'] ?? '' ) : '';
					$text = trim( wp_strip_all_tags( $text ) );
					if ( '' !== $text ) {
						$steps[] = [
							'@type' => 'HowToStep',
							'text'  => $text,
						];
					}
				}
				continue;
			}

			if ( $name !== self::STEP_BLOCK ) {
				continue;
			}

			$text = $this->extract_step_text_from_inner_blocks( $block['innerBlocks'] ?? [] );
			if ( '' !== $text ) {
				$steps[] = [
					'@type' => 'HowToStep',
					'text'  => $text,
				];
			}
		}

		return $steps;
	}

	private function extract_step_text_from_inner_blocks( array $blocks ): string {
		$parts = [];

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			$html = $block['innerHTML'] ?? '';

			if ( in_array( $name, [ 'core/heading', 'core/paragraph', 'core/list', 'core/code' ], true ) ) {
				$text = trim( wp_strip_all_tags( $html ) );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$child = $this->extract_step_text_from_inner_blocks( $block['innerBlocks'] );
				if ( '' !== $child ) {
					$parts[] = $child;
				}
			}
		}

		return trim( implode( "\n", $parts ) );
	}

	private function extract_code_text( array $block ): string {
		$html = $block['innerHTML'] ?? '';
		if ( '' === $html ) {
			return '';
		}
		return trim( wp_strip_all_tags( $html ) );
	}

	private function build_about( \WP_Post $post ): array {
		$items = [];

		$tags = get_the_tags( $post->ID );
		if ( ! empty( $tags ) ) {
			foreach ( $tags as $tag ) {
				$items[] = $tag->name;
			}
		}

		$items = array_unique( array_filter( $items ) );
		$about = [];
		foreach ( $items as $name ) {
			$about[] = [
				'@type' => 'Thing',
				'name'  => $name,
			];
		}

		return apply_filters( 'forwp_seo_techarticle_about', $about, $post );
	}

	private function flatten_blocks( array $blocks ): array {
		$flat = [];
		foreach ( $blocks as $block ) {
			$flat[] = $block;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, $this->flatten_blocks( $block['innerBlocks'] ) );
			}
		}
		return $flat;
	}
}

