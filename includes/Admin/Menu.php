<?php
/**
 * Admin menu registration.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Core\Release;
use Forwp\SeoHelper\Gsc\Admin as GscAdmin;
use Forwp\SeoHelper\Gsc\Module as GscModule;
use Forwp\SeoHelper\Inventory\PriorityLabels;
use Forwp\SeoHelper\Inventory\Repository;
use Forwp\SeoHelper\Multilingual\Registry as MultilingualRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Menu {
	public const PAGE_SLUG                 = 'forwp-seo';
	public const SETTINGS_PAGE_SLUG        = 'forwp-seo-settings';
	public const GSC_PAGE_SLUG             = 'forwp-seo-gsc';
	public const OAUTH_PAGE_SLUG           = 'forwp-seo-gsc-oauth';
	public const INVENTORY_PAGE_SLUG       = 'forwp-seo-inventory';
	public const DYNAMICS_PAGE_SLUG        = 'forwp-seo-dynamics';
	public const AI_PAGE_SLUG              = 'forwp-seo-ai';
	public const TRENDS_PAGE_SLUG          = 'forwp-seo-trends';
	public const POSITIONS_PAGE_SLUG       = 'forwp-seo-positions';
	public const INVENTORY_POST_TYPE_ARG   = 'forwp_post_type';
	public const INVENTORY_TAXONOMY_ARG    = 'forwp_taxonomy';
	public const INVENTORY_TERM_ARG        = 'forwp_term';
	public const INVENTORY_PER_PAGE_OPTION  = 'forwp_seo_inventory_per_page';
	public const INVENTORY_PER_PAGE_DEFAULT = 20;

	private static $instance = null;

	/** @var string|null Return value from add_submenu_page() — used for load-* / hook_suffix. */
	private static $inventory_page_hook = null;

	/** @var string|null */
	private static $settings_page_hook = null;

	/** @var string|null */
	private static $gsc_page_hook = null;

	/** @var string|null */
	private static $dynamics_page_hook = null;

	/** @var string|null */
	private static $dashboard_page_hook = null;

	/** @var string|null */
	private static $ai_page_hook = null;

	/** @var string|null */
	private static $trends_page_hook = null;

	/** @var string|null */
	private static $positions_page_hook = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'protect_plugin_screens' ], 0 );
		add_action( 'admin_init', [ $this, 'handle_legacy_routes' ] );
		add_action( 'admin_init', [ $this, 'handle_admin_form_posts' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_filter(
			'set_screen_option_' . self::INVENTORY_PER_PAGE_OPTION,
			static function ( $screen_option, $option, $value ) {
				unset( $screen_option, $option );
				return max( 1, min( 999, (int) $value ) );
			},
			10,
			3
		);
	}

	public function register_menu(): void {
		self::$dashboard_page_hook = add_menu_page(
			__( '4WP SEO Helper', '4wp-seo-helper' ),
			__( '4WP SEO', '4wp-seo-helper' ),
			'manage_options',
			self::PAGE_SLUG,
			[ DashboardPage::class, 'render' ],
			'dashicons-chart-line',
			30
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Dashboard', '4wp-seo-helper' ),
			__( 'Dashboard', '4wp-seo-helper' ),
			'manage_options',
			self::PAGE_SLUG,
			[ DashboardPage::class, 'render' ]
		);

		self::$inventory_page_hook = add_submenu_page(
			self::PAGE_SLUG,
			__( 'SEO Inventory', '4wp-seo-helper' ),
			__( 'SEO Inventory', '4wp-seo-helper' ),
			'manage_options',
			self::INVENTORY_PAGE_SLUG,
			[ InventoryPage::class, 'render' ]
		);

		if ( Release::is_module_public( Release::MODULE_GSC ) ) {
			add_submenu_page(
				null,
				__( 'Google OAuth', '4wp-seo-helper' ),
				__( 'Google OAuth', '4wp-seo-helper' ),
				'manage_options',
				self::OAUTH_PAGE_SLUG,
				'__return_null'
			);
		}

		if ( Release::is_module_public( Release::MODULE_GSC ) && GscModule::get_instance()->is_enabled() ) {
			self::$gsc_page_hook = add_submenu_page(
				self::PAGE_SLUG,
				__( 'Search Console', '4wp-seo-helper' ),
				__( 'G Webmaster Con.', '4wp-seo-helper' ),
				'manage_options',
				self::GSC_PAGE_SLUG,
				[ GscPage::class, 'render' ]
			);
		}

		self::$trends_page_hook = add_submenu_page(
			self::PAGE_SLUG,
			__( 'Google Trends', '4wp-seo-helper' ),
			__( 'Google Trends', '4wp-seo-helper' ),
			'manage_options',
			self::TRENDS_PAGE_SLUG,
			[ TrendsPage::class, 'render' ]
		);

		self::$positions_page_hook = add_submenu_page(
			self::PAGE_SLUG,
			__( 'Positions', '4wp-seo-helper' ),
			__( 'Positions', '4wp-seo-helper' ),
			'manage_options',
			self::POSITIONS_PAGE_SLUG,
			[ PositionsPage::class, 'render' ]
		);

		self::$ai_page_hook = add_submenu_page(
			null,
			__( 'AI Assistant', '4wp-seo-helper' ),
			__( 'AI Assistant', '4wp-seo-helper' ),
			'manage_options',
			self::AI_PAGE_SLUG,
			[ AiAssistantPage::class, 'render' ]
		);

		self::$settings_page_hook = add_submenu_page(
			self::PAGE_SLUG,
			__( 'Settings', '4wp-seo-helper' ),
			__( 'Settings', '4wp-seo-helper' ),
			'manage_options',
			self::SETTINGS_PAGE_SLUG,
			[ Page::class, 'render' ]
		);

		self::$dynamics_page_hook = add_submenu_page(
			self::PAGE_SLUG,
			__( 'Dynamics', '4wp-seo-helper' ),
			__( 'Dynamics', '4wp-seo-helper' ) . ' <span class="forwp-seo-menu-badge">' . esc_html__( 'Dev', '4wp-seo-helper' ) . '</span>',
			'manage_options',
			self::DYNAMICS_PAGE_SLUG,
			[ DynamicsPage::class, 'render' ]
		);

		if ( is_string( self::$dashboard_page_hook ) && '' !== self::$dashboard_page_hook ) {
			add_action( 'load-' . self::$dashboard_page_hook, [ DashboardPage::class, 'on_load' ] );
		}

		if ( is_string( self::$gsc_page_hook ) && '' !== self::$gsc_page_hook ) {
			add_action( 'load-' . self::$gsc_page_hook, [ GscPage::class, 'handle_load' ] );
		}

		if ( is_string( self::$settings_page_hook ) && '' !== self::$settings_page_hook ) {
			add_action( 'load-' . self::$settings_page_hook, [ Page::class, 'handle_load' ] );
		}

		if ( is_string( self::$inventory_page_hook ) && '' !== self::$inventory_page_hook ) {
			add_action( 'load-' . self::$inventory_page_hook, [ $this, 'inventory_screen_options' ] );
		}

		if ( is_string( self::$trends_page_hook ) && '' !== self::$trends_page_hook ) {
			add_action( 'load-' . self::$trends_page_hook, [ TrendsPage::class, 'handle_load' ] );
		}

		if ( is_string( self::$positions_page_hook ) && '' !== self::$positions_page_hook ) {
			add_action( 'load-' . self::$positions_page_hook, [ PositionsPage::class, 'handle_load' ] );
		}
	}

	/**
	 * WordPress treats ?post_type=page (and other registered types) as a core screen.
	 * That makes admin.php look up our submenu under admin.php?post_type=X and die with
	 * "Cannot load forwp-seo-inventory". Clear $typenow on our plugin pages.
	 */
	public function protect_plugin_screens(): void {
		global $plugin_page, $typenow;

		if ( ! is_string( $plugin_page ) || ! str_starts_with( $plugin_page, 'forwp-seo' ) ) {
			return;
		}

		$typenow = '';
	}

	public function handle_legacy_routes(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Legacy admin URL redirects.
		$page         = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$tab          = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		$history_post = isset( $_GET['history_post'] ) ? absint( wp_unslash( (string) $_GET['history_post'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		if ( $history_post > 0 ) {
			wp_safe_redirect( DynamicsPage::url_for_post( $history_post ) );
			exit;
		}

		if ( 'gsc' === $tab ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG . '&tab=gsc' ) );
			exit;
		}

		if ( in_array( $tab, [ 'overview', 'settings', 'api' ], true ) ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page' => self::SETTINGS_PAGE_SLUG,
						'tab'  => $tab,
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	public function handle_admin_form_posts(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Route detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$redirect = null;

		if ( self::SETTINGS_PAGE_SLUG === $page ) {
			$redirect = Page::process_settings_post();
			if ( ! is_string( $redirect ) ) {
				$redirect = GscAdmin::get_instance()->handle_connect_post();
			}
		} elseif ( self::GSC_PAGE_SLUG === $page ) {
			$redirect = GscAdmin::get_instance()->process_page_requests();
		}

		if ( is_string( $redirect ) && '' !== $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		wp_enqueue_style(
			'forwp-seo-admin-menu',
			FORWP_SEO_HELPER_URL . 'assets/css/admin-menu.css',
			[],
			FORWP_SEO_HELPER_VERSION
		);

		if (
			'toplevel_page_' . self::PAGE_SLUG === $hook_suffix
			|| ( is_string( self::$dashboard_page_hook ) && self::$dashboard_page_hook === $hook_suffix )
		) {
			wp_enqueue_style(
				'forwp-seo-admin-settings',
				FORWP_SEO_HELPER_URL . 'assets/css/admin-settings.css',
				[],
				FORWP_SEO_HELPER_VERSION
			);
			wp_enqueue_script(
				'forwp-seo-admin-tabs',
				FORWP_SEO_HELPER_URL . 'assets/js/admin-tabs.js',
				[],
				FORWP_SEO_HELPER_VERSION,
				true
			);
			return;
		}

		if (
			( is_string( self::$ai_page_hook ) && self::$ai_page_hook === $hook_suffix )
			|| str_ends_with( $hook_suffix, '_page_' . self::AI_PAGE_SLUG )
		) {
			wp_enqueue_style( 'wp-components' );
			wp_enqueue_style(
				'forwp-seo-admin-settings',
				FORWP_SEO_HELPER_URL . 'assets/css/admin-settings.css',
				[ 'wp-components' ],
				FORWP_SEO_HELPER_VERSION
			);
			wp_enqueue_script(
				'forwp-seo-admin-tabs',
				FORWP_SEO_HELPER_URL . 'assets/js/admin-tabs.js',
				[],
				FORWP_SEO_HELPER_VERSION,
				true
			);
			return;
		}

		if ( is_string( self::$dynamics_page_hook ) && self::$dynamics_page_hook === $hook_suffix ) {
			wp_enqueue_style(
				'forwp-seo-admin-settings',
				FORWP_SEO_HELPER_URL . 'assets/css/admin-settings.css',
				[],
				FORWP_SEO_HELPER_VERSION
			);
			return;
		}

		if (
			( is_string( self::$trends_page_hook ) && self::$trends_page_hook === $hook_suffix )
			|| str_ends_with( $hook_suffix, '_page_' . self::TRENDS_PAGE_SLUG )
		) {
			wp_enqueue_style(
				'forwp-seo-admin-settings',
				FORWP_SEO_HELPER_URL . 'assets/css/admin-settings.css',
				[],
				FORWP_SEO_HELPER_VERSION
			);
			wp_enqueue_style(
				'forwp-seo-admin-trends',
				FORWP_SEO_HELPER_URL . 'assets/css/admin-trends.css',
				[ 'forwp-seo-admin-settings' ],
				(string) filemtime( FORWP_SEO_HELPER_PATH . 'assets/css/admin-trends.css' )
			);
			wp_enqueue_script(
				'forwp-seo-admin-trends',
				FORWP_SEO_HELPER_URL . 'assets/js/admin-trends.js',
				[],
				(string) filemtime( FORWP_SEO_HELPER_PATH . 'assets/js/admin-trends.js' ),
				true
			);
			return;
		}

		if (
			( is_string( self::$positions_page_hook ) && self::$positions_page_hook === $hook_suffix )
			|| str_ends_with( $hook_suffix, '_page_' . self::POSITIONS_PAGE_SLUG )
		) {
			wp_enqueue_style( 'wp-components' );
			wp_enqueue_style(
				'forwp-seo-admin-settings',
				FORWP_SEO_HELPER_URL . 'assets/css/admin-settings.css',
				[ 'wp-components' ],
				(string) filemtime( FORWP_SEO_HELPER_PATH . 'assets/css/admin-settings.css' )
			);
			wp_enqueue_script(
				'forwp-seo-admin-tabs',
				FORWP_SEO_HELPER_URL . 'assets/js/admin-tabs.js',
				[],
				(string) filemtime( FORWP_SEO_HELPER_PATH . 'assets/js/admin-tabs.js' ),
				true
			);
			return;
		}

		if ( is_string( self::$settings_page_hook ) && self::$settings_page_hook === $hook_suffix ) {
			wp_enqueue_style( 'wp-components' );
			wp_enqueue_style(
				'forwp-seo-admin-settings',
				FORWP_SEO_HELPER_URL . 'assets/css/admin-settings.css',
				[ 'wp-components' ],
				(string) filemtime( FORWP_SEO_HELPER_PATH . 'assets/css/admin-settings.css' )
			);
			wp_enqueue_script(
				'forwp-seo-admin-tabs',
				FORWP_SEO_HELPER_URL . 'assets/js/admin-tabs.js',
				[],
				(string) filemtime( FORWP_SEO_HELPER_PATH . 'assets/js/admin-tabs.js' ),
				true
			);
			return;
		}

		if (
			( is_string( self::$gsc_page_hook ) && self::$gsc_page_hook === $hook_suffix )
			|| str_ends_with( $hook_suffix, '_page_' . self::GSC_PAGE_SLUG )
		) {
			wp_enqueue_style( 'wp-components' );
			wp_enqueue_style(
				'forwp-seo-admin-settings',
				FORWP_SEO_HELPER_URL . 'assets/css/admin-settings.css',
				[ 'wp-components' ],
				FORWP_SEO_HELPER_VERSION
			);
			wp_enqueue_style(
				'forwp-seo-gsc-data',
				FORWP_SEO_HELPER_URL . 'assets/css/gsc-data.css',
				[ 'forwp-seo-admin-settings' ],
				FORWP_SEO_HELPER_VERSION
			);
			wp_enqueue_script(
				'forwp-seo-admin-tabs',
				FORWP_SEO_HELPER_URL . 'assets/js/admin-tabs.js',
				[],
				FORWP_SEO_HELPER_VERSION,
				true
			);
			wp_enqueue_script(
				'forwp-seo-gsc-sync-status',
				FORWP_SEO_HELPER_URL . 'assets/js/gsc-sync-status.js',
				[],
				FORWP_SEO_HELPER_VERSION,
				true
			);
			return;
		}

		if ( is_string( self::$inventory_page_hook ) && self::$inventory_page_hook === $hook_suffix ) {
			$this->enqueue_inventory_assets();
		}
	}

	public function inventory_screen_options(): void {
		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Inventory items per page', '4wp-seo-helper' ),
				'default' => self::INVENTORY_PER_PAGE_DEFAULT,
				'option'  => self::INVENTORY_PER_PAGE_OPTION,
			]
		);

		$this->ensure_inventory_gsc_columns_visible();
	}

	/**
	 * GSC index columns must stay visible — users rely on them daily.
	 */
	private function ensure_inventory_gsc_columns_visible(): void {
		if ( ! InventoryPage::should_show_gsc_indexing() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}

		$gsc_cols = [ 'gsc_index_status', 'gsc_index_requested', 'gsc_last_crawl', 'gsc_actions' ];
		$key      = 'manage' . $screen->id . 'columnshidden';
		$hidden   = get_user_option( $key );

		if ( ! is_array( $hidden ) ) {
			return;
		}

		$next = array_values( array_diff( $hidden, $gsc_cols ) );
		if ( $next !== $hidden ) {
			update_user_option( get_current_user_id(), $key, $next );
		}
	}

	public function enqueue_inventory_assets(): void {
		$table = new InventoryListTable( new Repository() );
		$table->set_show_language( count( MultilingualRegistry::get_active()->get_languages() ) > 1 );
		$table->set_show_gsc_metrics( InventoryPage::should_show_gsc_metrics() );
		$table->set_show_gsc_indexing( InventoryPage::should_show_gsc_indexing() );
		$columns = $table->get_columns();
		$screen  = get_current_screen();
		$hidden  = $screen instanceof \WP_Screen ? get_hidden_columns( $screen ) : [];
		$colspan = count( $columns ) + 1;

		wp_enqueue_style(
			'forwp-seo-inventory-priority',
			FORWP_SEO_HELPER_URL . 'assets/css/inventory-priority.css',
			[],
			(string) filemtime( FORWP_SEO_HELPER_PATH . 'assets/css/inventory-priority.css' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin view for script localization.
		$view = sanitize_key( wp_unslash( (string) ( $_GET['view'] ?? 'inventory' ) ) );

		wp_enqueue_script(
			'forwp-seo-inventory-priority',
			FORWP_SEO_HELPER_URL . 'assets/js/inventory-priority.js',
			[],
			FORWP_SEO_HELPER_VERSION,
			true
		);

		wp_localize_script(
			'forwp-seo-inventory-priority',
			'forwpSeoInventoryPriority',
			[
				'mode'    => 'queue' === $view ? 'queue' : 'inventory',
				'restUrl' => rest_url( 'forwp-seo-helper/v1/seo-inventory/priority-queue' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => [
					'saving'     => __( 'Saving…', '4wp-seo-helper' ),
					'saved'      => __( 'Priority saved.', '4wp-seo-helper' ),
					'error'      => __( 'Could not save priority.', '4wp-seo-helper' ),
					'emptyGroup' => __( 'No items — drop here', '4wp-seo-helper' ),
					'emptyLane'  => __( 'Empty', '4wp-seo-helper' ),
					'oneItem'    => __( '1 item', '4wp-seo-helper' ),
					'items'      => __( 'items', '4wp-seo-helper' ),
					'more'       => __( 'more', '4wp-seo-helper' ),
					/* translators: %d: average completeness percent */
					'avgScore'   => __( 'Avg %d%%', '4wp-seo-helper' ),
					/* translators: %d: number of items with SEO gaps */
					'withGaps'   => __( '%d with gaps', '4wp-seo-helper' ),
				],
				'priorityLabels' => [
					'1' => PriorityLabels::get_formatted( 1 ),
					'2' => PriorityLabels::get_formatted( 2 ),
					'3' => PriorityLabels::get_formatted( 3 ),
				],
			]
		);

		if ( 'inventory' !== $view && 'queue' !== $view ) {
			return;
		}

		if ( 'inventory' === $view ) {
			wp_enqueue_media();
		}

		if ( InventoryPage::should_show_gsc_indexing() ) {
			wp_enqueue_script(
				'forwp-seo-inventory-gsc-actions',
				FORWP_SEO_HELPER_URL . 'assets/js/inventory-gsc-actions.js',
				[],
				FORWP_SEO_HELPER_VERSION,
				true
			);

			wp_localize_script(
				'forwp-seo-inventory-gsc-actions',
				'forwpSeoInventoryGsc',
				[
					'restRoot' => rest_url( 'forwp-seo/v1/' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'i18n'     => [
						'refresh'      => __( 'Refresh status', '4wp-seo-helper' ),
						'requestIndex' => __( 'Request indexing', '4wp-seo-helper' ),
						'refreshing'   => __( 'Refreshing…', '4wp-seo-helper' ),
						'requesting'   => __( 'Opening GSC…', '4wp-seo-helper' ),
						'inspectError' => __( 'Inspect error', '4wp-seo-helper' ),
						'notInspected' => __( 'Not inspected yet', '4wp-seo-helper' ),
						'error'        => __( 'Search Console action failed.', '4wp-seo-helper' ),
					],
				]
			);
		}

		wp_enqueue_script(
			'forwp-seo-inventory-quick-edit',
			FORWP_SEO_HELPER_URL . 'assets/js/inventory-quick-edit.js',
			[ 'jquery' ],
			(string) filemtime( FORWP_SEO_HELPER_PATH . 'assets/js/inventory-quick-edit.js' ),
			true
		);

		wp_localize_script(
			'forwp-seo-inventory-quick-edit',
			'forwpSeoInventoryQuickEdit',
			[
				'restUrl' => rest_url( 'forwp-seo-helper/v1/seo-inventory/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'colspan' => max( 1, $colspan ),
				'i18n'    => [
					'quickEdit'    => __( 'Quick Edit', '4wp-seo-helper' ),
					'save'         => __( 'Update', '4wp-seo-helper' ),
					'cancel'       => __( 'Cancel', '4wp-seo-helper' ),
					'saving'       => __( 'Saving…', '4wp-seo-helper' ),
					'error'        => __( 'Could not save changes.', '4wp-seo-helper' ),
					'empty'        => __( 'Empty', '4wp-seo-helper' ),
					'seoTitle'     => __( 'SEO title', '4wp-seo-helper' ),
					'metaDesc'     => __( 'Meta description', '4wp-seo-helper' ),
					'focusKw'      => __( 'Focus keyphrases', '4wp-seo-helper' ),
					'focusKwHint'  => __( 'One phrase per line. First line = primary (synced to Yoast).', '4wp-seo-helper' ),
					'ogImage'      => __( 'OG image', '4wp-seo-helper' ),
					'selectOgImage'=> __( 'Select image', '4wp-seo-helper' ),
					'useImage'     => __( 'Use image', '4wp-seo-helper' ),
					'removeImage'  => __( 'Remove image', '4wp-seo-helper' ),
					'noImage'      => __( 'No image selected', '4wp-seo-helper' ),
					'noOgImage'    => __( 'No OG image', '4wp-seo-helper' ),
					'viewOgImage'  => __( 'View OG image', '4wp-seo-helper' ),
					'saved'        => __( 'Saved.', '4wp-seo-helper' ),
					'gscQueries'   => __( 'Search Console queries', '4wp-seo-helper' ),
					'gscHint'      => __( 'Queries already sending traffic to this URL. Click Add to use as a focus keyphrase.', '4wp-seo-helper' ),
					'gscEmpty'     => __( 'No Search Console queries synced for this URL yet.', '4wp-seo-helper' ),
					/* translators: 1: clicks, 2: impressions, 3: average position */
					'gscMetrics'   => __( '%1$s clicks · %2$s impr. · Pos %3$s', '4wp-seo-helper' ),
					'gscAdd'       => __( 'Add', '4wp-seo-helper' ),
					'gscAdded'     => __( 'Added', '4wp-seo-helper' ),
				],
			]
		);
	}
}
