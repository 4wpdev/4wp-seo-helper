<?php
/**
 * Multi focus keyphrase storage (one phrase per line).
 *
 * Full list lives in plugin meta; primary (first line) syncs to Yoast / AIOSEO.
 */

namespace Forwp\SeoHelper\SeoMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FocusKeyphrases {
	public const META_KEY = '_forwp_seo_focus_keyphrases';

	/**
	 * @param mixed $input Newline-separated string or list of phrases.
	 * @return list<string>
	 */
	public static function normalize_input( mixed $input ): array {
		if ( is_array( $input ) ) {
			$lines = $input;
		} else {
			$lines = preg_split( '/\R/u', (string) $input ) ?: [];
		}

		$phrases = [];
		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( (string) $line ) );
			if ( '' !== $line ) {
				$phrases[] = $line;
			}
		}

		return $phrases;
	}

	/**
	 * @param list<string> $phrases
	 */
	public static function parse( string $input ): array {
		return self::normalize_input( $input );
	}

	public static function normalize_key( string $phrase ): string {
		$phrase = trim( preg_replace( '/\s+/u', ' ', $phrase ) ?? '' );
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $phrase );
		}

		return strtolower( $phrase );
	}

	/**
	 * @param list<string> $phrases
	 */
	public static function format( array $phrases ): string {
		return implode( "\n", $phrases );
	}

	/**
	 * @param list<string> $phrases
	 */
	public static function primary( array $phrases ): string {
		return $phrases[0] ?? '';
	}

	/**
	 * @return list<string>
	 */
	public static function read_for_post( int $post_id, string $adapter_primary = '' ): array {
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return self::normalize_input( $stored );
		}

		$adapter_primary = trim( $adapter_primary );

		return '' !== $adapter_primary ? [ $adapter_primary ] : [];
	}

	/**
	 * @param list<string> $phrases
	 */
	public static function save( int $post_id, array $phrases ): void {
		if ( empty( $phrases ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		update_post_meta( $post_id, self::META_KEY, self::format( $phrases ) );
	}
}
