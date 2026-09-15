<?php
/**
 * GSC data UI sections.
 */

namespace Forwp\SeoHelper\Gsc;

use Forwp\SeoHelper\Admin\Menu;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageRenderer {
	/** @var array<string, mixed> */
	private static array $cell_context = [];

	/**
	 * @param array<string, string> $extra_query
	 */
	public static function render_period_selector( string $tab, int $days, array $extra_query = [] ): void {
		$hidden = in_array( $tab, [ Admin::TAB_INSPECTION, Admin::TAB_SYNC ], true );
		?>
		<div
			id="forwp-seo-gsc-range-bar"
			class="forwp-seo-gsc-range-bar-wrap"
			<?php echo $hidden ? 'hidden' : ''; ?>
		>
			<form method="get" class="forwp-seo-gsc-range-bar">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::GSC_PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
				<?php foreach ( $extra_query as $key => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<?php endforeach; ?>
				<?php wp_nonce_field( ReportPeriod::RANGE_NONCE_ACTION, 'forwp_seo_gsc_range_nonce', false, true ); ?>
				<div class="forwp-seo-gsc-range-bar__row">
					<div class="forwp-seo-gsc-range-bar__field">
						<label class="forwp-seo-gsc-range-bar__label" for="forwp-seo-gsc-range">
							<?php esc_html_e( 'Date range', '4wp-seo-helper' ); ?>
						</label>
						<select id="forwp-seo-gsc-range" name="range" class="forwp-seo-gsc-range-bar__select forwp-seo-range-select">
							<?php foreach ( ReportPeriod::allowed_ranges() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $days, $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<p class="forwp-seo-gsc-range-bar__hint forwp-seo-admin-muted">
						<?php esc_html_e( 'Filters synced local data — 7, 28, and 90 day windows are stored together.', '4wp-seo-helper' ); ?>
					</p>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $overview
	 */
	public static function render_overview_tab( array $overview ): void {
		$current  = $overview['current'] ?? [];
		$change   = $overview['change'] ?? [];
		$period   = $overview['period'] ?? [];
		$daily    = $overview['daily'] ?? [];
		?>
		<div class="forwp-seo-intro-card">
			<h2 class="forwp-seo-intro-card__title"><?php esc_html_e( 'Overview', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-intro-card__text">
				<?php
				printf(
					/* translators: 1: range label, 2: start date, 3: end date */
					esc_html__( '%1$s: %2$s — %3$s (3-day API lag applied).', '4wp-seo-helper' ),
					esc_html( (string) ( $period['label'] ?? ReportPeriod::label() ) ),
					esc_html( (string) ( $period['current_start'] ?? '' ) ),
					esc_html( (string) ( $period['current_end'] ?? '' ) )
				);
				?>
			</p>
		</div>

		<div class="forwp-seo-gsc-stat-grid">
			<?php self::render_stat_card( __( 'Clicks', '4wp-seo-helper' ), (int) ( $current['clicks'] ?? 0 ), $change['clicks'] ?? [] ); ?>
			<?php self::render_stat_card( __( 'Impressions', '4wp-seo-helper' ), (int) ( $current['impressions'] ?? 0 ), $change['impressions'] ?? [] ); ?>
			<?php self::render_stat_card( __( 'Avg CTR', '4wp-seo-helper' ), round( (float) ( $current['ctr'] ?? 0 ) * 100, 2 ) . '%', [], false ); ?>
			<?php self::render_stat_card( __( 'Avg position', '4wp-seo-helper' ), round( (float) ( $current['position'] ?? 0 ), 1 ), [], false ); ?>
		</div>

		<div class="forwp-seo-panel">
			<h2><?php esc_html_e( 'Daily trend', '4wp-seo-helper' ); ?></h2>
			<?php if ( empty( $daily ) ) : ?>
				<p class="forwp-seo-admin-muted"><?php esc_html_e( 'No synced daily data yet. Run a sync from the Data sync tab.', '4wp-seo-helper' ); ?></p>
			<?php else : ?>
				<table class="forwp-seo-ref-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', '4wp-seo-helper' ); ?></th>
							<th><?php esc_html_e( 'Clicks', '4wp-seo-helper' ); ?></th>
							<th><?php esc_html_e( 'Impressions', '4wp-seo-helper' ); ?></th>
							<th><?php esc_html_e( 'CTR', '4wp-seo-helper' ); ?></th>
							<th><?php esc_html_e( 'Position', '4wp-seo-helper' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_reverse( $daily ) as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $row['metric_date'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['clicks'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['impressions'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ) ); ?>%</td>
								<td><?php echo esc_html( (string) round( (float) ( $row['position'] ?? 0 ), 1 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $cards
	 */
	public static function render_insights_tab( array $cards ): void {
		$period_label = (string) ( $cards['period_label'] ?? ReportPeriod::label() );
		$needs_sync   = ! empty( $cards['needs_sync'] );
		?>
		<div class="forwp-seo-intro-card">
			<h2 class="forwp-seo-intro-card__title"><?php esc_html_e( 'Insights', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-intro-card__text">
				<?php
				printf(
					/* translators: %s: date range label */
					esc_html__( 'Derived from synced Search Analytics for %s. Trending compares the current window vs the previous equal period.', '4wp-seo-helper' ),
					esc_html( $period_label )
				);
				?>
			</p>
		</div>
		<?php if ( $needs_sync ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'No synced breakdown rows for this date range yet. Run Sync once — all ranges (7 / 28 / 90 days) are loaded together.', '4wp-seo-helper' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="forwp-seo-gsc-insights-grid">
			<?php self::render_rank_table( __( 'Top content', '4wp-seo-helper' ), $cards['top_pages'] ?? [], 'page' ); ?>
			<?php self::render_trend_table( __( 'Trending content', '4wp-seo-helper' ), $cards['trending_pages'] ?? [] ); ?>
			<?php self::render_rank_table( __( 'Top queries', '4wp-seo-helper' ), $cards['top_queries'] ?? [], 'query', [
				'property'   => (string) ( $cards['property'] ?? '' ),
				'period_key' => (string) ( $cards['period_key'] ?? '' ),
			] ); ?>
			<?php self::render_trend_table( __( 'Trending queries', '4wp-seo-helper' ), $cards['trending_queries'] ?? [] ); ?>
			<?php self::render_rank_table( __( 'Top countries', '4wp-seo-helper' ), $cards['top_countries'] ?? [], 'country' ); ?>
			<?php self::render_traffic_sources( $cards['traffic_sources'] ?? [] ); ?>
			<?php self::render_brand_split( $cards['brand_split'] ?? [] ); ?>
		</div>
		<?php
	}

	/**
	 * @return array<string, string>
	 */
	public static function performance_dimensions(): array {
		return [
			'query'            => __( 'Queries', '4wp-seo-helper' ),
			'page'             => __( 'Pages', '4wp-seo-helper' ),
			'country'          => __( 'Countries', '4wp-seo-helper' ),
			'device'           => __( 'Devices', '4wp-seo-helper' ),
			'searchAppearance' => __( 'Search appearance', '4wp-seo-helper' ),
			'date'             => __( 'Days', '4wp-seo-helper' ),
		];
	}

	public static function resolve_performance_dimension( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return 'query';
		}

		$dimensions = self::performance_dimensions();
		if ( isset( $dimensions[ $raw ] ) ) {
			return $raw;
		}

		// sanitize_key() lowercases camelCase GSC dimensions (searchAppearance → searchappearance).
		foreach ( array_keys( $dimensions ) as $key ) {
			if ( strtolower( $key ) === strtolower( $raw ) ) {
				return $key;
			}
		}

		return 'query';
	}

	public static function render_performance_tab( string $property, string $dimension, int $days ): void {
		$dimensions = self::performance_dimensions();
		$dimension  = self::resolve_performance_dimension( $dimension );

		$repo = new Repository();
		[ $start, $end ] = ReportPeriod::ranges( $days );

		$page_url     = Admin::get_instance()->get_page_url( Admin::TAB_PERFORMANCE );
		$dim_base_url = remove_query_arg( [ 'orderby', 'order' ], $page_url );
		?>
		<div class="forwp-seo-intro-card">
			<h2 class="forwp-seo-intro-card__title"><?php esc_html_e( 'Performance', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-intro-card__text">
				<?php
				printf(
					/* translators: 1: range label, 2: start date, 3: end date */
					esc_html__( '%1$s: synced Search Analytics for %2$s — %3$s.', '4wp-seo-helper' ),
					esc_html( ReportPeriod::label( $days ) ),
					esc_html( $start ),
					esc_html( $end )
				);
				?>
			</p>
		</div>

		<div class="forwp-seo-gsc-dimension-tabs">
			<?php foreach ( $dimensions as $key => $label ) : ?>
				<a
					class="button <?php echo $key === $dimension ? 'button-primary' : 'button-secondary'; ?>"
					href="<?php echo esc_url( add_query_arg( 'dimension', rawurlencode( $key ), $dim_base_url ) ); ?>"
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>

		<div class="forwp-seo-panel">
			<?php if ( 'date' === $dimension ) : ?>
				<?php
				$sort_columns = [ 'metric_date', 'clicks', 'impressions', 'ctr', 'position' ];
				[ $orderby, $order ] = self::resolve_performance_sort( $sort_columns, 'metric_date', 'desc' );
				$rows               = $repo->get_daily_range( $property, 'web', $start, $end );
				$rows               = self::sort_metric_rows( $rows, $orderby, $order );
				self::render_metric_table(
					[
						[ 'key' => 'metric_date', 'label' => __( 'Date', '4wp-seo-helper' ) ],
						[ 'key' => 'clicks', 'label' => __( 'Clicks', '4wp-seo-helper' ) ],
						[ 'key' => 'impressions', 'label' => __( 'Impressions', '4wp-seo-helper' ) ],
						[ 'key' => 'ctr', 'label' => __( 'CTR', '4wp-seo-helper' ), 'format' => 'pct' ],
						[ 'key' => 'position', 'label' => __( 'Position', '4wp-seo-helper' ), 'format' => 'float' ],
					],
					$rows,
					$orderby,
					$order,
					[
						'page'      => Menu::GSC_PAGE_SLUG,
						'tab'       => Admin::TAB_PERFORMANCE,
						'range'     => $days,
						'dimension' => $dimension,
					]
				);
				?>
			<?php else : ?>
				<?php
				$sort_columns = [ 'dim_key', 'clicks', 'impressions', 'ctr', 'position' ];
				[ $orderby, $order ] = self::resolve_performance_sort( $sort_columns, 'clicks', 'desc' );
				$rows = $repo->get_facts( $property, 'web', $dimension, ReportPeriod::period_key_current( $days ), 25000 );
				if ( 'country' === $dimension && 'dim_key' === $orderby ) {
					$rows = self::sort_country_rows( $rows, $order );
				} else {
					$rows = self::sort_metric_rows( $rows, $orderby, $order );
				}
				$dim_column = [
					'key'   => 'dim_key',
					'label' => $dimensions[ $dimension ],
				];
				if ( 'country' === $dimension ) {
					$dim_column['format'] = 'country';
				}
				if ( 'query' === $dimension ) {
					$dim_column['format'] = 'query';
				}

				$cell_context = [];
				if ( 'query' === $dimension ) {
					$lookup = new QueryPageLookup();
					$cell_context['landing_pages'] = $lookup->top_pages_for_queries(
						$property,
						ReportPeriod::period_key_current( $days ),
						array_map(
							static function ( array $row ): string {
								return (string) ( $row['dim_key'] ?? '' );
							},
							$rows
						)
					);
				}

				self::render_metric_table(
					[
						$dim_column,
						[ 'key' => 'clicks', 'label' => __( 'Clicks', '4wp-seo-helper' ) ],
						[ 'key' => 'impressions', 'label' => __( 'Impressions', '4wp-seo-helper' ) ],
						[ 'key' => 'ctr', 'label' => __( 'CTR', '4wp-seo-helper' ), 'format' => 'pct' ],
						[ 'key' => 'position', 'label' => __( 'Position', '4wp-seo-helper' ), 'format' => 'float' ],
					],
					$rows,
					$orderby,
					$order,
					[
						'page'      => Menu::GSC_PAGE_SLUG,
						'tab'       => Admin::TAB_PERFORMANCE,
						'range'     => $days,
						'dimension' => $dimension,
					],
					$cell_context
				);
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param list<array<string,mixed>> $logs
	 */
	public static function render_sync_tab( string $property, bool $sync_running, array $logs, ?string $last_sync ): void {
		$brand_terms  = (string) get_option( 'forwp_seo_gsc_brand_terms', '' );
		$cron_enabled = Module::get_instance()->is_cron_enabled();
		$next_cron    = wp_next_scheduled( Sync::CRON_HOOK );
		?>
		<div class="forwp-seo-intro-card">
			<h2 class="forwp-seo-intro-card__title"><?php esc_html_e( 'Data sync', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-intro-card__text">
				<?php esc_html_e( 'Pull Search Analytics into WordPress for Overview, Insights, and Performance. Manual sync runs in the background via WP-Cron.', '4wp-seo-helper' ); ?>
			</p>
		</div>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag after redirect.
		if ( isset( $_GET['sync_started'] ) && '1' === (string) wp_unslash( $_GET['sync_started'] ) ) :
			?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Sync started in the background. Status updates below — you can leave this page.', '4wp-seo-helper' ); ?></p></div>
		<?php endif; ?>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag after redirect.
		if ( ! empty( $_GET['sync_error'] ) ) :
			?>
			<div class="notice notice-error inline"><p><?php echo esc_html( sanitize_text_field( wp_unslash( (string) $_GET['sync_error'] ) ) ); ?></p></div>
		<?php endif; ?>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag after redirect.
		if ( isset( $_GET['brand_saved'] ) && '1' === (string) wp_unslash( $_GET['brand_saved'] ) ) :
			?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Brand terms saved.', '4wp-seo-helper' ); ?></p></div>
		<?php endif; ?>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag after redirect.
		if ( isset( $_GET['cron_saved'] ) && '1' === (string) wp_unslash( $_GET['cron_saved'] ) ) :
			?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Scheduled sync settings saved.', '4wp-seo-helper' ); ?></p></div>
		<?php endif; ?>

		<div class="forwp-seo-panel" id="forwp-seo-gsc-sync-status" data-running="<?php echo $sync_running ? '1' : '0'; ?>">
			<h2><?php esc_html_e( 'Manual sync', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-admin-muted">
				<?php esc_html_e( 'Sync loads all report ranges (7, 28, and 90 days) into local tables at once. Use the date range on Overview, Insights, or Performance to view them.', '4wp-seo-helper' ); ?>
			</p>
			<?php if ( $sync_running ) : ?>
				<p class="forwp-seo-admin-muted">
					<span class="forwp-seo-badge forwp-seo-badge--warn"><?php esc_html_e( 'Running', '4wp-seo-helper' ); ?></span>
					<?php esc_html_e( 'Sync in progress — this page refreshes every 15 seconds until it finishes.', '4wp-seo-helper' ); ?>
				</p>
			<?php endif; ?>
			<p class="forwp-seo-admin-muted">
				<?php
				if ( $last_sync ) {
					printf(
						/* translators: %s: datetime */
						esc_html__( 'Last successful sync: %s (UTC)', '4wp-seo-helper' ),
						esc_html( $last_sync )
					);
				} else {
					esc_html_e( 'No successful sync yet.', '4wp-seo-helper' );
				}
				?>
			</p>
			<form method="post" class="forwp-seo-inline-actions">
				<?php wp_nonce_field( 'forwp_seo_gsc_sync', 'forwp_seo_gsc_sync_nonce' ); ?>
				<input type="hidden" name="forwp_seo_gsc_run_sync" value="1" />
				<?php
				submit_button(
					__( 'Sync now', '4wp-seo-helper' ),
					'primary',
					'submit',
					false,
					$sync_running ? [ 'disabled' => 'disabled' ] : []
				);
				?>
			</form>
		</div>

		<div class="forwp-seo-panel">
			<h2><?php esc_html_e( 'Scheduled sync (WP-Cron)', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-admin-muted">
				<?php esc_html_e( 'Off by default. Enable to pull Search Analytics once per day automatically.', '4wp-seo-helper' ); ?>
			</p>
			<?php if ( $cron_enabled && $next_cron ) : ?>
				<p class="forwp-seo-admin-muted">
					<?php
					printf(
						/* translators: %s: datetime in site timezone */
						esc_html__( 'Next scheduled run: %s', '4wp-seo-helper' ),
						esc_html( wp_date( 'Y-m-d H:i', $next_cron ) )
					);
					?>
				</p>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'forwp_seo_gsc_sync', 'forwp_seo_gsc_sync_nonce' ); ?>
				<label>
					<input type="checkbox" name="forwp_seo_gsc_cron_enabled" value="1" <?php checked( $cron_enabled ); ?> />
					<?php esc_html_e( 'Enable daily automatic sync', '4wp-seo-helper' ); ?>
				</label>
				<div class="forwp-seo-form-actions">
					<?php submit_button( __( 'Save schedule', '4wp-seo-helper' ), 'secondary', 'forwp_seo_gsc_save_cron', false ); ?>
				</div>
			</form>
		</div>

		<div class="forwp-seo-panel">
			<h2><?php esc_html_e( 'Brand terms (insights)', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-admin-muted"><?php esc_html_e( 'One term per line — used to estimate branded vs non-branded query traffic.', '4wp-seo-helper' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'forwp_seo_gsc_sync', 'forwp_seo_gsc_sync_nonce' ); ?>
				<textarea class="large-text code" rows="4" name="forwp_seo_gsc_brand_terms"><?php echo esc_textarea( $brand_terms ); ?></textarea>
				<div class="forwp-seo-form-actions">
					<?php submit_button( __( 'Save brand terms', '4wp-seo-helper' ), 'secondary', 'forwp_seo_gsc_save_brand_terms', false ); ?>
				</div>
			</form>
		</div>

		<div class="forwp-seo-panel">
			<h2><?php esc_html_e( 'Recent sync jobs', '4wp-seo-helper' ); ?></h2>
			<?php if ( empty( $logs ) ) : ?>
				<p class="forwp-seo-admin-muted"><?php esc_html_e( 'No sync history yet.', '4wp-seo-helper' ); ?></p>
			<?php else : ?>
				<table class="forwp-seo-ref-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Job', '4wp-seo-helper' ); ?></th>
							<th><?php esc_html_e( 'Status', '4wp-seo-helper' ); ?></th>
							<th><?php esc_html_e( 'Rows', '4wp-seo-helper' ); ?></th>
							<th><?php esc_html_e( 'Finished', '4wp-seo-helper' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $log['job_key'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $log['status'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $log['rows_count'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $log['finished_at'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array{daily:int,facts:int,sync_log:int} $storage
	 */
	public static function render_clear_data_panel(
		bool $sync_running,
		array $storage,
		string $nonce_action = 'forwp_seo_gsc_sync',
		string $nonce_field = 'forwp_seo_gsc_sync_nonce'
	): void {
		$total_rows = (int) $storage['daily'] + (int) $storage['facts'] + (int) ( $storage['query_page'] ?? 0 ) + (int) $storage['sync_log'];
		?>
		<div class="forwp-seo-panel forwp-seo-panel--danger">
			<h2><?php esc_html_e( 'Clear synced data', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-admin-muted">
				<?php esc_html_e( 'Removes all rows from local GSC tables: daily metrics, dimension breakdowns, query ↔ page links, and sync history. Connection settings and brand terms are kept.', '4wp-seo-helper' ); ?>
			</p>
			<ul class="forwp-seo-gsc-storage-stats">
				<li>
					<?php
					printf(
						/* translators: %d: row count */
						esc_html__( 'Daily rows: %d', '4wp-seo-helper' ),
						(int) $storage['daily']
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %d: row count */
						esc_html__( 'Breakdown rows: %d', '4wp-seo-helper' ),
						(int) $storage['facts']
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %d: row count */
						esc_html__( 'Query ↔ page rows: %d', '4wp-seo-helper' ),
						(int) ( $storage['query_page'] ?? 0 )
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %d: row count */
						esc_html__( 'Sync log rows: %d', '4wp-seo-helper' ),
						(int) $storage['sync_log']
					);
					?>
				</li>
			</ul>
			<form method="post">
				<?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
				<label class="forwp-seo-gsc-clear-confirm">
					<input type="checkbox" name="forwp_seo_gsc_clear_confirm" value="1" <?php disabled( $sync_running || 0 === $total_rows ); ?> />
					<?php esc_html_e( 'I understand this permanently deletes all synced Search Console data from this site.', '4wp-seo-helper' ); ?>
				</label>
				<div class="forwp-seo-form-actions">
					<?php
					submit_button(
						__( 'Clear all synced data', '4wp-seo-helper' ),
						'delete',
						'forwp_seo_gsc_clear_data',
						false,
						( $sync_running || 0 === $total_rows ) ? [ 'disabled' => 'disabled' ] : []
					);
					?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array{value?:int,pct?:float|null} $change
	 */
	private static function render_stat_card( string $label, int|float|string $value, array $change, bool $show_change = true ): void {
		?>
		<article class="forwp-seo-gsc-stat-card">
			<h3><?php echo esc_html( $label ); ?></h3>
			<p class="forwp-seo-gsc-stat-card__value"><?php echo esc_html( (string) $value ); ?></p>
			<?php if ( $show_change && isset( $change['value'] ) ) : ?>
				<p class="forwp-seo-gsc-stat-card__meta">
					<?php
					$pct = $change['pct'] ?? null;
					if ( null === $pct ) {
						echo esc_html( sprintf( '%+d vs prev.', (int) $change['value'] ) );
					} else {
						echo esc_html( sprintf( '%+d (%s%%) vs prev.', (int) $change['value'], (string) $pct ) );
					}
					?>
				</p>
			<?php endif; ?>
		</article>
		<?php
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @param array<string, mixed> $context
	 */
	private static function render_rank_table( string $title, array $rows, string $type, array $context = [] ): void {
		$landing_pages = [];
		if ( 'query' === $type && ! empty( $context['property'] ) && ! empty( $context['period_key'] ) ) {
			$lookup        = new QueryPageLookup();
			$landing_pages = $lookup->top_pages_for_queries(
				(string) $context['property'],
				(string) $context['period_key'],
				array_map(
					static function ( array $row ): string {
						return (string) ( $row['dim_key'] ?? '' );
					},
					$rows
				)
			);
		}

		self::$cell_context = [ 'landing_pages' => $landing_pages ];
		?>
		<div class="forwp-seo-panel forwp-seo-panel--nested">
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p class="forwp-seo-admin-muted"><?php esc_html_e( 'No data — run sync first.', '4wp-seo-helper' ); ?></p>
			<?php else : ?>
				<table class="forwp-seo-ref-table">
					<thead><tr><th><?php esc_html_e( 'Item', '4wp-seo-helper' ); ?></th><th><?php esc_html_e( 'Clicks', '4wp-seo-helper' ); ?></th><th><?php esc_html_e( 'Impr.', '4wp-seo-helper' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php self::render_dim_cell( (string) ( $row['dim_key'] ?? '' ), $type ); ?></td>
								<td><?php echo esc_html( (string) ( $row['clicks'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['impressions'] ?? 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		self::$cell_context = [];
	}

	/**
	 * @param array{up?:list<array<string,mixed>>,down?:list<array<string,mixed>>} $trending
	 */
	private static function render_trend_table( string $title, array $trending ): void {
		?>
		<div class="forwp-seo-panel forwp-seo-panel--nested">
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php if ( empty( $trending['up'] ) && empty( $trending['down'] ) ) : ?>
				<p class="forwp-seo-admin-muted"><?php esc_html_e( 'No trending data yet.', '4wp-seo-helper' ); ?></p>
			<?php else : ?>
				<h3><?php esc_html_e( 'Trending up', '4wp-seo-helper' ); ?></h3>
				<?php self::render_change_rows( $trending['up'] ?? [] ); ?>
				<h3><?php esc_html_e( 'Trending down', '4wp-seo-helper' ); ?></h3>
				<?php self::render_change_rows( $trending['down'] ?? [] ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 */
	private static function render_change_rows( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p class="forwp-seo-admin-muted">' . esc_html__( 'None', '4wp-seo-helper' ) . '</p>';
			return;
		}
		echo '<ul class="forwp-seo-gsc-change-list">';
		foreach ( $rows as $row ) {
			$pct = $row['change_pct'] ?? null;
			$label = null === $pct
				? sprintf( '%+d', (int) ( $row['change'] ?? 0 ) )
				: sprintf( '%+d (%s%%)', (int) ( $row['change'] ?? 0 ), (string) $pct );
			printf(
				'<li><span>%s</span> <strong>%s</strong></li>',
				esc_html( mb_strimwidth( (string) ( $row['dim_key'] ?? '' ), 0, 70, '…' ) ),
				esc_html( $label )
			);
		}
		echo '</ul>';
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 */
	private static function render_traffic_sources( array $rows ): void {
		?>
		<div class="forwp-seo-panel forwp-seo-panel--nested">
			<h2><?php esc_html_e( 'Traffic by source type', '4wp-seo-helper' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p class="forwp-seo-admin-muted"><?php esc_html_e( 'No multi-type data yet.', '4wp-seo-helper' ); ?></p>
			<?php else : ?>
				<table class="forwp-seo-ref-table">
					<thead><tr><th><?php esc_html_e( 'Type', '4wp-seo-helper' ); ?></th><th><?php esc_html_e( 'Clicks', '4wp-seo-helper' ); ?></th><th><?php esc_html_e( 'Impr.', '4wp-seo-helper' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $row['search_type'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['clicks'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['impressions'] ?? 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $split
	 */
	private static function render_brand_split( array $split ): void {
		$brand = $split['branded'] ?? [ 'clicks' => 0, 'impressions' => 0 ];
		$other = $split['non_branded'] ?? [ 'clicks' => 0, 'impressions' => 0 ];
		?>
		<div class="forwp-seo-panel forwp-seo-panel--nested forwp-seo-gsc-insights-grid__full">
			<h2><?php esc_html_e( 'Branded vs non-branded (estimate)', '4wp-seo-helper' ); ?></h2>
			<table class="forwp-seo-ref-table">
				<tbody>
					<tr><th><?php esc_html_e( 'Branded clicks', '4wp-seo-helper' ); ?></th><td><?php echo esc_html( (string) ( $brand['clicks'] ?? 0 ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Non-branded clicks', '4wp-seo-helper' ); ?></th><td><?php echo esc_html( (string) ( $other['clicks'] ?? 0 ) ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param list<array{key:string,label:string,format?:string}> $columns
	 * @param list<array<string,mixed>> $rows
	 * @param array<string, scalar> $base_query
	 * @param array<string, mixed> $context
	 */
	private static function render_metric_table(
		array $columns,
		array $rows,
		string $orderby,
		string $order,
		array $base_query,
		array $context = []
	): void {
		self::$cell_context = $context;
		if ( empty( $rows ) ) {
			echo '<p class="forwp-seo-admin-muted">' . esc_html__( 'No rows for this dimension. Run sync first.', '4wp-seo-helper' ) . '</p>';
			self::$cell_context = [];
			return;
		}
		?>
		<table class="forwp-seo-ref-table forwp-seo-gsc-metric-table">
			<thead>
				<tr>
					<?php foreach ( $columns as $col ) : ?>
						<?php
						$key        = (string) $col['key'];
						$is_sorted  = $orderby === $key;
						$next_order = self::next_sort_order( $key, $orderby, $order );
						$url        = add_query_arg(
							array_merge(
								$base_query,
								[
									'orderby' => $key,
									'order'   => $next_order,
								]
							),
							admin_url( 'admin.php' )
						);
						?>
						<th scope="col" class="forwp-seo-gsc-sortable<?php echo $is_sorted ? ' is-active is-' . esc_attr( $order ) : ''; ?>">
							<a href="<?php echo esc_url( $url ); ?>">
								<?php echo esc_html( $col['label'] ); ?>
								<?php if ( $is_sorted ) : ?>
									<span class="forwp-seo-gsc-sortable__indicator" aria-hidden="true"><?php echo 'asc' === $order ? '↑' : '↓'; ?></span>
								<?php endif; ?>
							</a>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<?php foreach ( $columns as $col ) : ?>
							<td><?php self::render_cell( $row, $col ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::$cell_context = [];
	}

	/**
	 * @param list<string> $allowed
	 * @return array{0:string,1:string}
	 */
	private static function resolve_performance_sort( array $allowed, string $default_key, string $default_order ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only table sort.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : $default_key;
		$order   = isset( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( (string) $_GET['order'] ) ) ) : $default_order;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $orderby, $allowed, true ) ) {
			$orderby = $default_key;
		}

		if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) {
			$order = $default_order;
		}

		return [ $orderby, $order ];
	}

	private static function next_sort_order( string $column_key, string $current_orderby, string $current_order ): string {
		if ( $current_orderby !== $column_key ) {
			return in_array( $column_key, [ 'dim_key', 'metric_date' ], true ) ? 'asc' : 'desc';
		}

		return 'asc' === $current_order ? 'desc' : 'asc';
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return list<array<string,mixed>>
	 */
	private static function sort_metric_rows( array $rows, string $orderby, string $order ): array {
		usort(
			$rows,
			static function ( array $left, array $right ) use ( $orderby, $order ): int {
				$left_value  = $left[ $orderby ] ?? '';
				$right_value = $right[ $orderby ] ?? '';

				if ( in_array( $orderby, [ 'clicks', 'impressions', 'ctr', 'position' ], true ) ) {
					$cmp = (float) $left_value <=> (float) $right_value;
				} else {
					$cmp = strcasecmp( (string) $left_value, (string) $right_value );
				}

				return 'asc' === $order ? $cmp : -$cmp;
			}
		);

		return $rows;
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return list<array<string,mixed>>
	 */
	private static function sort_country_rows( array $rows, string $order ): array {
		usort(
			$rows,
			static function ( array $left, array $right ) use ( $order ): int {
				$left_name  = CountryCatalog::resolve( (string) ( $left['dim_key'] ?? '' ) )['name'];
				$right_name = CountryCatalog::resolve( (string) ( $right['dim_key'] ?? '' ) )['name'];
				$cmp        = strcasecmp( $left_name, $right_name );

				return 'asc' === $order ? $cmp : -$cmp;
			}
		);

		return $rows;
	}

	private static function render_dim_cell( string $value, string $type ): void {
		if ( 'country' === $type ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in CountryCatalog::render().
			echo CountryCatalog::render( $value );
			return;
		}

		if ( 'query' === $type ) {
			$landing = self::$cell_context['landing_pages'][ $value ] ?? null;
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in QueryCell::render().
			echo QueryCell::render( $value, is_array( $landing ) ? $landing : null );
			return;
		}

		echo esc_html( mb_strimwidth( $value, 0, 80, '…' ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array{key:string,format?:string} $col
	 */
	private static function render_cell( array $row, array $col ): void {
		if ( 'country' === ( $col['format'] ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in CountryCatalog::render().
			echo CountryCatalog::render( (string) ( $row[ $col['key'] ] ?? '' ) );
			return;
		}

		if ( 'query' === ( $col['format'] ?? '' ) ) {
			$query   = (string) ( $row[ $col['key'] ] ?? '' );
			$landing = self::$cell_context['landing_pages'][ $query ] ?? null;
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in QueryCell::render().
			echo QueryCell::render( $query, is_array( $landing ) ? $landing : null );
			return;
		}

		echo esc_html( self::format_cell( $row, $col ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array{key:string,format?:string} $col
	 */
	private static function format_cell( array $row, array $col ): string {
		$value = $row[ $col['key'] ] ?? '';
		if ( 'pct' === ( $col['format'] ?? '' ) ) {
			return (string) round( (float) $value * 100, 2 ) . '%';
		}
		if ( 'float' === ( $col['format'] ?? '' ) ) {
			return (string) round( (float) $value, 1 );
		}
		return (string) $value;
	}
}
