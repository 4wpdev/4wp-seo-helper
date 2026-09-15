<?php
/**
 * SEO Helper AI: shared field panel + inventory meta case.
 *
 * @package Forwp\SeoHelper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FORWP_AI_LAYER_LOADED' ) ) {
	define( 'FORWP_AI_LAYER_LOADED', true );
	define( 'FORWP_AI_LAYER_DIR', __DIR__ . '/' );
	define( 'FORWP_AI_LAYER_URL', trailingslashit( FORWP_SEO_HELPER_URL . 'ai' ) );

	require_once __DIR__ . '/class-client.php';
	require_once __DIR__ . '/class-panel.php';
}

require_once __DIR__ . '/class-inventory-meta.php';

\Forwp\SeoHelper\Ai\Inventory_Meta::init();
