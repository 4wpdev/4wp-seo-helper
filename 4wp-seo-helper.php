<?php
/**
 * Plugin Name: 4WP SEO Helper
 * Plugin URI:        https://4wp.dev/plugin/4wp-seo-helper/
 * Description: SEO Inventory and Google Search Console for WordPress — audit SEO fields site-wide, set P1–P3 priorities, sync Search Analytics, inspect URLs. Works with Yoast SEO and All in One SEO.
 * Version: 2.2.0
 * Author: 4wp.dev
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Tested up to:      7.1
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       4wp-seo-helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define(
	'FORWP_SEO_HELPER_VERSION',
	(string) ( get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version'] ?? '2.2.0' )
);
define( 'FORWP_SEO_HELPER_FILE', __FILE__ );
define( 'FORWP_SEO_HELPER_PATH', plugin_dir_path( __FILE__ ) );
define( 'FORWP_SEO_HELPER_URL', plugin_dir_url( __FILE__ ) );

require_once FORWP_SEO_HELPER_PATH . 'includes/autoload.php';

if ( class_exists( '\Forwp\SeoHelper\Core\Extension' ) ) {
	\Forwp\SeoHelper\Core\Extension::get_instance();
}

$forwp_seo_ai_loader = FORWP_SEO_HELPER_PATH . 'ai/load.php';
if ( is_readable( $forwp_seo_ai_loader ) ) {
	require_once $forwp_seo_ai_loader;
}

register_activation_hook(
	__FILE__,
	function () {
		if ( class_exists( '\Forwp\SeoHelper\Llms\Generator' ) ) {
			\Forwp\SeoHelper\Llms\Generator::activate();
		}
		if ( class_exists( '\Forwp\SeoHelper\Inventory\Module' ) ) {
			\Forwp\SeoHelper\Inventory\Module::activate();
		}
		if ( class_exists( '\Forwp\SeoHelper\Gsc\Module' ) ) {
			\Forwp\SeoHelper\Gsc\Module::activate();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( class_exists( '\Forwp\SeoHelper\Llms\Generator' ) ) {
			\Forwp\SeoHelper\Llms\Generator::deactivate();
		}
		if ( class_exists( '\Forwp\SeoHelper\Gsc\Module' ) ) {
			\Forwp\SeoHelper\Gsc\Module::deactivate();
		}
	}
);
