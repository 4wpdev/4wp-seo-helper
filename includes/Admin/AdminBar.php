<?php
/**
 * Frontend toolbar: clear page SEO status + actions (no upsells).
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Gsc\Admin as GscAdmin;
use Forwp\SeoHelper\Gsc\Indexing;
use Forwp\SeoHelper\Gsc\Module as GscModule;
use Forwp\SeoHelper\Gsc\PropertyResolver;
use Forwp\SeoHelper\SeoMeta\Registry as SeoMetaRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminBar {
	public const NODE_ID = 'forwp-seo-admin-bar';

	public static function boot(): void {
		add_action( 'admin_bar_menu', [ self::class, 'register' ], 95 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
	}

	public static function enqueue_assets(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'forwp-seo-admin-bar',
			FORWP_SEO_HELPER_URL . 'assets/css/admin-bar.css',
			[],
			FORWP_SEO_HELPER_VERSION
		);

		$post_id = self::current_post_id();
		if ( $post_id <= 0 || ! GscModule::get_instance()->is_enabled() || ! GscAdmin::get_instance()->is_connected() ) {
			return;
		}

		wp_enqueue_script(
			'forwp-seo-admin-bar-gsc',
			FORWP_SEO_HELPER_URL . 'assets/js/admin-bar-gsc.js',
			[],
			FORWP_SEO_HELPER_VERSION,
			true
		);

		$stored_link = '';
		$raw         = get_post_meta( $post_id, Indexing::META_LAST_STATUS, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$stored_link = esc_url_raw( (string) ( $decoded['inspectLink'] ?? '' ) );
			}
		}

		wp_localize_script(
			'forwp-seo-admin-bar-gsc',
			'forwpSeoAdminBarGsc',
			[
				'postId'      => $post_id,
				'restRoot'    => rest_url( 'forwp-seo/v1/' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'storedUrl'   => PropertyResolver::is_valid_inspection_result_link( $stored_link ) ? $stored_link : '',
				'propertyUrl' => PropertyResolver::search_console_property_url( GscAdmin::get_site_property() ),
				'i18n'        => [
					'opening' => __( 'Opening Search Console…', '4wp-seo-helper' ),
					'error'   => __( 'Search Console inspect failed. Open your property in GSC and paste the URL manually.', '4wp-seo-helper' ),
				],
			]
		);
	}

	public static function register( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$post_id = self::current_post_id();
		$seo     = $post_id > 0 ? self::seo_payload( $post_id ) : null;

		$wp_admin_bar->add_node(
			[
				'id'    => self::NODE_ID,
				'href'  => $post_id > 0 ? InventoryPage::url_for_post( $post_id ) : admin_url( 'admin.php?page=' . Menu::INVENTORY_PAGE_SLUG ),
				'title' => self::root_title( $seo ),
				'meta'  => [
					'class' => 'menupop forwp-seo-admin-bar-root',
					'title' => __( '4WP SEO — page status and actions', '4wp-seo-helper' ),
				],
			]
		);

		if ( $post_id > 0 && is_array( $seo ) ) {
			self::add_post_items( $wp_admin_bar, $post_id, $seo );
			return;
		}

		$wp_admin_bar->add_node(
			[
				'parent' => self::NODE_ID,
				'id'     => self::NODE_ID . '-inventory',
				'title'  => self::action_row( __( 'Open SEO Inventory', '4wp-seo-helper' ) ),
				'href'   => admin_url( 'admin.php?page=' . Menu::INVENTORY_PAGE_SLUG ),
			]
		);

		if ( GscModule::get_instance()->is_enabled() ) {
			$wp_admin_bar->add_node(
				[
					'parent' => self::NODE_ID,
					'id'     => self::NODE_ID . '-gsc-home',
					'title'  => self::action_row( __( 'Search Console', '4wp-seo-helper' ) ),
					'href'   => admin_url( 'admin.php?page=' . Menu::GSC_PAGE_SLUG ),
				]
			);
		}
	}

	/**
	 * @param array<string, mixed> $seo SEO payload.
	 */
	private static function add_post_items( \WP_Admin_Bar $wp_admin_bar, int $post_id, array $seo ): void {
		$title = get_the_title( $post_id );
		if ( '' === $title ) {
			$title = '#' . $post_id;
		}

		$wp_admin_bar->add_node(
			[
				'parent' => self::NODE_ID,
				'id'     => self::NODE_ID . '-heading',
				'title'  => '<span class="forwp-seo-ab-heading">' . esc_html( wp_html_excerpt( $title, 42, '…' ) ) . '</span>',
				'href'   => false,
				'meta'   => [
					'class' => 'forwp-seo-ab-node forwp-seo-ab-node--heading',
				],
			]
		);

		$seo_status = self::seo_status( $seo );
		$wp_admin_bar->add_node(
			[
				'parent' => self::NODE_ID,
				'id'     => self::NODE_ID . '-seo',
				'title'  => self::status_row(
					__( 'SEO score', '4wp-seo-helper' ),
					$seo_status['label'],
					$seo_status['tone']
				),
				'href'   => false,
				'meta'   => [
					'class' => 'forwp-seo-ab-node forwp-seo-ab-node--status',
					'title' => $seo_status['hint'],
				],
			]
		);

		$focus = self::focus_status( $seo );
		$wp_admin_bar->add_node(
			[
				'parent' => self::NODE_ID,
				'id'     => self::NODE_ID . '-focus',
				'title'  => self::status_row(
					__( 'Focus keyphrase', '4wp-seo-helper' ),
					$focus['label'],
					$focus['tone']
				),
				'href'   => false,
				'meta'   => [
					'class' => 'forwp-seo-ab-node forwp-seo-ab-node--status',
					'title' => $focus['hint'],
				],
			]
		);

		$indexed = self::index_status( $post_id );
		$wp_admin_bar->add_node(
			[
				'parent' => self::NODE_ID,
				'id'     => self::NODE_ID . '-indexed',
				'title'  => self::status_row(
					__( 'Google index', '4wp-seo-helper' ),
					$indexed['label'],
					$indexed['tone']
				),
				'href'   => false,
				'meta'   => [
					'class' => 'forwp-seo-ab-node forwp-seo-ab-node--status',
					'title' => $indexed['hint'],
				],
			]
		);

		$gsc = self::gsc_action( $post_id );
		if ( is_array( $gsc ) ) {
			$wp_admin_bar->add_node(
				[
					'parent' => self::NODE_ID,
					'id'     => self::NODE_ID . '-gsc',
					'title'  => self::action_row( __( 'Inspect in Search Console', '4wp-seo-helper' ) ),
					'href'   => $gsc['href'],
					'meta'   => [
						'class'  => 'forwp-seo-ab-node forwp-seo-ab-node--action forwp-seo-ab-node--actions-start forwp-seo-ab-gsc-link',
						'target' => '_blank',
						'rel'    => 'noopener noreferrer',
						'title'  => __( 'Open URL Inspection in Google Search Console', '4wp-seo-helper' ),
					],
				]
			);
		}

		$wp_admin_bar->add_node(
			[
				'parent' => self::NODE_ID,
				'id'     => self::NODE_ID . '-inventory',
				'title'  => self::action_row( __( 'Find in SEO Inventory', '4wp-seo-helper' ) ),
				'href'   => InventoryPage::url_for_post( $post_id ),
				'meta'   => [
					'class' => 'forwp-seo-ab-node forwp-seo-ab-node--action forwp-seo-ab-node--primary'
						. ( is_array( $gsc ) ? '' : ' forwp-seo-ab-node--actions-start' ),
					'title' => __( 'Open the full inventory and highlight this URL', '4wp-seo-helper' ),
				],
			]
		);

		$edit = get_edit_post_link( $post_id, 'raw' );
		if ( is_string( $edit ) && '' !== $edit ) {
			$wp_admin_bar->add_node(
				[
					'parent' => self::NODE_ID,
					'id'     => self::NODE_ID . '-edit',
					'title'  => self::action_row( __( 'Edit page', '4wp-seo-helper' ) ),
					'href'   => $edit,
					'meta'   => [
						'class' => 'forwp-seo-ab-node forwp-seo-ab-node--action',
					],
				]
			);
		}

		$wp_admin_bar->add_node(
			[
				'parent' => self::NODE_ID,
				'id'     => self::NODE_ID . '-all',
				'title'  => self::action_row( __( 'All URLs in Inventory', '4wp-seo-helper' ) ),
				'href'   => admin_url( 'admin.php?page=' . Menu::INVENTORY_PAGE_SLUG . '&view=inventory' ),
				'meta'   => [
					'class' => 'forwp-seo-ab-node forwp-seo-ab-node--action forwp-seo-ab-node--footer',
				],
			]
		);
	}

	/**
	 * @param array<string, mixed>|null $seo SEO payload.
	 */
	private static function root_title( ?array $seo ): string {
		$tone  = 'na';
		$label = __( '4WP SEO', '4wp-seo-helper' );

		if ( is_array( $seo ) ) {
			$status = self::seo_status( $seo );
			$tone   = $status['tone'];
			$label  = '4WP SEO';
		}

		return '<span class="ab-icon dashicons dashicons-chart-line" aria-hidden="true"></span>'
			. '<span class="forwp-seo-ab-root-text">' . esc_html( $label ) . '</span>'
			. '<span class="forwp-seo-ab-pill forwp-seo-ab-pill--' . esc_attr( $tone ) . '" aria-hidden="true"></span>'
			. '<span class="screen-reader-text">' . esc_html__( '4WP SEO page status', '4wp-seo-helper' ) . '</span>';
	}

	/**
	 * @param array<string, mixed> $seo SEO payload.
	 * @return array{label:string,tone:string,hint:string}
	 */
	private static function seo_status( array $seo ): array {
		if ( ! empty( $seo['noFocus'] ) ) {
			return [
				'label' => __( 'Needs focus', '4wp-seo-helper' ),
				'tone'  => 'na',
				'hint'  => __( 'Set a focus keyphrase to get an SEO score', '4wp-seo-helper' ),
			];
		}

		$score = $seo['seo'] ?? null;
		if ( null === $score || '' === $score ) {
			return [
				'label' => __( 'No score yet', '4wp-seo-helper' ),
				'tone'  => 'na',
				'hint'  => __( 'SEO score is not available for this page', '4wp-seo-helper' ),
			];
		}

		$n = (int) $score;
		if ( $n >= 71 ) {
			return [
				'label' => sprintf(
					/* translators: %d: SEO score 0–100 */
					__( 'Good (%d)', '4wp-seo-helper' ),
					$n
				),
				'tone'  => 'good',
				'hint'  => __( 'SEO score looks healthy', '4wp-seo-helper' ),
			];
		}
		if ( $n >= 41 ) {
			return [
				'label' => sprintf(
					/* translators: %d: SEO score 0–100 */
					__( 'OK (%d)', '4wp-seo-helper' ),
					$n
				),
				'tone'  => 'ok',
				'hint'  => __( 'SEO score could be improved', '4wp-seo-helper' ),
			];
		}

		return [
			'label' => sprintf(
				/* translators: %d: SEO score 0–100 */
				__( 'Needs work (%d)', '4wp-seo-helper' ),
				$n
			),
			'tone'  => 'bad',
			'hint'  => __( 'SEO score needs attention', '4wp-seo-helper' ),
		];
	}

	/**
	 * @param array<string, mixed> $seo SEO payload.
	 * @return array{label:string,tone:string,hint:string}
	 */
	private static function focus_status( array $seo ): array {
		$phrase = trim( (string) ( $seo['focus'] ?? '' ) );
		if ( '' === $phrase || ! empty( $seo['noFocus'] ) ) {
			return [
				'label' => __( 'Not set', '4wp-seo-helper' ),
				'tone'  => 'bad',
				'hint'  => __( 'No focus keyphrase on this page', '4wp-seo-helper' ),
			];
		}

		return [
			'label' => wp_html_excerpt( $phrase, 28, '…' ),
			'tone'  => 'good',
			'hint'  => $phrase,
		];
	}

	/**
	 * @return array{label:string,tone:string,hint:string}
	 */
	private static function index_status( int $post_id ): array {
		$fields   = Indexing::inventory_fields( $post_id );
		$coverage = (string) ( $fields['gsc_coverage'] ?? '' );
		$crawl    = (string) ( $fields['gsc_last_crawl'] ?? '' );

		if ( '' !== $coverage ) {
			$tone = self::gsc_tone( $coverage );
			return [
				'label' => wp_html_excerpt( $coverage, 28, '…' ),
				'tone'  => $tone,
				'hint'  => $coverage,
			];
		}

		if ( '' !== $crawl ) {
			$formatted = self::format_time( $crawl );
			return [
				'label' => $formatted,
				'tone'  => 'ok',
				'hint'  => sprintf(
					/* translators: %s: last crawl datetime */
					__( 'Google last crawled this URL on %s', '4wp-seo-helper' ),
					$formatted
				),
			];
		}

		$requested = (int) ( $fields['gsc_index_requested_at'] ?? 0 );
		if ( $requested > 0 ) {
			$formatted = wp_date( 'd.m.Y H:i', $requested );
			return [
				'label' => __( 'Requested', '4wp-seo-helper' ),
				'tone'  => 'ok',
				'hint'  => sprintf(
					/* translators: %s: datetime when indexing was requested */
					__( 'Indexing requested %s — waiting for crawl', '4wp-seo-helper' ),
					is_string( $formatted ) ? $formatted : ''
				),
			];
		}

		return [
			'label' => __( 'Not crawled yet', '4wp-seo-helper' ),
			'tone'  => 'na',
			'hint'  => __( 'No crawl or index request recorded for this URL', '4wp-seo-helper' ),
		];
	}

	/**
	 * @return array{href:string}|null
	 */
	private static function gsc_action( int $post_id ): ?array {
		if ( ! GscModule::get_instance()->is_enabled() || ! GscAdmin::get_instance()->is_connected() ) {
			return null;
		}

		$raw  = get_post_meta( $post_id, Indexing::META_LAST_STATUS, true );
		$link = '';
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$link = esc_url_raw( (string) ( $decoded['inspectLink'] ?? '' ) );
			}
		}

		$site = GscAdmin::get_site_property();
		$href = PropertyResolver::is_valid_inspection_result_link( $link )
			? $link
			: '#forwp-seo-gsc-inspect';
		if ( '#forwp-seo-gsc-inspect' === $href && '' === PropertyResolver::search_console_property_url( $site ) ) {
			$href = admin_url( 'admin.php?page=' . Menu::SETTINGS_PAGE_SLUG . '&tab=gsc' );
		}

		return [ 'href' => $href ];
	}

	private static function status_row( string $label, string $value, string $tone ): string {
		return '<span class="forwp-seo-ab-row forwp-seo-ab-row--status">'
			. '<span class="forwp-seo-ab-label">' . esc_html( $label ) . '</span>'
			. '<span class="forwp-seo-ab-badge forwp-seo-ab-badge--' . esc_attr( $tone ) . '">' . esc_html( $value ) . '</span>'
			. '</span>';
	}

	private static function action_row( string $label ): string {
		return '<span class="forwp-seo-ab-row forwp-seo-ab-row--action">'
			. '<span class="forwp-seo-ab-action-label">' . esc_html( $label ) . '</span>'
			. '</span>';
	}

	private static function format_time( string $iso ): string {
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return $iso;
		}

		$formatted = wp_date( 'd.m.Y H:i', $ts );
		return is_string( $formatted ) ? $formatted : $iso;
	}

	private static function gsc_tone( string $coverage ): string {
		if ( '' === $coverage ) {
			return 'na';
		}
		if ( preg_match( '/indexed/i', $coverage ) && ! preg_match( '/not indexed|excluded/i', $coverage ) ) {
			return 'good';
		}
		if ( preg_match( '/not indexed|excluded|error/i', $coverage ) ) {
			return 'bad';
		}

		return 'ok';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function seo_payload( int $post_id ): array {
		$adapter = SeoMetaRegistry::get_active();
		$scores  = $adapter->read_scores( $post_id );
		$meta    = $adapter->read( $post_id );

		return [
			'seo'     => $scores['seo'] ?? null,
			'noFocus' => (bool) ( $scores['no_focus'] ?? false ),
			'focus'   => (string) ( $meta['focus_keyword'] ?? '' ),
		];
	}

	private static function current_post_id(): int {
		if ( is_admin() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin bar identifies the post being edited.
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
			return $post_id > 0 ? $post_id : 0;
		}

		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}

		return 0;
	}
}
