<?php
/**
 * Settings of the forwp-seo-helper/get-content ability (Settings → AI agents / Abilities).
 */

namespace Forwp\SeoHelper\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	public const OPTION = 'forwp_seo_helper_abilities_content';

	public const FORMATS = [ 'text', 'html', 'blocks' ];

	public const DEFAULT_MAX_CHARS = 20000;

	public const MAX_CHARS_LIMIT = 200000;

	/**
	 * @return array{default_format: string, allowed_formats: list<string>, max_chars: int}
	 */
	public static function get(): array {
		$value = get_option( self::OPTION, [] );
		$value = is_array( $value ) ? $value : [];

		$allowed = array_values( array_intersect( self::FORMATS, (array) ( $value['allowed_formats'] ?? [ 'text' ] ) ) );
		if ( [] === $allowed ) {
			$allowed = [ 'text' ];
		}

		$default = (string) ( $value['default_format'] ?? 'text' );
		if ( ! in_array( $default, $allowed, true ) ) {
			$default = $allowed[0];
		}

		$max_chars = (int) ( $value['max_chars'] ?? self::DEFAULT_MAX_CHARS );

		return [
			'default_format'  => $default,
			'allowed_formats' => $allowed,
			'max_chars'       => min( self::MAX_CHARS_LIMIT, max( 500, $max_chars ) ),
		];
	}

	/**
	 * Saves from the settings form (nonce verified by the caller).
	 */
	public static function save_from_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in Page::process_settings_post().
		$allowed = isset( $_POST['forwp_seo_abilities_allowed_formats'] )
			? array_map( 'sanitize_key', (array) wp_unslash( $_POST['forwp_seo_abilities_allowed_formats'] ) )
			: [];
		$default = isset( $_POST['forwp_seo_abilities_default_format'] )
			? sanitize_key( wp_unslash( $_POST['forwp_seo_abilities_default_format'] ) )
			: 'text';
		$max     = isset( $_POST['forwp_seo_abilities_max_chars'] )
			? absint( wp_unslash( $_POST['forwp_seo_abilities_max_chars'] ) )
			: self::DEFAULT_MAX_CHARS;
		// phpcs:enable

		$allowed = array_values( array_intersect( self::FORMATS, $allowed ) );
		if ( ! in_array( $default, self::FORMATS, true ) ) {
			$default = 'text';
		}
		// The default format is always allowed.
		if ( ! in_array( $default, $allowed, true ) ) {
			$allowed[] = $default;
		}

		update_option(
			self::OPTION,
			[
				'default_format'  => $default,
				'allowed_formats' => $allowed,
				'max_chars'       => min( self::MAX_CHARS_LIMIT, max( 500, $max ) ),
			],
			false
		);
	}
}
