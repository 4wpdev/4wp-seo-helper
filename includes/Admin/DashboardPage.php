<?php
/**
 * Dashboard: 4WP SEO shell — inventory + Search Console snapshot.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Gsc\Module as GscModule;
use Forwp\SeoHelper\Gsc\ReportPeriod;
use Forwp\SeoHelper\Inventory\DashboardStats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DashboardPage {
	/** @var array<string, mixed>|null */
	private static ?array $stats = null;

	public static function on_load(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		ReportPeriod::maybe_save_from_request();

		wp_enqueue_script( 'dashboard' );
		wp_enqueue_style( 'dashboard' );
		if ( wp_is_mobile() ) {
			wp_enqueue_script( 'jquery-touch-punch' );
		}

		wp_enqueue_style(
			'forwp-seo-admin-settings',
			FORWP_SEO_HELPER_URL . 'assets/css/admin-settings.css',
			[],
			FORWP_SEO_HELPER_VERSION
		);
		wp_enqueue_style(
			'forwp-seo-admin-dashboard',
			FORWP_SEO_HELPER_URL . 'assets/css/admin-dashboard.css',
			[ 'dashboard', 'forwp-seo-admin-settings' ],
			FORWP_SEO_HELPER_VERSION
		);
		wp_enqueue_script(
			'forwp-seo-admin-tabs',
			FORWP_SEO_HELPER_URL . 'assets/js/admin-tabs.js',
			[],
			FORWP_SEO_HELPER_VERSION,
			true
		);

		add_filter( 'admin_body_class', [ self::class, 'body_class' ] );
		add_filter( 'screen_settings', [ self::class, 'filter_screen_settings' ], 10, 2 );

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		add_meta_box(
			'forwp_seo_dash_metrics',
			__( 'Key metrics', '4wp-seo-helper' ),
			[ self::class, 'render_metrics_box' ],
			$screen->id,
			'normal',
			'high'
		);
		add_meta_box(
			'forwp_seo_dash_weakest',
			__( 'Weakest pages', '4wp-seo-helper' ),
			[ self::class, 'render_weakest_box' ],
			$screen->id,
			'normal',
			'default'
		);
		add_meta_box(
			'forwp_seo_dash_queries',
			__( 'Top queries', '4wp-seo-helper' ),
			[ self::class, 'render_queries_box' ],
			$screen->id,
			'side',
			'high'
		);
		add_meta_box(
			'forwp_seo_dash_search',
			__( 'Search & growth', '4wp-seo-helper' ),
			[ self::class, 'render_search_box' ],
			$screen->id,
			'side',
			'default'
		);
		add_meta_box(
			'forwp_seo_dash_gaps',
			__( 'SEO gaps by field', '4wp-seo-helper' ),
			[ self::class, 'render_gaps_box' ],
			$screen->id,
			'column3',
			'default'
		);
	}

	/**
	 * @param string $classes Body classes.
	 */
	public static function body_class( string $classes ): string {
		return $classes . ' forwp-seo-dashboard-page';
	}

	/**
	 * @param string     $settings Existing extra settings HTML.
	 * @param \WP_Screen $screen   Current screen.
	 */
	public static function filter_screen_settings( $settings, $screen ): string {
		if ( ! $screen instanceof \WP_Screen || 'toplevel_page_' . Menu::PAGE_SLUG !== $screen->id ) {
			return (string) $settings;
		}

		$columns = self::layout_columns( $screen );

		ob_start();
		?>
		<fieldset class="columns-prefs forwp-seo-columns-prefs">
			<legend class="screen-layout"><?php esc_html_e( 'Layout', '4wp-seo-helper' ); ?></legend>
			<label class="columns-prefs-2">
				<input type="radio" name="screen_columns" value="2" <?php checked( $columns, 2 ); ?> />
				<?php esc_html_e( '2 columns', '4wp-seo-helper' ); ?>
			</label>
			<label class="columns-prefs-3">
				<input type="radio" name="screen_columns" value="3" <?php checked( $columns, 3 ); ?> />
				<?php esc_html_e( '3 columns', '4wp-seo-helper' ); ?>
			</label>
			<label class="columns-prefs-4">
				<input type="radio" name="screen_columns" value="4" <?php checked( $columns, 4 ); ?> />
				<?php esc_html_e( '33 / 66', '4wp-seo-helper' ); ?>
			</label>
		</fieldset>
		<?php

		return (string) $settings . ob_get_clean();
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		ReportPeriod::maybe_save_from_request();

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$stats   = self::stats();
		$columns = self::layout_columns( $screen );

		$heading_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none" focusable="false" aria-hidden="true"><path d="M3 3v18h18" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="m7 14 4-4 3 3 5-6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		?>
		<div class="wrap forwp-seo-admin-shell forwp-seo-dash">
			<header class="forwp-seo-dash__header">
				<div class="forwp-seo-dash__header-main">
					<h1 class="forwp-seo-admin-heading">
						<span class="forwp-seo-admin-heading__icon" aria-hidden="true">
							<?php echo wp_kses( $heading_svg, Page::svg_allowed_html() ); ?>
						</span>
						<span class="forwp-seo-admin-heading__text"><?php esc_html_e( 'Dashboard', '4wp-seo-helper' ); ?></span>
					</h1>
					<p class="forwp-seo-admin-lead forwp-seo-dash__lead">
						<?php esc_html_e( 'Key SEO health metrics from Inventory and Search Console.', '4wp-seo-helper' ); ?>
					</p>
				</div>
				<div class="forwp-seo-dash__header-meta">
					<?php self::render_period_select(); ?>
					<nav class="forwp-seo-dash__links" aria-label="<?php esc_attr_e( 'Dashboard shortcuts', '4wp-seo-helper' ); ?>">
						<a href="<?php echo esc_url( (string) $stats['inventory_url'] ); ?>"><?php esc_html_e( 'Inventory', '4wp-seo-helper' ); ?></a>
						<?php if ( GscModule::get_instance()->is_enabled() ) : ?>
							<a href="<?php echo esc_url( (string) $stats['gsc_url'] ); ?>"><?php esc_html_e( 'Search Console', '4wp-seo-helper' ); ?></a>
						<?php endif; ?>
						<a href="<?php echo esc_url( (string) $stats['settings_url'] ); ?>"><?php esc_html_e( 'Settings', '4wp-seo-helper' ); ?></a>
					</nav>
				</div>
			</header>

			<div id="dashboard-widgets-wrap">
				<div id="dashboard-widgets" class="metabox-holder columns-<?php echo esc_attr( (string) $columns ); ?>">
					<div id="postbox-container-1" class="postbox-container">
						<?php do_meta_boxes( $screen->id, 'normal', '' ); ?>
					</div>
					<div id="postbox-container-2" class="postbox-container">
						<?php do_meta_boxes( $screen->id, 'side', '' ); ?>
					</div>
					<div id="postbox-container-3" class="postbox-container">
						<?php do_meta_boxes( $screen->id, 'column3', '' ); ?>
					</div>
					<div id="postbox-container-4" class="postbox-container">
						<?php do_meta_boxes( $screen->id, 'column4', '' ); ?>
					</div>
				</div>
				<?php wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false ); ?>
				<?php wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false ); ?>
			</div>
		</div>
		<?php
	}

	public static function render_metrics_box(): void {
		$stats  = self::stats();
		$gsc    = is_array( $stats['gsc'] ?? null ) ? $stats['gsc'] : [];
		$growth = is_array( $stats['growth'] ?? null ) ? $stats['growth'] : [];
		?>
		<div class="forwp-seo-metric-grid">
			<?php
			self::render_metric_card(
				number_format_i18n( (int) $stats['posts'] ),
				__( 'Inventory URLs', '4wp-seo-helper' ),
				(array) ( $stats['delta']['posts'] ?? [] ),
				'',
				(string) $stats['inventory_url']
			);
			self::render_metric_card(
				number_format_i18n( (int) $stats['weak'] ),
				__( 'Weak pages', '4wp-seo-helper' ),
				(array) ( $stats['delta']['weak'] ?? [] ),
				(int) $stats['weak'] > 0 ? 'is-alert' : 'is-ok',
				(string) $stats['weak_url']
			);
			self::render_metric_card(
				(int) $stats['avg'] . '%',
				__( 'Average SEO score', '4wp-seo-helper' ),
				(array) ( $stats['delta']['avg'] ?? [] )
			);
			self::render_metric_card(
				number_format_i18n( (int) $stats['gaps'] ),
				__( 'Pages with gaps', '4wp-seo-helper' ),
				(array) ( $stats['delta']['gaps'] ?? [] ),
				(int) $stats['gaps'] > 0 ? 'is-warn' : '',
				(string) $stats['gaps_url']
			);
			self::render_metric_card(
				number_format_i18n( (int) ( $growth['week'] ?? 0 ) ),
				__( 'New pages this week', '4wp-seo-helper' ),
				(array) ( $growth['week_delta'] ?? [] ),
				(int) ( $growth['week'] ?? 0 ) > 0 ? 'is-up' : ''
			);
			if ( ! empty( $gsc['enabled'] ) && ! empty( $gsc['connected'] ) ) {
				self::render_metric_card(
					number_format_i18n( (int) ( $gsc['clicks'] ?? 0 ) ),
					__( 'Search clicks', '4wp-seo-helper' ),
					is_array( $gsc['change']['clicks'] ?? null ) ? (array) $gsc['change']['clicks'] : [],
					'',
					(string) $stats['gsc_url']
				);
				self::render_metric_card(
					number_format_i18n( (int) ( $gsc['impressions'] ?? 0 ) ),
					__( 'Search impressions', '4wp-seo-helper' ),
					is_array( $gsc['change']['impressions'] ?? null ) ? (array) $gsc['change']['impressions'] : [],
					'',
					(string) $stats['gsc_url']
				);
			}
			?>
		</div>
		<?php
	}

	public static function render_weakest_box(): void {
		$stats   = self::stats();
		$weakest = is_array( $stats['weakest'] ?? null ) ? $stats['weakest'] : [];
		self::render_weakest_pane( $weakest, (string) $stats['weak_url'] );
	}

	public static function render_queries_box(): void {
		$stats = self::stats();
		$gsc   = is_array( $stats['gsc'] ?? null ) ? $stats['gsc'] : [];
		self::render_queries_pane( $gsc, (string) $stats['gsc_url'], (string) $stats['settings_url'] );
	}

	public static function render_search_box(): void {
		$stats  = self::stats();
		$gsc    = is_array( $stats['gsc'] ?? null ) ? $stats['gsc'] : [];
		$growth = is_array( $stats['growth'] ?? null ) ? $stats['growth'] : [];
		$types  = is_array( $stats['types'] ?? null ) ? $stats['types'] : [];
		self::render_search_summary_pane( $gsc, $growth, $types, (string) $stats['gsc_url'], (string) $stats['settings_url'] );
	}

	public static function render_gaps_box(): void {
		$stats   = self::stats();
		$missing = is_array( $stats['missing'] ?? null ) ? $stats['missing'] : [];
		self::render_gaps_pane( $missing, (string) $stats['gaps_url'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function stats(): array {
		if ( null === self::$stats ) {
			self::$stats = DashboardStats::collect();
		}

		return self::$stats;
	}

	private static function layout_columns( \WP_Screen $screen ): int {
		$columns = (int) get_user_option( 'screen_layout_' . $screen->id );
		if ( $columns < 2 || $columns > 4 ) {
			return 2;
		}

		return $columns;
	}


	private static function render_period_select(): void {
		if ( ! GscModule::get_instance()->is_enabled() ) {
			return;
		}

		$days = ReportPeriod::resolve_from_request();
		?>
		<form method="get" class="forwp-seo-dash__period-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_SLUG ); ?>" />
			<?php wp_nonce_field( ReportPeriod::RANGE_NONCE_ACTION, 'forwp_seo_gsc_range_nonce', false ); ?>
			<label class="screen-reader-text" for="forwp-seo-dash-range"><?php esc_html_e( 'Date range', '4wp-seo-helper' ); ?></label>
			<select id="forwp-seo-dash-range" name="range" class="forwp-seo-dash__period-select forwp-seo-range-select">
				<?php foreach ( ReportPeriod::allowed_ranges() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $days, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</form>
		<?php
	}

	/**
	 * @param callable():void $body Pane body renderer.
	 */
	private static function render_pane( string $title, string $desc, callable $body ): void {
		?>
		<section class="forwp-seo-pane">
			<header class="forwp-seo-pane__header">
				<h2 class="forwp-seo-pane__title"><?php echo esc_html( $title ); ?></h2>
				<p class="forwp-seo-pane__desc"><?php echo esc_html( $desc ); ?></p>
			</header>
			<div class="forwp-seo-pane__body">
				<?php $body(); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * @param list<array<string, mixed>> $weakest Weakest inventory rows.
	 */
	private static function render_weakest_pane( array $weakest, string $weak_url ): void {
		if ( [] === $weakest ) {
			echo '<p class="forwp-seo-pane__empty">' . esc_html__( 'No inventory URLs yet.', '4wp-seo-helper' ) . '</p>';
			return;
		}
		?>
		<ul class="forwp-seo-pane-list">
			<?php foreach ( $weakest as $row ) : ?>
				<?php
				$score   = (int) ( $row['score'] ?? 0 );
				$is_weak = $score < DashboardStats::WEAK_SCORE;
				$gaps    = is_array( $row['missing'] ?? null ) ? $row['missing'] : [];
				$post_id = (int) ( $row['post_id'] ?? 0 );
				?>
				<li class="forwp-seo-pane-list__item<?php echo $is_weak ? ' is-weak' : ''; ?>">
					<a class="forwp-seo-pane-list__label" href="<?php echo esc_url( InventoryPage::url_for_post( $post_id ) ); ?>">
						<?php echo esc_html( (string) ( $row['title'] ?? '#' . $post_id ) ); ?>
					</a>
					<span class="forwp-seo-pane-list__meta">
						<span class="forwp-seo-dash-score<?php echo $is_weak ? ' is-weak' : ''; ?>">
							<?php echo esc_html( (string) $score ); ?>%
						</span>
						<?php if ( [] !== $gaps ) : ?>
							<span class="forwp-seo-pane-list__sub"><?php echo esc_html( implode( ', ', $gaps ) ); ?></span>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="forwp-seo-pane__footer">
			<a href="<?php echo esc_url( $weak_url ); ?>"><?php esc_html_e( 'Open all weak pages', '4wp-seo-helper' ); ?></a>
		</p>
		<?php
	}

	/**
	 * @param array<string, mixed> $gsc GSC payload from DashboardStats.
	 */
	private static function render_queries_pane( array $gsc, string $gsc_url, string $settings_url ): void {
		$queries = is_array( $gsc['top_queries'] ?? null ) ? $gsc['top_queries'] : [];

		if ( empty( $gsc['enabled'] ) ) {
			echo '<p class="forwp-seo-pane__empty">';
			esc_html_e( 'Search Console is turned off.', '4wp-seo-helper' );
			echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', '4wp-seo-helper' ) . '</a>';
			echo '</p>';
			return;
		}

		if ( empty( $gsc['connected'] ) ) {
			echo '<p class="forwp-seo-pane__empty">';
			esc_html_e( 'Connect Google and pick a property to load queries.', '4wp-seo-helper' );
			echo ' <a href="' . esc_url( add_query_arg( 'tab', 'gsc', $settings_url ) ) . '">' . esc_html__( 'GSC settings', '4wp-seo-helper' ) . '</a>';
			echo '</p>';
			return;
		}

		if ( ! empty( $gsc['needs_sync'] ) || [] === $queries ) {
			echo '<p class="forwp-seo-pane__empty">';
			esc_html_e( 'No synced query data for this period yet.', '4wp-seo-helper' );
			echo ' <a href="' . esc_url( $gsc_url ) . '">' . esc_html__( 'Open Search Console', '4wp-seo-helper' ) . '</a>';
			echo '</p>';
			return;
		}
		?>
		<ul class="forwp-seo-pane-list">
			<?php foreach ( $queries as $row ) : ?>
				<li class="forwp-seo-pane-list__item">
					<span class="forwp-seo-pane-list__label" title="<?php echo esc_attr( (string) ( $row['dim_key'] ?? '' ) ); ?>">
						<?php echo esc_html( wp_html_excerpt( (string) ( $row['dim_key'] ?? '' ), 48, '…' ) ); ?>
					</span>
					<span class="forwp-seo-pane-list__meta">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: clicks, 2: impressions */
								__( '%1$s clk · %2$s impr', '4wp-seo-helper' ),
								number_format_i18n( (int) ( $row['clicks'] ?? 0 ) ),
								number_format_i18n( (int) ( $row['impressions'] ?? 0 ) )
							)
						);
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="forwp-seo-pane__footer">
			<a href="<?php echo esc_url( $gsc_url ); ?>"><?php esc_html_e( 'View Search Console', '4wp-seo-helper' ); ?></a>
		</p>
		<?php
	}

	/**
	 * @param array<string, mixed>                $gsc    GSC payload.
	 * @param array<string, mixed>                $growth Growth stats.
	 * @param array<string, array<string, mixed>> $types  By post type.
	 */
	private static function render_search_summary_pane( array $gsc, array $growth, array $types, string $gsc_url, string $settings_url ): void {
		$pages = is_array( $gsc['top_pages'] ?? null ) ? $gsc['top_pages'] : [];

		if ( ! empty( $gsc['enabled'] ) && ! empty( $gsc['connected'] ) && empty( $gsc['needs_sync'] ) ) {
			?>
			<ul class="forwp-seo-pane-stats">
				<li>
					<span class="forwp-seo-pane-stats__label"><?php esc_html_e( 'Clicks', '4wp-seo-helper' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (int) ( $gsc['clicks'] ?? 0 ) ) ); ?></strong>
					<?php echo self::delta_pill_html( is_array( $gsc['change']['clicks'] ?? null ) ? (array) $gsc['change']['clicks'] : [] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</li>
				<li>
					<span class="forwp-seo-pane-stats__label"><?php esc_html_e( 'Impressions', '4wp-seo-helper' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (int) ( $gsc['impressions'] ?? 0 ) ) ); ?></strong>
					<?php echo self::delta_pill_html( is_array( $gsc['change']['impressions'] ?? null ) ? (array) $gsc['change']['impressions'] : [] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</li>
				<?php if ( (float) ( $gsc['position'] ?? 0 ) > 0 ) : ?>
					<li>
						<span class="forwp-seo-pane-stats__label"><?php esc_html_e( 'Avg. position', '4wp-seo-helper' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (float) $gsc['position'], 1 ) ); ?></strong>
					</li>
				<?php endif; ?>
			</ul>
			<?php if ( [] !== $pages ) : ?>
				<h3 class="forwp-seo-pane__subtitle"><?php esc_html_e( 'Top pages', '4wp-seo-helper' ); ?></h3>
				<ul class="forwp-seo-pane-list forwp-seo-pane-list--compact">
					<?php foreach ( array_slice( $pages, 0, 5 ) as $row ) : ?>
						<li class="forwp-seo-pane-list__item">
							<span class="forwp-seo-pane-list__label" title="<?php echo esc_attr( (string) ( $row['dim_key'] ?? '' ) ); ?>">
								<?php echo esc_html( wp_html_excerpt( (string) ( $row['dim_key'] ?? '' ), 40, '…' ) ); ?>
							</span>
							<span class="forwp-seo-pane-list__meta">
								<?php echo esc_html( number_format_i18n( (int) ( $row['clicks'] ?? 0 ) ) ); ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php
		} elseif ( ! empty( $gsc['enabled'] ) && empty( $gsc['connected'] ) ) {
			echo '<p class="forwp-seo-pane__empty">';
			esc_html_e( 'Search Console is not ready yet.', '4wp-seo-helper' );
			echo ' <a href="' . esc_url( add_query_arg( 'tab', 'gsc', $settings_url ) ) . '">' . esc_html__( 'Connect property', '4wp-seo-helper' ) . '</a>';
			echo '</p>';
		} elseif ( ! empty( $gsc['enabled'] ) ) {
			echo '<p class="forwp-seo-pane__empty">';
			esc_html_e( 'Run a Search Console sync to load period totals.', '4wp-seo-helper' );
			echo ' <a href="' . esc_url( $gsc_url ) . '">' . esc_html__( 'Open Search Console', '4wp-seo-helper' ) . '</a>';
			echo '</p>';
		}
		?>
		<ul class="forwp-seo-pane-stats forwp-seo-pane-stats--growth">
			<li>
				<span class="forwp-seo-pane-stats__label"><?php esc_html_e( 'Published (7 days)', '4wp-seo-helper' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( (int) ( $growth['week'] ?? 0 ) ) ); ?></strong>
				<?php echo self::delta_pill_html( (array) ( $growth['week_delta'] ?? [] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</li>
			<li>
				<span class="forwp-seo-pane-stats__label"><?php esc_html_e( 'Published (30 days)', '4wp-seo-helper' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( (int) ( $growth['month'] ?? 0 ) ) ); ?></strong>
				<?php echo self::delta_pill_html( (array) ( $growth['month_delta'] ?? [] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</li>
		</ul>
		<?php if ( [] !== $types ) : ?>
			<h3 class="forwp-seo-pane__subtitle"><?php esc_html_e( 'By type', '4wp-seo-helper' ); ?></h3>
			<ul class="forwp-seo-pane-list forwp-seo-pane-list--compact">
				<?php foreach ( $types as $slug => $type ) : ?>
					<li class="forwp-seo-pane-list__item">
						<span class="forwp-seo-pane-list__label"><code><?php echo esc_html( (string) $slug ); ?></code></span>
						<span class="forwp-seo-pane-list__meta">
							<?php
							echo esc_html(
								sprintf(
									'%s · %s%%',
									number_format_i18n( (int) ( $type['count'] ?? 0 ) ),
									(string) (int) ( $type['avg_completeness'] ?? 0 )
								)
							);
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string, int> $missing Missing field counts.
	 */
	private static function render_gaps_pane( array $missing, string $gaps_url ): void {
		$field_labels = [
			'title'         => __( 'SEO title', '4wp-seo-helper' ),
			'description'   => __( 'Meta description', '4wp-seo-helper' ),
			'focus_keyword' => __( 'Focus keyphrases', '4wp-seo-helper' ),
			'og_image'      => __( 'OG image', '4wp-seo-helper' ),
		];
		?>
		<ul class="forwp-seo-gap-grid">
			<?php foreach ( $field_labels as $field => $label ) : ?>
				<?php $count = (int) ( $missing[ $field ] ?? 0 ); ?>
				<li class="forwp-seo-gap-card<?php echo $count > 0 ? ' is-warn' : ' is-ok'; ?>">
					<span class="forwp-seo-gap-card__value"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
					<span class="forwp-seo-gap-card__label"><?php echo esc_html( $label ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="forwp-seo-pane__footer">
			<a href="<?php echo esc_url( $gaps_url ); ?>"><?php esc_html_e( 'Open pages with gaps', '4wp-seo-helper' ); ?></a>
		</p>
		<?php
	}

	/**
	 * @param array{value?:int,pct?:float|null} $delta Delta payload.
	 */
	private static function render_metric_card( string $value, string $label, array $delta = [], string $modifier = '', string $href = '' ): void {
		$classes = 'forwp-seo-metric-card';
		if ( '' !== $modifier ) {
			$classes .= ' ' . $modifier;
		}
		$pill = self::delta_pill_html( $delta );
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<div class="forwp-seo-metric-card__top">
				<p class="forwp-seo-metric-card__value"><?php echo esc_html( $value ); ?></p>
				<?php echo $pill; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_html. ?>
			</div>
			<p class="forwp-seo-metric-card__label">
				<?php if ( '' !== $href ) : ?>
					<a href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $label ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * @param array{value?:int,pct?:float|null} $delta Delta payload.
	 */
	private static function delta_pill_html( array $delta ): string {
		$value = (int) ( $delta['value'] ?? 0 );
		$pct   = $delta['pct'] ?? null;
		if ( 0 === $value && ( null === $pct || '' === $pct ) ) {
			return '';
		}

		$tone = $value > 0 ? 'up' : ( $value < 0 ? 'down' : 'flat' );
		$text = is_numeric( $pct )
			? sprintf( '%+s%%', (string) $pct )
			: sprintf( '%+d', $value );

		return '<span class="forwp-seo-delta forwp-seo-delta--' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</span>';
	}
}
