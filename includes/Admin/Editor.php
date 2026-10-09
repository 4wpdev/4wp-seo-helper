<?php
/**
 * Editor sidebar assets.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Core\Release;
use Forwp\SeoHelper\CrossPosting\Module as CrossPostingModule;
use Forwp\SeoHelper\CrossPosting\Platforms;
use Forwp\SeoHelper\Gsc\Admin as GscAdmin;
use Forwp\SeoHelper\Gsc\Module as GscModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Editor {
	private static $instance = null;
	private static $enqueued = false;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets_fallback' ] );
	}

	public function enqueue_assets(): void {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		wp_enqueue_style(
			'forwp-seo-editor-sidebar',
			FORWP_SEO_HELPER_URL . 'assets/css/editor-sidebar.css',
			[],
			FORWP_SEO_HELPER_VERSION
		);

		$settings = [
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'baseUrl'             => esc_url_raw( rest_url( 'forwp-seo/v1' ) ),
			'crosspostingEnabled' => CrossPostingModule::get_instance()->is_enabled()
				&& Release::is_module_public( Release::MODULE_CROSSPOSTING ),
			'crosspostingPublic'  => Release::is_module_public( Release::MODULE_CROSSPOSTING ),
			'platformsUrl'        => current_user_can( 'manage_options' ) ? Platforms::admin_url() : '',
			'techarticleEnabled'  => Release::is_module_public( Release::MODULE_TECHARTICLE ),
			'gscEnabled'          => Release::is_module_public( Release::MODULE_GSC )
				&& GscModule::get_instance()->is_enabled(),
			'gscConnected'        => GscAdmin::get_instance()->is_connected(),
			'gscSettingsUrl'      => GscAdmin::get_instance()->get_settings_url(),
		];

		$deps = [ 'wp-plugins', 'wp-edit-post', 'wp-data', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-blocks' ];
		$handle = 'forwp-seo-editor-sidebar';

		wp_register_script( $handle, '', $deps, FORWP_SEO_HELPER_VERSION, true );
		wp_enqueue_script( $handle );

		wp_add_inline_script(
			$handle,
			'window.forwpSeoSidebar = ' . wp_json_encode( $settings ) . ';',
			'before'
		);

		$script_path = FORWP_SEO_HELPER_PATH . 'assets/js/seo-sidebar.js';
		if ( file_exists( $script_path ) ) {
			$inline = file_get_contents( $script_path );
			if ( $inline ) {
				wp_add_inline_script( $handle, $inline, 'after' );
				return;
			}
		}

		wp_add_inline_script( $handle, "console.warn('[forwp-seo] seo-sidebar.js missing');", 'after' );
	}

	public function enqueue_assets_fallback( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->is_block_editor ) ) {
			return;
		}

		$this->enqueue_assets();
	}
}


