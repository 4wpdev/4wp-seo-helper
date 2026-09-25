<?php
/**
 * Normalized semantic slots for schema profiles (source-agnostic).
 */

namespace Forwp\SeoHelper\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intermediate bag between markup extractors and JSON-LD builders.
 */
final class SlotBag {
	/** @var string */
	public $goal = '';

	/** @var string */
	public $context = '';

	/** @var array<int, array{text: string, name?: string}> */
	public $steps = [];

	/** @var array<int, array{text: string, language?: string}> */
	public $code = [];

	/** @var string */
	public $issues = '';

	/** @var string */
	public $completion = '';

	/**
	 * @param array<string, mixed> $data Raw slot data.
	 */
	public static function from_array( array $data ): self {
		$bag = new self();

		$bag->goal       = self::as_text( $data['goal'] ?? '' );
		$bag->context    = self::as_text( $data['context'] ?? '' );
		$bag->issues     = self::as_text( $data['issues'] ?? '' );
		$bag->completion = self::as_text( $data['completion'] ?? '' );

		if ( ! empty( $data['steps'] ) && is_array( $data['steps'] ) ) {
			foreach ( $data['steps'] as $step ) {
				if ( is_string( $step ) ) {
					$text = trim( wp_strip_all_tags( $step ) );
					if ( '' !== $text ) {
						$bag->steps[] = [ 'text' => $text ];
					}
					continue;
				}
				if ( ! is_array( $step ) ) {
					continue;
				}
				$text = self::as_text( $step['text'] ?? $step['explanation'] ?? '' );
				$name = self::as_text( $step['name'] ?? $step['title'] ?? '' );
				if ( '' === $text && '' !== $name ) {
					$text = $name;
					$name = '';
				}
				if ( '' === $text ) {
					continue;
				}
				$entry = [ 'text' => $text ];
				if ( '' !== $name ) {
					$entry['name'] = $name;
				}
				$bag->steps[] = $entry;
			}
		}

		if ( ! empty( $data['code'] ) && is_array( $data['code'] ) ) {
			foreach ( $data['code'] as $sample ) {
				if ( is_string( $sample ) ) {
					$text = trim( $sample );
					if ( '' !== $text ) {
						$bag->code[] = [ 'text' => $text, 'language' => 'php' ];
					}
					continue;
				}
				if ( ! is_array( $sample ) ) {
					continue;
				}
				$text = trim( (string) ( $sample['text'] ?? $sample['code'] ?? '' ) );
				if ( '' === $text ) {
					continue;
				}
				$bag->code[] = [
					'text'     => $text,
					'language' => (string) ( $sample['language'] ?? 'php' ),
				];
			}
		}

		return $bag;
	}

	/**
	 * Steps drive HowTo in TechArticle JSON-LD.
	 * Code samples are optional SoftwareSourceCode enrichment (not Context).
	 */
	public function has_required(): bool {
		return ! empty( $this->steps );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private static function as_text( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_map( 'strval', $value ) );
		}
		// Escape first so plain-text snippets like "</body>" survive wp_strip_all_tags.
		$escaped = htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		return trim(
			html_entity_decode(
				wp_strip_all_tags( $escaped ),
				ENT_QUOTES | ENT_HTML5,
				'UTF-8'
			)
		);
	}
}
