<?php
/**
 * User-saved taxonomies hidden from SEO inventory archives.
 */

namespace Forwp\SeoHelper\Inventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ExcludedTaxonomies {
	public const OPTION_KEY = 'forwp_seo_helper_inventory_excluded_taxonomies';

	/**
	 * @return list<string>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			return [];
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $saved )
				)
			)
		);
	}

	/**
	 * @param list<string> $slugs
	 * @return list<string>
	 */
	public static function set( array $slugs ): array {
		$allowed = TaxonomyDiscovery::get_discovered_archive_slugs();
		$clean   = [];

		foreach ( $slugs as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! in_array( $slug, $allowed, true ) ) {
				continue;
			}
			$clean[] = $slug;
		}

		$clean = array_values( array_unique( $clean ) );
		update_option( self::OPTION_KEY, $clean, false );

		return $clean;
	}

	public static function add( string $slug ): bool {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return false;
		}

		$current = self::get();
		if ( in_array( $slug, $current, true ) ) {
			return true;
		}

		self::set( array_merge( $current, [ $slug ] ) );

		return in_array( $slug, self::get(), true );
	}

	public static function remove( string $slug ): void {
		$slug = sanitize_key( $slug );
		self::set(
			array_values(
				array_filter(
					self::get(),
					static function ( string $item ) use ( $slug ): bool {
						return $item !== $slug;
					}
				)
			)
		);
	}
}
