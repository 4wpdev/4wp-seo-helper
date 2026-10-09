<?php
/**
 * Cross posting module toggle and hooks.
 */

namespace Forwp\SeoHelper\CrossPosting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Module {
	public const OPTION_ENABLED = 'forwp_seo_crossposting_enabled';

	/**
	 * Default hours between publishing the original and cross-posting (meta.json republish.delay_hours wins).
	 */
	public const OPTION_DELAY_HOURS = 'forwp_seo_crossposting_delay_hours';

	public const OPTION_DELAY_MINUTES = 'forwp_seo_crossposting_delay_minutes';

	public const DEFAULT_DELAY_HOURS = 24;

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Platforms::get_instance();
		Schedule::boot();
		add_action( 'init', [ $this, 'register_rest' ] );
	}

	public function is_enabled(): bool {
		return get_option( self::OPTION_ENABLED, '0' ) === '1';
	}

	public function delay_hours(): int {
		return max( 0, (int) get_option( self::OPTION_DELAY_HOURS, self::DEFAULT_DELAY_HOURS ) );
	}

	public function delay_minutes(): int {
		return min( 59, max( 0, (int) get_option( self::OPTION_DELAY_MINUTES, 0 ) ) );
	}

	public function register_rest(): void {
		Rest::get_instance();
	}
}


