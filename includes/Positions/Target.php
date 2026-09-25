<?php
/**
 * Domain we look up in SERP — Search Console property (incl. local override).
 */

namespace Forwp\SeoHelper\Positions;

use Forwp\SeoHelper\Admin\Menu;
use Forwp\SeoHelper\Gsc\Admin as GscAdmin;
use Forwp\SeoHelper\Gsc\PropertyResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Target {
	public static function property(): string {
		return trim( GscAdmin::get_site_property() );
	}

	public static function has_property(): bool {
		return '' !== self::property();
	}

	public static function host(): string {
		$property = self::property();
		if ( '' === $property ) {
			return '';
		}

		if ( str_starts_with( $property, 'sc-domain:' ) ) {
			return strtolower( substr( $property, 10 ) );
		}

		$host = (string) wp_parse_url( $property, PHP_URL_HOST );

		return strtolower( preg_replace( '/^www\./', '', $host ) ?? $host );
	}

	public static function settings_url(): string {
		return add_query_arg(
			[
				'page' => Menu::SETTINGS_PAGE_SLUG,
				'tab'  => 'gsc',
			],
			admin_url( 'admin.php' )
		);
	}

	public static function matches_url( string $url ): bool {
		$property = self::property();
		if ( '' === $property ) {
			return false;
		}

		return PropertyResolver::url_belongs_to_property( $url, $property );
	}

	/**
	 * Path after the host, for matching local vs live URLs of the same page.
	 */
	public static function path_key( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$path = (string) ( $parts['path'] ?? '/' );
		$path = untrailingslashit( $path );
		if ( '' === $path ) {
			$path = '/';
		}

		return strtolower( $path );
	}

	/**
	 * Host + path for admin lists (not domain-only).
	 */
	public static function link_label( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$host = preg_replace( '/^www\./', '', $host ) ?? $host;
		$path = (string) ( $parts['path'] ?? '' );
		if ( '/' === $path ) {
			$path = '';
		}

		return $host . $path;
	}

	/**
	 * @return array{property: string, host: string}
	 */
	public static function snapshot(): array {
		return [
			'property' => self::property(),
			'host'     => self::host(),
		];
	}
}
