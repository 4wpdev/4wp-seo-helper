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
