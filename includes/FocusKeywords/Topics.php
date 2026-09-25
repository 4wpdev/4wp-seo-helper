<?php
/**
 * Keyword bank topic catalog (labels only).
 */

namespace Forwp\SeoHelper\FocusKeywords;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Topics {
	/**
	 * @return list<string>
	 */
	public static function all(): array {
		global $wpdb;

		if ( ! Schema::tables_exist() ) {
			return [];
		}
		Schema::ensure_topics_table();

		$table = Schema::topics_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT name FROM {$table} ORDER BY name ASC" );

		return is_array( $rows ) ? array_values( array_map( 'strval', $rows ) ) : [];
	}

	public static function add( string $name ): bool {
		global $wpdb;

		$name = Bank::normalize_topic( $name );
		if ( '' === $name ) {
			return false;
		}

		Schema::ensure_topics_table();
		$key   = self::key( $name );
		$table = Schema::topics_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE name_key = %s LIMIT 1",
				$key
			)
		);
		if ( $exists > 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->insert(
			$table,
			[
				'name'       => $name,
				'name_key'   => $key,
				'created_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s' ]
		);
	}

	/**
	 * @param list<string> $names
	 * @return int Added count.
	 */
	public static function add_many( array $names ): int {
		$added = 0;
		foreach ( $names as $name ) {
			if ( self::add( (string) $name ) ) {
				++$added;
			}
		}
		return $added;
	}

	public static function delete( string $name ): bool {
		global $wpdb;

		$name = Bank::normalize_topic( $name );
		if ( '' === $name || ! Schema::tables_exist() ) {
			return false;
		}
		Schema::ensure_topics_table();

		$table = Schema::topics_table();
		$key   = self::key( $name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->delete( $table, [ 'name_key' => $key ], [ '%s' ] );

		return $deleted > 0;
	}

	/**
	 * Default starter topics for 4wp.dev content clusters.
	 *
	 * @return list<string>
	 */
	public static function defaults(): array {
		return [
			'Headless WordPress',
			'Hooks',
			'WP-CLI',
			'REST API',
		];
	}

	/**
	 * Ensure default topics exist (idempotent).
	 */
	public static function seed_defaults(): int {
		return self::add_many( self::defaults() );
	}

	/**
	 * Pull distinct topics already used on keywords into the catalog.
	 */
	public static function sync_from_keywords(): int {
		global $wpdb;

		if ( ! Schema::tables_exist() ) {
			return 0;
		}
		Schema::ensure_topic_column();
		Schema::ensure_topics_table();

		$keywords = Schema::keywords_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT DISTINCT topic FROM {$keywords} WHERE topic <> ''" );
		if ( ! is_array( $rows ) || [] === $rows ) {
			return 0;
		}

		return self::add_many( $rows );
	}

	public static function key( string $name ): string {
		$name = Bank::normalize_topic( $name );
		if ( function_exists( 'mb_strtolower' ) ) {
			return (string) mb_strtolower( $name );
		}
		return strtolower( $name );
	}

	/**
	 * Render <select> options for topic assignment.
	 */
	public static function render_select_options( string $selected = '', bool $include_empty = true ): void {
		$catalog = self::all();
		if ( $include_empty ) {
			printf(
				'<option value="" %s>%s</option>',
				selected( $selected, '', false ),
				esc_html__( '— No topic —', '4wp-seo-helper' )
			);
		}
		if ( '' !== $selected && ! in_array( $selected, $catalog, true ) ) {
			printf(
				'<option value="%1$s" selected>%2$s</option>',
				esc_attr( $selected ),
				esc_html( $selected )
			);
		}
		foreach ( $catalog as $name ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $name ),
				selected( $selected, $name, false ),
				esc_html( $name )
			);
		}
	}
}
