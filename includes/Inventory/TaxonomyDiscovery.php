<?php
/**
 * Public taxonomies for SEO inventory (categories first, then the rest).
 */

namespace Forwp\SeoHelper\Inventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TaxonomyDiscovery {
	/**
	 * @param list<string>|string $post_types Empty = all inventory post types.
	 * @return list<array{slug: string, label: string, hierarchical: bool}>
	 */
	public static function get_labeled( $post_types = [] ): array {
		$types = self::normalize_post_types( $post_types );
		if ( [] === $types ) {
			return [];
		}

		$objects = get_object_taxonomies( $types, 'objects' );
		if ( ! is_array( $objects ) ) {
			return [];
		}

		$exclude = apply_filters(
			'forwp_seo_inventory_exclude_taxonomies',
			[
				'nav_menu',
				'link_category',
				'post_format',
				'wp_theme',
				'wp_template_part_area',
				'wp_pattern_category',
			]
		);
		$exclude = array_map( 'sanitize_key', (array) $exclude );

		$rows = [];
		foreach ( $objects as $taxonomy ) {
			if ( ! $taxonomy instanceof \WP_Taxonomy ) {
				continue;
			}

			$slug = sanitize_key( (string) $taxonomy->name );
			if ( '' === $slug || in_array( $slug, $exclude, true ) ) {
				continue;
			}

			if ( empty( $taxonomy->public ) && empty( $taxonomy->show_ui ) ) {
				continue;
			}

			$label = (string) ( $taxonomy->labels->name ?? $slug );
			if ( '' === $label ) {
				$label = $slug;
			}

			$rows[] = [
				'slug'          => $slug,
				'label'         => $label,
				'hierarchical'  => ! empty( $taxonomy->hierarchical ),
			];
		}

		$rows = apply_filters( 'forwp_seo_inventory_taxonomies', $rows, $types );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$clean = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
			if ( '' === $slug || ! taxonomy_exists( $slug ) ) {
				continue;
			}
			$clean[] = [
				'slug'         => $slug,
				'label'        => (string) ( $row['label'] ?? $slug ),
				'hierarchical' => ! empty( $row['hierarchical'] ),
			];
		}

		usort(
			$clean,
			static function ( array $left, array $right ): int {
				return self::sort_rank( $left['slug'] ) <=> self::sort_rank( $right['slug'] )
					?: strcasecmp( $left['label'], $right['label'] );
			}
		);

		return $clean;
	}

	public const TYPE_PREFIX = 'taxonomy-';

	public static function type_slug( string $taxonomy ): string {
		$taxonomy = sanitize_key( $taxonomy );

		return '' === $taxonomy ? '' : self::TYPE_PREFIX . $taxonomy;
	}

	public static function taxonomy_from_type_filter( string $type ): string {
		$type = sanitize_key( $type );
		if ( ! str_starts_with( $type, self::TYPE_PREFIX ) ) {
			return '';
		}

		$taxonomy = sanitize_key( substr( $type, strlen( self::TYPE_PREFIX ) ) );

		return taxonomy_exists( $taxonomy ) ? $taxonomy : '';
	}

	/**
	 * Public taxonomy archives that have a front-end URL (like a page).
	 *
	 * @return list<array{slug: string, label: string, hierarchical: bool}>
	 */
	public static function get_archive_labeled(): array {
		$excluded = array_fill_keys( ExcludedTaxonomies::get(), true );
		$rows     = [];
		foreach ( self::get_discovered_archive_labeled() as $row ) {
			if ( isset( $excluded[ $row['slug'] ] ) ) {
				continue;
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Public archive taxonomies before the saved user exclude list.
	 *
	 * @return list<array{slug: string, label: string, hierarchical: bool}>
	 */
	public static function get_discovered_archive_labeled(): array {
		$rows = [];
		foreach ( self::get_labeled() as $row ) {
			if ( self::is_public_archive( $row['slug'] ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * @return list<string>
	 */
	public static function get_discovered_archive_slugs(): array {
		return array_column( self::get_discovered_archive_labeled(), 'slug' );
	}

	/**
	 * All archive taxonomies, including those the user has excluded.
	 *
	 * @return list<array{slug: string, label: string, hierarchical: bool, excluded: bool}>
	 */
	public static function get_manageable_archive_labeled(): array {
		$excluded = array_fill_keys( ExcludedTaxonomies::get(), true );
		$items    = [];

		foreach ( self::get_discovered_archive_labeled() as $row ) {
			$row['excluded'] = isset( $excluded[ $row['slug'] ] );
			$items[]         = $row;
		}

		return $items;
	}

	public static function is_inventory_archive( string $taxonomy ): bool {
		$taxonomy = sanitize_key( $taxonomy );
		if ( ! self::is_public_archive( $taxonomy ) ) {
			return false;
		}

		return ! in_array( $taxonomy, ExcludedTaxonomies::get(), true );
	}

	public static function is_public_archive( string $taxonomy ): bool {
		$taxonomy = sanitize_key( $taxonomy );
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$object = get_taxonomy( $taxonomy );
		if ( ! $object instanceof \WP_Taxonomy ) {
			return false;
		}

		if ( empty( $object->public ) ) {
			return false;
		}

		$viewable = ! empty( $object->publicly_queryable );
		if ( ! $viewable && is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) {
			$viewable = true;
		}

		return (bool) apply_filters( 'forwp_seo_inventory_taxonomy_is_viewable', $viewable, $object );
	}

	/**
	 * Terms for a taxonomy (used in inventory filter).
	 *
	 * @return list<array{id: int, name: string, slug: string, parent: int}>
	 */
	public static function get_terms( string $taxonomy ): array {
		$taxonomy = sanitize_key( $taxonomy );
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 500,
			]
		);

		if ( ! is_array( $terms ) ) {
			return [];
		}

		$out = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$out[] = [
				'id'     => (int) $term->term_id,
				'name'   => (string) $term->name,
				'slug'   => (string) $term->slug,
				'parent' => (int) $term->parent,
			];
		}

		return $out;
	}

	/**
	 * Public archive terms for inventory rows (category pages, tag pages, …).
	 *
	 * @return list<\WP_Term>
	 */
	public static function get_inventory_terms( string $taxonomy, string $search = '', int $term_id = 0 ): array {
		$taxonomy = sanitize_key( $taxonomy );
		if ( '' === $taxonomy || ! self::is_inventory_archive( $taxonomy ) ) {
			return [];
		}

		$args = [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
			'number'     => 2000,
		];

		if ( $term_id > 0 ) {
			$args['include'] = [ $term_id ];
		}

		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$terms = get_terms( $args );
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$out = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$out[] = $term;
			}
		}

		return $out;
	}

	/**
	 * Terms assigned to a post, grouped by inventory taxonomies.
	 *
	 * @return list<array{taxonomy: string, label: string, terms: list<array{id: int, name: string, slug: string}>}>
	 */
	public static function get_post_groups( int $post_id, string $post_type ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || '' === $post_type ) {
			return [];
		}

		$groups = [];
		foreach ( self::get_labeled( [ $post_type ] ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy['slug'] );
			if ( ! is_array( $terms ) || [] === $terms ) {
				continue;
			}

			$items = [];
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$items[] = [
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
					'slug' => (string) $term->slug,
				];
			}

			if ( [] === $items ) {
				continue;
			}

			$groups[] = [
				'taxonomy' => $taxonomy['slug'],
				'label'    => $taxonomy['label'],
				'terms'    => $items,
			];
		}

		return $groups;
	}

	/**
	 * Plain text for CSV / REST flattening.
	 *
	 * @param list<array{taxonomy: string, label: string, terms: list<array{id: int, name: string, slug: string}>}> $groups
	 */
	public static function format_plain( array $groups ): string {
		$parts = [];
		foreach ( $groups as $group ) {
			$names = [];
			foreach ( $group['terms'] ?? [] as $term ) {
				$name = trim( (string) ( $term['name'] ?? '' ) );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}
			if ( [] === $names ) {
				continue;
			}
			$label   = (string) ( $group['label'] ?? $group['taxonomy'] ?? '' );
			$parts[] = $label . ': ' . implode( ', ', $names );
		}

		return implode( ' | ', $parts );
	}

	/**
	 * @param list<string>|string $post_types
	 * @return list<string>
	 */
	private static function normalize_post_types( $post_types ): array {
		if ( is_string( $post_types ) && '' !== $post_types ) {
			$post_types = [ $post_types ];
		}

		if ( ! is_array( $post_types ) || [] === $post_types ) {
			return PostTypeDiscovery::get_slugs();
		}

		$supported = array_fill_keys( PostTypeDiscovery::get_slugs(), true );
		$out       = [];
		foreach ( $post_types as $type ) {
			$type = sanitize_key( (string) $type );
			if ( '' !== $type && isset( $supported[ $type ] ) ) {
				$out[] = $type;
			}
		}

		return array_values( array_unique( $out ) );
	}

	private static function sort_rank( string $slug ): int {
		return match ( $slug ) {
			'category' => 0,
			'post_tag' => 1,
			default    => 10,
		};
	}
}
