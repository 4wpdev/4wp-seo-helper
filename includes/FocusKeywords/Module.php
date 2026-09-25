<?php
/**
 * Focus Keywords module bootstrap.
 */

namespace Forwp\SeoHelper\FocusKeywords;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Module {
	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate(): void {
		Schema::install();
	}

	private function __construct() {
		if ( ! Schema::tables_exist() ) {
			Schema::install();
		} else {
			Schema::maybe_upgrade();
		}
	}
}
