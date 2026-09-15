<?php
/**
 * Admin notices for SEO Helper.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Inventory\Module as InventoryModule;
use Forwp\SeoHelper\SeoMeta\Registry as SeoMetaRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notices {
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
	}

	public function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$adapter = SeoMetaRegistry::get_active();
		if ( 'none' === $adapter->get_id() ) {
			$this->render_notice(
				'warning',
				__( '4WP SEO Helper: no SEO plugin detected (Yoast or All in One SEO). SEO inventory reads and bulk updates will not work until one is active.', '4wp-seo-helper' )
			);
		}

		if ( ! InventoryModule::get_instance()->is_enabled() && $this->is_seo_admin_screen() ) {
			$this->render_notice(
				'info',
				__( 'SEO inventory REST API is disabled. Enable it under 4WP SEO → Settings.', '4wp-seo-helper' )
			);
		}
	}

	private function is_seo_admin_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		return in_array(
			$page,
			[
				Menu::PAGE_SLUG,
				Menu::SETTINGS_PAGE_SLUG,
				Menu::GSC_PAGE_SLUG,
				Menu::INVENTORY_PAGE_SLUG,
				Menu::AI_PAGE_SLUG,
			],
			true
		);
	}

	private function render_notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
