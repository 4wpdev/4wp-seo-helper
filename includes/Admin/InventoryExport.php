<?php
/**
 * Admin CSV export for SEO inventory.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Inventory\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InventoryExport {
	public static function init(): void {
		add_action( 'admin_post_forwp_seo_inventory_export', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', '4wp-seo-helper' ) );
		}

		check_admin_referer( 'forwp_seo_inventory_export' );

		$repository = new Repository();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filter params echoed from inventory screen; export nonce verified above.
		$args       = [
			'page'      => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1,
			'per_page'  => 200,
			'post_type' => InventoryPage::get_requested_post_type(),
			'taxonomy'  => InventoryPage::get_requested_taxonomy(),
			'term'      => InventoryPage::get_requested_term(),
			'lang'      => sanitize_key( wp_unslash( (string) ( $_GET['lang'] ?? '' ) ) ),
			'status'    => sanitize_key( wp_unslash( (string) ( $_GET['status'] ?? 'publish' ) ) ),
			'missing'   => sanitize_key( wp_unslash( (string) ( $_GET['missing'] ?? '' ) ) ),
			'search'    => sanitize_text_field( wp_unslash( (string) ( $_GET['s'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$all_items = [];
		do {
			$result    = $repository->query( $args );
			$all_items = array_merge( $all_items, $result['items'] );
			++$args['page'];
		} while ( ! empty( $result['items'] ) && $args['page'] <= 50 );

		$columns = [
			'post_id',
			'lang',
			'post_type',
			'status',
			'wp_title',
			'url',
			'seo_title',
			'meta_description',
			'focus_keyword',
			'focus_keyphrases_text',
			'priority',
			'queue_position',
			'completeness',
			'missing',
			'taxonomies_text',
		];

		$lines = [ implode( ',', $columns ) ];
		foreach ( $all_items as $item ) {
			$row = [];
			foreach ( $columns as $column ) {
				$value = $item[ $column ] ?? '';
				if ( 'missing' === $column && is_array( $value ) ) {
					$value = implode( '|', $value );
				}
				$row[] = self::csv_escape( (string) $value );
			}
			$lines[] = implode( ',', $row );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="seo-inventory.csv"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV cells escaped via csv_escape().
		echo implode( "\n", $lines );
		exit;
	}

	private static function csv_escape( string $value ): string {
		$value = str_replace( '"', '""', $value );
		return '"' . $value . '"';
	}
}
