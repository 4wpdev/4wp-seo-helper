<?php
/**
 * Cross posting platforms (taxonomy + term meta).
 */

namespace Forwp\SeoHelper\CrossPosting;

use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Platforms {
	public const TAXONOMY = 'forwp_platform';

	public const META_KEY     = 'forwp_platform_key';
	public const META_MODE    = 'forwp_platform_mode';
	public const META_FORMAT  = 'forwp_platform_format';
	public const META_ENABLED = 'forwp_platform_enabled';
	public const META_URL     = 'forwp_platform_url';
	public const META_ORDER   = 'forwp_platform_order';

	/**
	 * Where "open platform" goes when a platform has no URL of its own.
	 */
	private const DEFAULT_URLS = [
		'dev.to'       => 'https://dev.to/new',
		'x.com'        => 'https://x.com/compose/post',
		'linkedin.com' => 'https://www.linkedin.com/feed/',
		'daily.dev'    => 'https://app.daily.dev/',
		'medium.com'   => 'https://medium.com/new-story',
		'hashnode.com' => 'https://hashnode.com/draft',
		'reddit.com'   => 'https://www.reddit.com/submit',
		'facebook.com' => 'https://www.facebook.com/',
	];

	/**
	 * Platforms used for sharing right now.
	 *
	 * @var list<string>
	 */
	public const ACTIVE_KEYS = [
		'dev.to',
		'daily.dev',
		'hashnode.com',
		'x.com',
		'medium.com',
	];

	public const MODE_API    = 'api';
	public const MODE_MANUAL = 'manual';

	private const OPTION_SEEDED = 'forwp_seo_platforms_seeded';
	private const NONCE         = 'forwp_seo_platform_fields';
	private const ORDER_NONCE   = 'forwp_seo_platform_order';

	/**
	 * Guard so order backfill does not re-enter the terms query filter.
	 */
	private static $ensuring_order = false;

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'admin_init', [ $this, 'maybe_seed' ] );
		add_action( self::TAXONOMY . '_add_form_fields', [ $this, 'render_add_fields' ] );
		add_action( self::TAXONOMY . '_edit_form_fields', [ $this, 'render_edit_fields' ] );
		add_action( 'created_' . self::TAXONOMY, [ $this, 'save_fields' ] );
		add_action( 'edited_' . self::TAXONOMY, [ $this, 'save_fields' ] );
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', [ $this, 'columns' ] );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', [ $this, 'column_value' ], 10, 3 );
		add_filter( 'terms_clauses', [ $this, 'order_clauses' ], 10, 3 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_sort' ] );
		add_action( 'wp_ajax_forwp_seo_platform_order', [ $this, 'ajax_order' ] );
	}

	public function register(): void {
		/**
		 * Post types that can be cross-posted.
		 *
		 * @param list<string> $post_types Post types.
		 */
		$post_types = (array) apply_filters( 'forwp_seo_platform_post_types', [ 'post' ] );

		register_taxonomy(
			self::TAXONOMY,
			$post_types,
			[
				'labels'            => [
					'name'          => __( 'Platforms', '4wp-seo-helper' ),
					'singular_name' => __( 'Platform', '4wp-seo-helper' ),
					'add_new_item'  => __( 'Add platform', '4wp-seo-helper' ),
					'edit_item'     => __( 'Edit platform', '4wp-seo-helper' ),
					'search_items'  => __( 'Search platforms', '4wp-seo-helper' ),
					'not_found'     => __( 'No platforms found.', '4wp-seo-helper' ),
				],
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_nav_menus' => false,
				'show_in_rest'      => false,
				'show_tagcloud'     => false,
				'show_admin_column' => false,
				'meta_box_cb'       => false,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => [
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_posts',
				],
			]
		);
	}

	/**
	 * Admin URL of the platforms list.
	 */
	public static function admin_url(): string {
		return admin_url( 'edit-tags.php?taxonomy=' . self::TAXONOMY );
	}

	/**
	 * Configured platforms.
	 *
	 * @return list<array{term_id: int, key: string, label: string, mode: string, format: string, enabled: bool, url: string}>
	 */
	public static function all( bool $enabled_only = true ): array {
		self::ensure_order();
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'meta_key'   => self::META_ORDER,
				'orderby'    => 'meta_value_num',
				'order'      => 'ASC',
			]
		);
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$out = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$row = self::from_term( $term );
			if ( '' === $row['key'] || ( $enabled_only && ! $row['enabled'] ) ) {
				continue;
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * @return array{term_id: int, key: string, label: string, mode: string, format: string, enabled: bool, url: string}
	 */
	private static function from_term( WP_Term $term ): array {
		$mode = (string) get_term_meta( $term->term_id, self::META_MODE, true );
		$key  = (string) get_term_meta( $term->term_id, self::META_KEY, true );
		$url  = (string) get_term_meta( $term->term_id, self::META_URL, true );

		return [
			'term_id' => (int) $term->term_id,
			'key'     => $key,
			'url'     => '' !== $url ? $url : ( self::DEFAULT_URLS[ $key ] ?? '' ),
			'label'   => (string) $term->name,
			'mode'    => self::MODE_API === $mode ? self::MODE_API : self::MODE_MANUAL,
			'format'  => (string) get_term_meta( $term->term_id, self::META_FORMAT, true ),
			'enabled' => '0' !== (string) get_term_meta( $term->term_id, self::META_ENABLED, true ),
		];
	}

	/**
	 * Create the default platform list once, when cross posting is enabled.
	 */
	public function maybe_seed(): void {
		if ( get_option( self::OPTION_SEEDED ) || ! Module::get_instance()->is_enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$defaults = [
			[ 'dev.to', 'DEV.to', self::MODE_API, 'article-modified' ],
			[ 'x.com', 'X.com', self::MODE_MANUAL, 'short-video+text+tags' ],
			[ 'linkedin.com', 'LinkedIn', self::MODE_MANUAL, 'short-post' ],
			[ 'daily.dev', 'daily.dev', self::MODE_MANUAL, 'article-full' ],
			[ 'medium.com', 'Medium', self::MODE_MANUAL, '' ],
			[ 'hashnode.com', 'Hashnode', self::MODE_MANUAL, '' ],
			[ 'reddit.com', 'Reddit', self::MODE_MANUAL, '' ],
			[ 'facebook.com', 'Facebook', self::MODE_MANUAL, '' ],
		];

		foreach ( $defaults as $index => [ $key, $label, $mode, $format ] ) {
			if ( term_exists( $label, self::TAXONOMY ) ) {
				continue;
			}
			$term = wp_insert_term( $label, self::TAXONOMY );
			if ( is_wp_error( $term ) ) {
				continue;
			}
			$term_id = (int) $term['term_id'];
			update_term_meta( $term_id, self::META_KEY, $key );
			update_term_meta( $term_id, self::META_MODE, $mode );
			update_term_meta( $term_id, self::META_FORMAT, $format );
			update_term_meta( $term_id, self::META_ENABLED, '1' );
			update_term_meta( $term_id, self::META_ORDER, $index );
		}

		update_option( self::OPTION_SEEDED, '1', false );
	}

	public function render_add_fields(): void {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<div class="form-field form-required">
			<label for="forwp-platform-key"><?php esc_html_e( 'Key', '4wp-seo-helper' ); ?></label>
			<input type="text" id="forwp-platform-key" name="forwp_platform_key" required />
			<p><?php esc_html_e( 'Same key as in meta.json "platforms", e.g. dev.to.', '4wp-seo-helper' ); ?></p>
		</div>
		<div class="form-field">
			<label for="forwp-platform-mode"><?php esc_html_e( 'Mode', '4wp-seo-helper' ); ?></label>
			<?php self::render_mode_select( self::MODE_MANUAL ); ?>
		</div>
		<div class="form-field">
			<label for="forwp-platform-format"><?php esc_html_e( 'Format', '4wp-seo-helper' ); ?></label>
			<input type="text" id="forwp-platform-format" name="forwp_platform_format" />
			<p><?php esc_html_e( 'Default content format, e.g. short-post or article-full.', '4wp-seo-helper' ); ?></p>
		</div>
		<div class="form-field">
			<label for="forwp-platform-url"><?php esc_html_e( 'Open URL', '4wp-seo-helper' ); ?></label>
			<input type="url" id="forwp-platform-url" name="forwp_platform_url" />
			<p><?php esc_html_e( 'Where the editor panel link goes, e.g. the "new post" page.', '4wp-seo-helper' ); ?></p>
		</div>
		<div class="form-field">
			<label><input type="checkbox" name="forwp_platform_enabled" value="1" checked /> <?php esc_html_e( 'Enabled', '4wp-seo-helper' ); ?></label>
		</div>
		<?php
	}

	public function render_edit_fields( WP_Term $term ): void {
		$row = self::from_term( $term );
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<tr class="form-field form-required">
			<th scope="row"><label for="forwp-platform-key"><?php esc_html_e( 'Key', '4wp-seo-helper' ); ?></label></th>
			<td>
				<input type="text" id="forwp-platform-key" name="forwp_platform_key" value="<?php echo esc_attr( $row['key'] ); ?>" required />
				<p class="description"><?php esc_html_e( 'Same key as in meta.json "platforms", e.g. dev.to.', '4wp-seo-helper' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="forwp-platform-mode"><?php esc_html_e( 'Mode', '4wp-seo-helper' ); ?></label></th>
			<td><?php self::render_mode_select( $row['mode'] ); ?></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="forwp-platform-format"><?php esc_html_e( 'Format', '4wp-seo-helper' ); ?></label></th>
			<td><input type="text" id="forwp-platform-format" name="forwp_platform_format" value="<?php echo esc_attr( $row['format'] ); ?>" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="forwp-platform-url"><?php esc_html_e( 'Open URL', '4wp-seo-helper' ); ?></label></th>
			<td>
				<input type="url" id="forwp-platform-url" name="forwp_platform_url" value="<?php echo esc_attr( (string) get_term_meta( $term->term_id, self::META_URL, true ) ); ?>" placeholder="<?php echo esc_attr( self::DEFAULT_URLS[ $row['key'] ] ?? '' ); ?>" />
				<p class="description"><?php esc_html_e( 'Where the editor panel link goes. Empty = default for this key.', '4wp-seo-helper' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Enabled', '4wp-seo-helper' ); ?></th>
			<td><label><input type="checkbox" name="forwp_platform_enabled" value="1" <?php checked( $row['enabled'] ); ?> /> <?php esc_html_e( 'Show in the editor panel', '4wp-seo-helper' ); ?></label></td>
		</tr>
		<?php
	}

	private static function render_mode_select( string $current ): void {
		?>
		<select id="forwp-platform-mode" name="forwp_platform_mode">
			<option value="<?php echo esc_attr( self::MODE_MANUAL ); ?>" <?php selected( $current, self::MODE_MANUAL ); ?>><?php esc_html_e( 'Manual (Chrome extension)', '4wp-seo-helper' ); ?></option>
			<option value="<?php echo esc_attr( self::MODE_API ); ?>" <?php selected( $current, self::MODE_API ); ?>><?php esc_html_e( 'API', '4wp-seo-helper' ); ?></option>
		</select>
		<?php
	}

	public function save_fields( int $term_id ): void {
		$nonce = isset( $_POST[ self::NONCE . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key  = isset( $_POST['forwp_platform_key'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['forwp_platform_key'] ) ) ) : '';
		$mode = isset( $_POST['forwp_platform_mode'] ) ? sanitize_key( wp_unslash( $_POST['forwp_platform_mode'] ) ) : self::MODE_MANUAL;

		update_term_meta( $term_id, self::META_KEY, $key );
		update_term_meta( $term_id, self::META_MODE, self::MODE_API === $mode ? self::MODE_API : self::MODE_MANUAL );
		update_term_meta(
			$term_id,
			self::META_FORMAT,
			isset( $_POST['forwp_platform_format'] ) ? sanitize_text_field( wp_unslash( $_POST['forwp_platform_format'] ) ) : ''
		);
		update_term_meta( $term_id, self::META_ENABLED, isset( $_POST['forwp_platform_enabled'] ) ? '1' : '0' );
		update_term_meta(
			$term_id,
			self::META_URL,
			isset( $_POST['forwp_platform_url'] ) ? esc_url_raw( wp_unslash( $_POST['forwp_platform_url'] ) ) : ''
		);
		if ( '' === (string) get_term_meta( $term_id, self::META_ORDER, true ) ) {
			update_term_meta( $term_id, self::META_ORDER, self::next_order() );
		}
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		unset( $columns['description'], $columns['slug'], $columns['posts'] );

		$ordered = [];
		if ( isset( $columns['cb'] ) ) {
			$ordered['cb'] = $columns['cb'];
		}
		$ordered['forwp_platform_order'] = '<span class="screen-reader-text">' . esc_html__( 'Order', '4wp-seo-helper' ) . '</span>';
		if ( isset( $columns['name'] ) ) {
			$ordered['name'] = $columns['name'];
		}
		$ordered['forwp_platform_key']     = __( 'Key', '4wp-seo-helper' );
		$ordered['forwp_platform_mode']    = __( 'Mode', '4wp-seo-helper' );
		$ordered['forwp_platform_format']  = __( 'Format', '4wp-seo-helper' );
		$ordered['forwp_platform_enabled'] = __( 'Enabled', '4wp-seo-helper' );

		return $ordered;
	}

	/**
	 * @param string $content Column content.
	 * @param string $column  Column name.
	 * @param int    $term_id Term id.
	 */
	public function column_value( $content, $column, $term_id ): string {
		$term = get_term( (int) $term_id, self::TAXONOMY );
		if ( ! $term instanceof WP_Term ) {
			return (string) $content;
		}
		$row = self::from_term( $term );

		switch ( $column ) {
			case 'forwp_platform_order':
				return '<button type="button" class="forwp-platform-drag dashicons dashicons-menu" aria-label="' . esc_attr__( 'Drag to reorder', '4wp-seo-helper' ) . '"></button>';
			case 'forwp_platform_key':
				return '<code>' . esc_html( $row['key'] ) . '</code>';
			case 'forwp_platform_mode':
				return esc_html( self::MODE_API === $row['mode'] ? __( 'API', '4wp-seo-helper' ) : __( 'Manual', '4wp-seo-helper' ) );
			case 'forwp_platform_format':
				return esc_html( $row['format'] );
			case 'forwp_platform_enabled':
				return $row['enabled'] ? '✓' : '—';
		}

		return (string) $content;
	}

	/**
	 * Default list order is the dragged order. A column sort still wins.
	 *
	 * Meta is joined here because get_terms_args runs after WordPress has already built the meta query.
	 *
	 * @param array<string, string> $clauses    SQL clauses.
	 * @param list<string>|string   $taxonomies Taxonomies.
	 * @param array<string, mixed>  $args       Term query args.
	 * @return array<string, string>
	 */
	public function order_clauses( array $clauses, $taxonomies, array $args ): array {
		if ( self::$ensuring_order || ! in_array( self::TAXONOMY, (array) $taxonomies, true ) || ! empty( $_REQUEST['orderby'] ) ) {
			return $clauses;
		}
		if ( isset( $args['meta_key'] ) && self::META_ORDER === $args['meta_key'] ) {
			return $clauses;
		}

		global $wpdb;
		$clauses['join']   .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->termmeta} AS forwp_platform_order ON (t.term_id = forwp_platform_order.term_id AND forwp_platform_order.meta_key = %s)",
			self::META_ORDER
		);
		$clauses['orderby'] = 'ORDER BY CAST(forwp_platform_order.meta_value AS UNSIGNED)';
		$clauses['order']   = 'ASC';

		return $clauses;
	}

	/**
	 * Drag handle and sortable list on the platforms screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_sort( string $hook ): void {
		if ( 'edit-tags.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::TAXONOMY !== $screen->taxonomy ) {
			return;
		}

		self::ensure_order();
		wp_enqueue_script(
			'forwp-seo-platform-order',
			FORWP_SEO_HELPER_URL . 'assets/js/admin-platforms.js',
			[],
			(string) filemtime( FORWP_SEO_HELPER_PATH . 'assets/js/admin-platforms.js' ),
			true
		);

		$per_page = (int) get_user_option( 'edit_' . self::TAXONOMY . '_per_page' );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );

		wp_localize_script(
			'forwp-seo-platform-order',
			'forwpSeoPlatformOrder',
			[
				'nonce' => wp_create_nonce( self::ORDER_NONCE ),
				'start' => ( $paged - 1 ) * $per_page,
				'on'    => empty( $_GET['orderby'] ) && empty( $_GET['s'] ),
			]
		);
		wp_enqueue_style( 'dashicons' );
		wp_add_inline_style(
			'dashicons',
			'.forwp-platform-drag{cursor:grab;color:#787c82;background:none;border:0;padding:0;line-height:1}.forwp-platform-drag:active{cursor:grabbing}#the-list tr.ui-sortable-helper{background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.12)}.column-forwp_platform_order{width:2.2em}'
		);
	}

	/**
	 * Save the row order from the platforms list.
	 */
	public function ajax_order(): void {
		check_ajax_referer( self::ORDER_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$ids   = isset( $_POST['order'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order'] ) ) : [];
		$start = isset( $_POST['start'] ) ? absint( $_POST['start'] ) : 0;
		$index = $start;
		foreach ( $ids as $term_id ) {
			if ( $term_id < 1 ) {
				continue;
			}
			$term = get_term( $term_id, self::TAXONOMY );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			update_term_meta( $term_id, self::META_ORDER, $index );
			++$index;
		}

		wp_send_json_success();
	}

	/**
	 * Give every platform an order number so the list query can sort on it.
	 */
	private static function ensure_order(): void {
		if ( self::$ensuring_order ) {
			return;
		}
		self::$ensuring_order = true;

		$missing = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'meta_query' => [
					[
						'key'     => self::META_ORDER,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		if ( is_array( $missing ) ) {
			$next = self::next_order();
			foreach ( $missing as $term ) {
				if ( $term instanceof WP_Term ) {
					update_term_meta( $term->term_id, self::META_ORDER, $next );
					++$next;
				}
			}
		}

		self::$ensuring_order = false;
	}

	/**
	 * Next free order slot.
	 */
	private static function next_order(): int {
		self::$ensuring_order = true;
		$terms                = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'meta_key'   => self::META_ORDER,
				'orderby'    => 'meta_value_num',
				'order'      => 'DESC',
				'number'     => 1,
			]
		);
		self::$ensuring_order = false;

		if ( ! is_array( $terms ) || ! isset( $terms[0] ) || ! $terms[0] instanceof WP_Term ) {
			return 0;
		}

		return (int) get_term_meta( $terms[0]->term_id, self::META_ORDER, true ) + 1;
	}
}
