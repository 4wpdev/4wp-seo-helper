<?php
/**
 * TechArticle schema mapper — blocks or CSS class wrappers → SlotBag → JSON-LD.
 */

namespace Forwp\SeoHelper\Schema;

use Forwp\SeoHelper\Blocks\TechArticleWrappers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Source-agnostic TechArticle builder.
 */
final class TechArticleMapper {

	public const PROFILE = 'techarticle';

	/** CSS classes (same as block wrapper front-end classes). */
	public const CLASS_GOAL       = 'forwp-seo-techarticle-goal';
	public const CLASS_CONTEXT    = 'forwp-seo-techarticle-context';
	public const CLASS_STEPS      = 'forwp-seo-techarticle-steps';
	public const CLASS_STEP       = 'forwp-seo-techarticle-step';
	public const CLASS_ISSUES     = 'forwp-seo-techarticle-issues';
	public const CLASS_COMPLETION = 'forwp-seo-techarticle-completion';
	public const CLASS_CODE       = 'forwp-seo-techarticle-code';

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed Gutenberg blocks.
	 */
	public static function slots_from_blocks( array $blocks ): SlotBag {
		$flat = self::flatten_blocks( $blocks );
		$data = [
			'goal'       => '',
			'context'    => '',
			'issues'     => '',
			'completion' => '',
			'steps'      => [],
			'code'       => [],
		];

		foreach ( $flat as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			$html = (string) ( $block['innerHTML'] ?? '' );

			switch ( $name ) {
				case TechArticleWrappers::GOAL_BLOCK:
					$data['goal'] = self::plain( $html );
					break;
				case TechArticleWrappers::CONTEXT_BLOCK:
					$data['context'] = self::plain( $html );
					break;
				case TechArticleWrappers::ISSUES_BLOCK:
					$data['issues'] = self::plain( $html );
					break;
				case TechArticleWrappers::COMPLETION_BLOCK:
					$data['completion'] = self::plain( $html );
					break;
				case TechArticleWrappers::STEPS_BLOCK:
					if ( ! empty( $block['attrs']['steps'] ) && is_array( $block['attrs']['steps'] ) ) {
						foreach ( $block['attrs']['steps'] as $step ) {
							$text = is_array( $step ) ? (string) ( $step['text'] ?? '' ) : '';
							$text = trim( wp_strip_all_tags( $text ) );
							if ( '' !== $text ) {
								$data['steps'][] = [ 'text' => $text ];
							}
						}
					}
					break;
				case TechArticleWrappers::STEP_BLOCK:
				case TechArticle::STEP_BLOCK:
					$text = self::plain_from_inner( $block['innerBlocks'] ?? [] );
					if ( '' !== $text ) {
						$data['steps'][] = [ 'text' => $text ];
					}
					break;
				case 'core/code':
				case 'forwp-advanced-code/code':
					$code = self::plain( $html );
					if ( '' !== $code ) {
						$lang           = (string) ( $block['attrs']['language'] ?? 'php' );
						$data['code'][] = [
							'text'     => $code,
							'language' => $lang,
						];
					}
					break;
			}
		}

		// Code inside step wrappers still counts.
		if ( empty( $data['code'] ) ) {
			foreach ( $flat as $block ) {
				if ( ( $block['blockName'] ?? '' ) !== 'core/code' ) {
					continue;
				}
				$code = self::plain( (string) ( $block['innerHTML'] ?? '' ) );
				if ( '' !== $code ) {
					$data['code'][] = [
						'text'     => $code,
						'language' => (string) ( $block['attrs']['language'] ?? 'php' ),
					];
				}
			}
		}

		return SlotBag::from_array( $data );
	}

	/**
	 * Extract slots from HTML that uses TechArticle CSS class wrappers.
	 */
	public static function slots_from_html( string $html ): SlotBag {
		$html = trim( $html );
		if ( '' === $html ) {
			return new SlotBag();
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$wrapped  = '<?xml encoding="utf-8" ?><div id="forwp-seo-root">' . $html . '</div>';
		$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new \DOMXPath( $dom );
		$data  = [
			'goal'       => self::first_class_text( $xpath, self::CLASS_GOAL ),
			'context'    => self::first_class_text( $xpath, self::CLASS_CONTEXT ),
			'issues'     => self::first_class_text( $xpath, self::CLASS_ISSUES ),
			'completion' => self::first_class_text( $xpath, self::CLASS_COMPLETION ),
			'steps'      => [],
			'code'       => [],
		];

		foreach ( self::nodes_by_class( $xpath, self::CLASS_STEP ) as $node ) {
			$text = self::step_text( $xpath, $node );
			if ( '' !== $text ) {
				$data['steps'][] = [ 'text' => $text ];
			}
		}

		// Fallback: list items inside steps wrapper.
		if ( empty( $data['steps'] ) ) {
			$steps_root = self::first_class_node( $xpath, self::CLASS_STEPS );
			if ( $steps_root ) {
				foreach ( $xpath->query( './/li', $steps_root ) as $li ) {
					$text = self::node_text( $li );
					if ( '' !== $text ) {
						$data['steps'][] = [ 'text' => $text ];
					}
				}
			}
		}

		foreach ( self::nodes_by_class( $xpath, self::CLASS_CODE ) as $node ) {
			$text = self::code_text( $xpath, $node );
			if ( '' === $text ) {
				continue;
			}
			$lang = 'php';
			if ( $node instanceof \DOMElement && $node->hasAttribute( 'data-language' ) ) {
				$lang = (string) $node->getAttribute( 'data-language' );
			}
			$data['code'][] = [
				'text'     => $text,
				'language' => $lang ?: 'php',
			];
		}

		// Also pick <pre><code> inside any techarticle section if CLASS_CODE missing.
		if ( empty( $data['code'] ) ) {
			foreach ( [ self::CLASS_STEPS, self::CLASS_GOAL, self::CLASS_CONTEXT ] as $section_class ) {
				$root = self::first_class_node( $xpath, $section_class );
				if ( ! $root ) {
					continue;
				}
				foreach ( $xpath->query( './/pre//code', $root ) as $code_node ) {
					$text = self::node_text( $code_node );
					if ( '' !== $text ) {
						$data['code'][] = [ 'text' => $text, 'language' => 'php' ];
					}
				}
			}
		}

		return SlotBag::from_array( $data );
	}

	/**
	 * @param array<string, mixed> $data Slot array.
	 */
	public static function slots_from_array( array $data ): SlotBag {
		return SlotBag::from_array( $data );
	}

	/**
	 * Build TechArticle JSON-LD from page context + slots.
	 *
	 * @param array<string, mixed> $context headline, url, dates, author, image, about, @id.
	 * @return array<string, mixed>
	 */
	public static function to_schema( array $context, SlotBag $slots ): array {
		if ( ! $slots->has_required() ) {
			return [];
		}

		$has_part = [];
		foreach ( $slots->code as $sample ) {
			$has_part[] = [
				'@type'               => 'SoftwareSourceCode',
				'codeSampleType'      => 'full',
				'programmingLanguage' => $sample['language'] ?? 'php',
				'text'                => $sample['text'],
			];
		}

		$howto_steps = [];
		foreach ( $slots->steps as $step ) {
			$entry = [
				'@type' => 'HowToStep',
				'text'  => $step['text'],
			];
			if ( ! empty( $step['name'] ) ) {
				$entry['name'] = $step['name'];
			}
			$howto_steps[] = $entry;
		}

		$howto = [
			'@type' => 'HowTo',
			'step'  => $howto_steps,
		];
		if ( '' !== $slots->goal ) {
			$howto['name'] = $slots->goal;
		}
		$has_part[] = $howto;

		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => 'TechArticle',
			'headline' => (string) ( $context['headline'] ?? '' ),
			'url'      => (string) ( $context['url'] ?? '' ),
			'hasPart'  => $has_part,
		];

		if ( ! empty( $context['@id'] ) ) {
			$schema['@id'] = (string) $context['@id'];
		}
		if ( ! empty( $context['datePublished'] ) ) {
			$schema['datePublished'] = (string) $context['datePublished'];
		}
		if ( ! empty( $context['dateModified'] ) ) {
			$schema['dateModified'] = (string) $context['dateModified'];
		}
		if ( ! empty( $context['author'] ) ) {
			$schema['author'] = $context['author'];
		}
		if ( ! empty( $context['image'] ) ) {
			$schema['image'] = $context['image'];
		}
		if ( ! empty( $context['about'] ) ) {
			$schema['about'] = $context['about'];
		}
		if ( '' !== $slots->goal ) {
			$schema['description'] = $slots->goal;
		} elseif ( '' !== $slots->context ) {
			$schema['description'] = $slots->context;
		}
		if ( '' !== $slots->issues ) {
			$schema['additionalProperty'] = [
				[
					'@type' => 'PropertyValue',
					'name'  => 'commonMistakes',
					'value' => $slots->issues,
				],
			];
		}

		/**
		 * Filter assembled TechArticle schema (mapper path).
		 *
		 * @param array<string, mixed> $schema  Schema.
		 * @param array<string, mixed> $context Context.
		 * @param SlotBag              $slots   Slots.
		 */
		return apply_filters( 'forwp_seo_techarticle_mapper_schema', $schema, $context, $slots );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten_blocks( array $blocks ): array {
		$flat = [];
		foreach ( $blocks as $block ) {
			$flat[] = $block;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, self::flatten_blocks( $block['innerBlocks'] ) );
			}
		}
		return $flat;
	}

	private static function plain( string $html ): string {
		return trim( wp_strip_all_tags( $html ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Inner blocks.
	 */
	private static function plain_from_inner( array $blocks ): string {
		$parts = [];
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			$html = $block['innerHTML'] ?? '';
			if ( in_array( $name, [ 'core/heading', 'core/paragraph', 'core/list', 'core/code' ], true ) ) {
				$text = self::plain( (string) $html );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$child = self::plain_from_inner( $block['innerBlocks'] );
				if ( '' !== $child ) {
					$parts[] = $child;
				}
			}
		}
		return trim( implode( "\n", $parts ) );
	}

	private static function first_class_text( \DOMXPath $xpath, string $class ): string {
		$node = self::first_class_node( $xpath, $class );
		if ( ! $node ) {
			return '';
		}

		$parts = [];
		foreach ( $xpath->query( './p|./ul/li|./ol/li|.//p[not(ancestor::*[contains(@class,"forwp-seo-techarticle-step")])]', $node ) as $child ) {
			$text = self::node_text( $child );
			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		if ( ! empty( $parts ) ) {
			return trim( implode( ' ', $parts ) );
		}

		// Clone without headings / nested code for a clean fallback.
		$clone = $node->cloneNode( true );
		if ( $clone instanceof \DOMElement ) {
			$headings = [];
			foreach ( $clone->getElementsByTagName( '*' ) as $el ) {
				$tag = strtolower( $el->tagName );
				if ( in_array( $tag, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'code' ], true ) ) {
					$headings[] = $el;
				}
			}
			foreach ( $headings as $el ) {
				if ( $el->parentNode ) {
					$el->parentNode->removeChild( $el );
				}
			}
			return self::node_text( $clone );
		}

		return self::node_text( $node );
	}

	private static function first_class_node( \DOMXPath $xpath, string $class ): ?\DOMNode {
		$nodes = self::nodes_by_class( $xpath, $class );
		return $nodes[0] ?? null;
	}

	/**
	 * @return array<int, \DOMNode>
	 */
	private static function nodes_by_class( \DOMXPath $xpath, string $class ): array {
		$safe = addcslashes( $class, '"\\' );
		$list = $xpath->query(
			'//*[@id="forwp-seo-root"]//*[contains(concat(" ", normalize-space(@class), " "), " ' . $safe . ' ")]'
		);
		if ( ! $list ) {
			return [];
		}
		$out = [];
		foreach ( $list as $node ) {
			$out[] = $node;
		}
		return $out;
	}

	private static function node_text( \DOMNode $node ): string {
		return trim( preg_replace( '/\s+/u', ' ', $node->textContent ?? '' ) ?? '' );
	}

	/**
	 * Step text without nested code samples.
	 */
	private static function step_text( \DOMXPath $xpath, \DOMNode $node ): string {
		$parts = [];
		foreach ( $xpath->query( './/h3|.//h4|.//p[not(ancestor::pre)]', $node ) as $child ) {
			// Skip if inside a code wrapper.
			$parent = $child->parentNode;
			$skip   = false;
			while ( $parent instanceof \DOMElement ) {
				$class = $parent->getAttribute( 'class' );
				if ( false !== strpos( ' ' . $class . ' ', ' ' . self::CLASS_CODE . ' ' ) ) {
					$skip = true;
					break;
				}
				$parent = $parent->parentNode;
			}
			if ( $skip ) {
				continue;
			}
			$text = self::node_text( $child );
			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		if ( ! empty( $parts ) ) {
			return trim( implode( ': ', array_unique( $parts ) ) );
		}

		$clone = $node->cloneNode( true );
		if ( $clone instanceof \DOMElement ) {
			$remove = [];
			foreach ( $clone->getElementsByTagName( '*' ) as $el ) {
				$class = $el instanceof \DOMElement ? $el->getAttribute( 'class' ) : '';
				$tag   = strtolower( $el->tagName );
				if ( 'pre' === $tag || 'code' === $tag || false !== strpos( ' ' . $class . ' ', ' ' . self::CLASS_CODE . ' ' ) ) {
					$remove[] = $el;
				}
			}
			foreach ( $remove as $el ) {
				if ( $el->parentNode ) {
					$el->parentNode->removeChild( $el );
				}
			}
			return self::node_text( $clone );
		}

		return self::node_text( $node );
	}

	/**
	 * Prefer <code>/<pre> text when CLASS_CODE is on a section wrapper.
	 */
	private static function code_text( \DOMXPath $xpath, \DOMNode $node ): string {
		foreach ( $xpath->query( './/pre//code|.//code', $node ) as $code_node ) {
			$text = self::node_text( $code_node );
			if ( '' !== $text ) {
				return $text;
			}
		}
		return self::node_text( $node );
	}
}
