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

	private const NOTICE_NO_ADAPTER = 'no_adapter';
	private const DISMISS_META      = 'forwp_seo_dismissed_notice_';

	private function __construct() {
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_forwp_seo_dismiss_notice', [ $this, 'handle_dismiss' ] );
	}

	public function enqueue(): void {
		if ( ! current_user_can( 'manage_options' ) || ! $this->should_show_no_adapter() ) {
			return;
		}

		wp_enqueue_script(
			'forwp-seo-admin-notices',
			FORWP_SEO_HELPER_URL . 'assets/js/admin-notices.js',
			[],
			(string) filemtime( FORWP_SEO_HELPER_PATH . 'assets/js/admin-notices.js' ),
			true
		);
		wp_localize_script(
			'forwp-seo-admin-notices',
			'forwpSeoNotices',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'forwp_seo_dismiss_notice' ),
			]
		);
	}

	public function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		check_ajax_referer( 'forwp_seo_dismiss_notice', 'nonce' );

		$notice = isset( $_POST['notice'] ) ? sanitize_key( wp_unslash( (string) $_POST['notice'] ) ) : '';
		if ( self::NOTICE_NO_ADAPTER !== $notice ) {
			wp_send_json_error( [ 'message' => 'unknown_notice' ], 400 );
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META . $notice, '1' );
		wp_send_json_success();
	}

	public function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->should_show_no_adapter() ) {
			$this->render_notice(
				'warning',
				__( '4WP SEO Helper: no SEO plugin detected (Yoast or All in One SEO). SEO inventory reads and bulk updates will not work until one is active.', '4wp-seo-helper' ),
				self::NOTICE_NO_ADAPTER
			);
		}

		if ( ! InventoryModule::get_instance()->is_enabled() && $this->is_seo_admin_screen() ) {
			$this->render_notice(
				'info',
				__( 'SEO inventory REST API is disabled. Enable it under 4WP SEO → Settings.', '4wp-seo-helper' )
			);
		}
	}

	private function should_show_no_adapter(): bool {
		$adapter = SeoMetaRegistry::get_active();
		if ( 'none' !== $adapter->get_id() ) {
			return false;
		}

		return ! (bool) get_user_meta( get_current_user_id(), self::DISMISS_META . self::NOTICE_NO_ADAPTER, true );
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
				Menu::TRENDS_PAGE_SLUG,
				Menu::POSITIONS_PAGE_SLUG,
				Menu::DYNAMICS_PAGE_SLUG,
			],
			true
		);
	}

	private function render_notice( string $type, string $message, string $notice_id = '' ): void {
		$class = 'notice notice-' . $type . ' is-dismissible';
		$attr  = '';
		if ( '' !== $notice_id ) {
			$class .= ' forwp-seo-persistent-notice';
			$attr   = ' data-notice="' . esc_attr( $notice_id ) . '"';
		}

		printf(
			'<div class="%1$s"%2$s><p>%3$s</p></div>',
			esc_attr( $class ),
			$attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr above.
			esc_html( $message )
		);
	}
}
