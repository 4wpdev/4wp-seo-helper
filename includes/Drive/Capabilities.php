<?php
/**
 * Drive allow-list owned by 4WP SEO Helper — not Drive's static catalog.
 *
 * Heading aliases live in Drive Patterns after import, not in PHP.
 */

namespace Forwp\SeoHelper\Drive;

use Forwp\SeoHelper\Blocks\TechArticleWrappers;
use Forwp\SeoHelper\Content\TechArticleMarkup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {
	public const MAP = 'tech-article';

	public static function init(): void {
		add_filter( 'forwp_drive_wrap_capabilities', [ self::class, 'register' ] );
		add_filter( 'forwp_drive_block_templates', [ self::class, 'register_templates' ] );
		add_filter( 'forwp_drive_detect_map', [ self::class, 'detect_map' ], 10, 3 );
	}

	/**
	 * Rows Drive may wrap. List / sidebar-only blocks stay out.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function export(): array {
		return [
			self::row(
				'techarticle-goal',
				TechArticleWrappers::GOAL_BLOCK,
				'TechArticle Goal',
				'Goal, Мета',
				'Role: outcome. H2 until the next H2. Heading text is set in Drive Patterns or pinned in Incoming.'
			),
			self::row(
				'techarticle-context',
				TechArticleWrappers::CONTEXT_BLOCK,
				'TechArticle Context',
				'Context, Контекст',
				'Role: problem / context. H2 until the next H2. Add language aliases in Patterns.'
			),
			self::row(
				'techarticle-steps',
				TechArticleWrappers::STEPS_BLOCK,
				'TechArticle Steps',
				'Steps, Кроки',
				'Role: steps. Pin once to wrap contiguous Step H2 sections in one block (or a Steps heading with H3/list items inside).',
				'multi'
			),
			self::row(
				'techarticle-issues',
				TechArticleWrappers::ISSUES_BLOCK,
				'TechArticle Common mistakes',
				'Common mistakes, Типові помилки',
				'Role: common mistakes. List or paragraphs until the next H2.'
			),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $items Drive catalog.
	 * @return array<int, array<string, mixed>>
	 */
	public static function register( array $items ): array {
		foreach ( self::export() as $row ) {
			$items[] = $row;
		}

		return $items;
	}

	/**
	 * Import templates for Drive Patterns (editable H2 aliases).
	 *
	 * @param array<string, array<string, mixed>> $templates Template id => definition.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_templates( array $templates ): array {
		foreach ( self::export() as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$templates[ $id ] = [
				'label'            => (string) ( $row['label'] ?? $id ),
				'description'      => (string) ( $row['prompt'] ?? '' ),
				'recipe_type'      => 'section-wrap',
				'block'            => (string) ( $row['block'] ?? '' ),
				'requires_plugins' => [ '4wp-seo-helper/4wp-seo-helper.php' ],
				'available'        => true,
				'default_enabled'  => false,
				'doc_hint'         => (string) ( $row['prompt'] ?? '' ),
			];
		}

		return $templates;
	}

	/**
	 * Upgrade default Map when the document is already a TechArticle.
	 *
	 * @param string $map    Current map.
	 * @param string $header Header text.
	 * @param string $body   Body HTML.
	 */
	public static function detect_map( string $map, string $header, string $body ): string {
		if ( self::signals_tech_article( $header, $body ) ) {
			return self::MAP;
		}

		return $map;
	}

	public static function signals_tech_article( string $header, string $body ): bool {
		if ( TechArticleMarkup::has_techarticle_blocks( $body ) ) {
			return true;
		}

		$blob = $header . "\n" . $body;

		return (bool) preg_match( '/\btech[\s_-]*article\b/i', $blob );
	}

	/**
	 * @param string $span single = one H2 section; multi = contiguous step-like H2s in one wrap.
	 * @return array<string, mixed>
	 */
	private static function row(
		string $id,
		string $block,
		string $label,
		string $heading_seed,
		string $prompt,
		string $span = 'single'
	): array {
		return [
			'id'            => $id,
			'origin'        => 'family',
			'plugin'        => '4wp-seo-helper',
			'block'         => $block,
			'label'         => $label,
			'maps'          => [ self::MAP ],
			'wrap'          => 'section',
			'span'          => 'multi' === $span ? 'multi' : 'single',
			'match'         => [
				'type'    => 'heading',
				'level'   => 2,
				'pattern' => '',
			],
			'heading_seeds' => $heading_seed,
			'prompt'        => $prompt,
			'auto'          => false,
			'manual'        => true,
		];
	}
}
