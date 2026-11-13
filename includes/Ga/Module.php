<?php
/**
 * Google Analytics 4: property selection, cached totals, metrics for 4WP Planner.
 *
 * Uses the Search Console OAuth connection (same Google account, extra scope analytics.readonly).
 * 4WP Planner reads values through `forwp_planner_metric_value`; without this plugin Planner stays manual.
 */

namespace Forwp\SeoHelper\Ga;

use Forwp\SeoHelper\Gsc\Admin as GscAdmin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Module {
	public const PAGE_SLUG            = 'forwp-seo-ga';
	public const OPTION_PROPERTY      = 'forwp_seo_ga_property';
	public const OPTION_PROPERTY_NAME = 'forwp_seo_ga_property_name';

	private const NONCE = 'forwp_seo_ga_save';

	/**
	 * Planner metric slug => GA4 metric API name.
	 */
	public const PLANNER_MAP = [
		'visitors'  => 'activeUsers',
		'sessions'  => 'sessions',
		'pageviews' => 'screenPageViews',
	];

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Schema::maybe_upgrade();
		Sync::boot();
		add_action( 'admin_init', [ $this, 'handle_post' ] );
		add_filter( 'forwp_planner_metric_value', [ $this, 'planner_value' ], 10, 4 );
		add_filter( 'forwp_planner_metrics', [ $this, 'planner_metrics' ] );
	}

	public function property_id(): string {
		return (string) get_option( self::OPTION_PROPERTY, '' );
	}

	public function is_ready(): bool {
		return '' !== $this->property_id() && GscAdmin::get_instance()->is_connected();
	}

	private function token(): string {
		return GscAdmin::get_instance()->get_access_token_for_sync();
	}

	/**
	 * Totals for a period, read from the database only. Never calls Google: data comes from Sync
	 * (cron or "Sync now"). Values not collected yet are listed in `pending` and queued for the next run.
	 *
	 * @param list<string> $metrics GA4 metric names.
	 * @return array{totals?: array<string, int>, pending?: list<string>, error?: string}
	 */
	public function totals( string $start, string $end, array $metrics = [ 'activeUsers', 'sessions', 'screenPageViews' ] ): array {
		if ( ! $this->is_ready() ) {
			return [ 'error' => 'Google Analytics is not connected.' ];
		}
		$today = wp_date( 'Y-m-d' );
		if ( $start > $today ) {
			return [ 'error' => 'Period has not started.' ];
		}
		$property = $this->property_id();
		$fresh    = Sync::fresh_date();
		$totals   = [];
		$pending  = [];

		$daily = array_values( array_intersect( $metrics, Sync::DAILY_METRICS ) );
		if ( $daily ) {
			$last   = min( $end, $today );
			$stored = Repository::stored_dates( $property, $start, $last, $daily );
			$days   = (int) floor( ( strtotime( $last ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1;
			if ( count( $stored ) < $days ) {
				Sync::want_period( $start, $end, [] ); // Missing days: next run backfills them.
			}
			foreach ( $daily as $metric ) {
				$totals[ $metric ] = Repository::sum_daily( $property, $start, $last, $metric );
			}
		}

		$exact = array_values( array_diff( $metrics, Sync::DAILY_METRICS ) );
		$queue = [];
		foreach ( $exact as $metric ) {
			$row = Repository::get_period( $property, $start, $end, $metric );
			if ( $row ) {
				$totals[ $metric ] = $row['value'];
			} else {
				$pending[] = $metric;
			}
			// Final when the period ended before the open window and was synced after that.
			$final = $row && $end < $fresh && substr( $row['synced_at'], 0, 10 ) > gmdate( 'Y-m-d', strtotime( $end . ' +' . Sync::SETTLE_DAYS . ' days' ) );
			if ( ! $final ) {
				$queue[] = $metric;
			}
		}
		if ( $queue ) {
			Sync::want_period( $start, $end, $queue );
		}

		return [
			'totals'  => $totals,
			'pending' => $pending,
		];
	}

	/**
	 * 4WP Planner: value of a metric for a plan period, or null to keep manual input.
	 *
	 * @param mixed  $value  Current value (null = none).
	 * @param string $metric Planner metric slug.
	 * @param string $start  Y-m-d.
	 * @param string $end    Y-m-d.
	 * @return mixed
	 */
	public function planner_value( $value, $metric, $start, $end ) {
		if ( null !== $value || ! isset( self::PLANNER_MAP[ $metric ] ) || ! $this->is_ready() ) {
			return $value;
		}
		$ga     = self::PLANNER_MAP[ $metric ];
		$result = $this->totals( (string) $start, (string) $end, [ $ga ] );
		return isset( $result['totals'][ $ga ] ) ? (int) $result['totals'][ $ga ] : $value;
	}

	/**
	 * Mark mapped Planner metrics as provided by this plugin.
	 *
	 * @param array<string, array<string, string>> $metrics Planner registry.
	 * @return array<string, array<string, string>>
	 */
	public function planner_metrics( $metrics ) {
		if ( ! is_array( $metrics ) || ! $this->is_ready() ) {
			return $metrics;
		}
		foreach ( array_keys( self::PLANNER_MAP ) as $slug ) {
			if ( isset( $metrics[ $slug ] ) ) {
				$metrics[ $slug ]['source']   = 'plugin';
				$metrics[ $slug ]['provider'] = '4wp-seo-helper';
			}
		}
		return $metrics;
	}

	/**
	 * Overview card: badge (live|warn) and one-line status.
	 *
	 * @return array{badge: string, meta: string}
	 */
	public function status(): array {
		if ( ! GscAdmin::get_instance()->is_connected() ) {
			return [
				'badge' => 'warn',
				'meta'  => __( 'Not connected — connect Google in the Search Console tab', '4wp-seo-helper' ),
			];
		}
		if ( '' === $this->property_id() ) {
			return [
				'badge' => 'warn',
				'meta'  => __( 'Choose a GA4 property in the Search Console tab', '4wp-seo-helper' ),
			];
		}
		$name   = (string) get_option( self::OPTION_PROPERTY_NAME, $this->property_id() );
		$totals = $this->totals( wp_date( 'Y-m-d', strtotime( '-7 days' ) ), wp_date( 'Y-m-d', strtotime( '-1 day' ) ), [ 'activeUsers' ] );
		if ( isset( $totals['error'] ) ) {
			return [
				'badge' => 'warn',
				'meta'  => $name . ' · ' . $totals['error'],
			];
		}
		$state = Sync::state();
		if ( ! empty( $state['last_error'] ) ) {
			return [
				'badge' => 'warn',
				'meta'  => $name . ' · ' . __( 'Last sync failed:', '4wp-seo-helper' ) . ' ' . $state['last_error'],
			];
		}
		if ( ! isset( $totals['totals']['activeUsers'] ) ) {
			return [
				'badge' => 'warn',
				'meta'  => $name . ' · ' . __( 'Collecting data — next sync will fill it', '4wp-seo-helper' ),
			];
		}
		return [
			'badge' => 'live',
			'meta'  => sprintf(
				/* translators: 1: property name, 2: users */
				__( '%1$s · %2$s users, last 7 days', '4wp-seo-helper' ),
				$name,
				number_format_i18n( (int) ( $totals['totals']['activeUsers'] ?? 0 ) )
			),
		];
	}

	public function handle_post(): void {
		if ( ! isset( $_POST['forwp_seo_ga_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['forwp_seo_ga_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		$id   = isset( $_POST['forwp_seo_ga_property'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( (string) $_POST['forwp_seo_ga_property'] ) ) ) : '';
		$name = '';
		foreach ( $this->properties()['properties'] ?? [] as $property ) {
			if ( $property['id'] === $id ) {
				$name = $property['account'] . ' / ' . $property['name'];
			}
		}
		update_option( self::OPTION_PROPERTY, (string) $id );
		update_option( self::OPTION_PROPERTY_NAME, $name );
		wp_safe_redirect( add_query_arg( 'forwp_seo_ga_saved', '1', GscAdmin::get_instance()->get_settings_url() ) );
		exit;
	}

	/**
	 * @return array{properties?: list<array{id: string, name: string, account: string}>, error?: string}
	 */
	private function properties(): array {
		$token = $this->token();
		if ( '' === $token ) {
			return [ 'error' => 'Connect Google in Search Console first.' ];
		}
		$cached = get_transient( 'forwp_seo_ga_properties' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$result = ( new Client() )->list_properties( $token );
		if ( ! isset( $result['error'] ) ) {
			set_transient( 'forwp_seo_ga_properties', $result, 10 * MINUTE_IN_SECONDS );
		}
		return $result;
	}

	/**
	 * GA data page: last 30 days by day + stored period totals. Database only; "Sync now" runs the collector.
	 */
	public function render_page(): void {
		global $wpdb;
		echo '<div class="wrap"><h1>' . esc_html__( 'Google Analytics', '4wp-seo-helper' ) . '</h1>';
		if ( ! $this->is_ready() ) {
			echo '<p>' . esc_html__( 'Not connected. Choose a GA4 property in 4WP SEO → Settings → Search Console.', '4wp-seo-helper' ) . '</p></div>';
			return;
		}
		$end    = wp_date( 'Y-m-d' );
		$start  = wp_date( 'Y-m-d', strtotime( '-29 days' ) );
		$state  = Sync::state();
		$next   = wp_next_scheduled( Sync::CRON_HOOK );

		echo '<p>' . esc_html( (string) get_option( self::OPTION_PROPERTY_NAME, $this->property_id() ) ) . ' · ' . esc_html( $start . ' → ' . $end ) . '</p>';
		$busy   = Sync::is_queued() || Sync::is_running();
		$error  = (string) ( $state['last_error'] ?? '' );
		echo '<div id="forwp-seo-ga-sync" class="notice inline ' . ( $busy ? 'notice-info' : ( '' !== $error ? 'notice-error' : '' ) ) . '"' . ( $busy || '' !== $error ? '' : ' hidden' ) . '><p>' .
			esc_html( $busy ? __( 'Sync is running in the background…', '4wp-seo-helper' ) : __( 'Last sync failed:', '4wp-seo-helper' ) . ' ' . $error ) . '</p></div>';
		echo '<p>' . esc_html__( 'Last successful sync (UTC):', '4wp-seo-helper' ) . ' <span id="forwp-seo-ga-last">' . esc_html( (string) ( $state['last_ok'] ?? '—' ) ) . '</span> · ';
		echo esc_html__( 'Next cron:', '4wp-seo-helper' ) . ' ' . esc_html( $next ? wp_date( 'Y-m-d H:i', $next ) : '—' ) . ' ';
		echo '<button type="button" class="button" id="forwp-seo-ga-sync-btn"' . disabled( $busy, true, false ) . '>' . esc_html__( 'Sync now', '4wp-seo-helper' ) . '</button></p>';
		echo '<p id="forwp-seo-ga-summary">' . esc_html( $this->summary_text() ) . '</p>';
		$this->print_sync_script( $busy );

		$daily = Schema::daily_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT metric_date, metric, value, synced_at FROM {$daily} WHERE property = %s AND metric_date BETWEEN %s AND %s ORDER BY metric_date DESC", $this->property_id(), $start, $end ) );
		$by   = [];
		foreach ( (array) $rows as $row ) {
			$by[ $row->metric_date ][ $row->metric ] = (int) $row->value;
			$by[ $row->metric_date ]['synced_at']    = $row->synced_at;
		}
		echo '<h2>' . esc_html__( 'By day (wp_forwp_ga_daily)', '4wp-seo-helper' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Date</th><th>Sessions</th><th>Pageviews</th><th>Synced (UTC)</th></tr></thead><tbody>';
		foreach ( $by as $date => $v ) {
			echo '<tr><td>' . esc_html( $date ) . '</td><td>' . esc_html( (string) ( $v['sessions'] ?? '—' ) ) . '</td><td>' . esc_html( (string) ( $v['screenPageViews'] ?? '—' ) ) . '</td><td>' . esc_html( (string) $v['synced_at'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$period = Schema::period_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prows = $wpdb->get_results( $wpdb->prepare( "SELECT period_start, period_end, metric, value, synced_at FROM {$period} WHERE property = %s ORDER BY period_start DESC, period_end DESC LIMIT 100", $this->property_id() ) );
		echo '<h2>' . esc_html__( 'Periods (wp_forwp_ga_period)', '4wp-seo-helper' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Period</th><th>Metric</th><th>Value</th><th>Synced (UTC)</th></tr></thead><tbody>';
		foreach ( (array) $prows as $row ) {
			echo '<tr><td>' . esc_html( $row->period_start . ' → ' . $row->period_end ) . '</td><td>' . esc_html( $row->metric ) . '</td><td>' . esc_html( (string) $row->value ) . '</td><td>' . esc_html( $row->synced_at ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * One line: users · sessions · pageviews for the last 30 days (database only).
	 */
	public function summary_text(): string {
		$end    = wp_date( 'Y-m-d' );
		$start  = wp_date( 'Y-m-d', strtotime( '-29 days' ) );
		$totals = $this->totals( $start, $end )['totals'] ?? [];
		$users  = isset( $totals['activeUsers'] ) ? number_format_i18n( (int) $totals['activeUsers'] ) : '—';
		return sprintf(
			/* translators: 1: users, 2: sessions, 3: pageviews */
			__( '30 days: %1$s users · %2$s sessions · %3$s pageviews', '4wp-seo-helper' ),
			$users,
			number_format_i18n( (int) ( $totals['sessions'] ?? 0 ) ),
			number_format_i18n( (int) ( $totals['screenPageViews'] ?? 0 ) )
		);
	}

	/**
	 * "Sync now" without reload: start → poll status every 5 s → update the status line and summary.
	 */
	private function print_sync_script( bool $busy ): void {
		$config = [
			'url'     => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( Sync::MANUAL_ACTION ),
			'start'   => Sync::MANUAL_ACTION,
			'status'  => Sync::STATUS_ACTION,
			'busy'    => $busy,
			'running' => __( 'Sync is running in the background…', '4wp-seo-helper' ),
			'done'    => __( 'Synced. Reload the page to refresh the table.', '4wp-seo-helper' ),
			'failed'  => __( 'Last sync failed:', '4wp-seo-helper' ),
		];
		?>
		<script>
		( function ( c ) {
			var btn = document.getElementById( 'forwp-seo-ga-sync-btn' );
			var box = document.getElementById( 'forwp-seo-ga-sync' );
			var timer = null;
			function show( cls, text ) {
				box.hidden = false;
				box.className = 'notice inline ' + cls;
				box.firstChild.textContent = text;
			}
			function call( action ) {
				var body = new FormData();
				body.append( 'action', action );
				body.append( 'nonce', c.nonce );
				return fetch( c.url, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
			}
			function apply( res ) {
				if ( ! res || ! res.success ) {
					show( 'notice-error', c.failed + ' ' + ( ( res && res.data && res.data.message ) || 'request error' ) );
					btn.disabled = false;
					return;
				}
				var d = res.data;
				document.getElementById( 'forwp-seo-ga-last' ).textContent = d.last_ok;
				document.getElementById( 'forwp-seo-ga-summary' ).textContent = d.summary;
				if ( d.busy ) {
					show( 'notice-info', c.running );
					btn.disabled = true;
					timer = setTimeout( poll, 5000 );
					return;
				}
				btn.disabled = false;
				if ( d.last_error ) {
					show( 'notice-error', c.failed + ' ' + d.last_error );
				} else {
					show( 'notice-success', c.done );
				}
			}
			function poll() {
				call( c.status ).then( apply ).catch( function () { timer = setTimeout( poll, 5000 ); } );
			}
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				show( 'notice-info', c.running );
				call( c.start ).then( apply ).catch( function () { btn.disabled = false; } );
			} );
			if ( c.busy ) {
				timer = setTimeout( poll, 5000 );
			}
		}( <?php echo wp_json_encode( $config ); ?> ) );
		</script>
		<?php
	}

	public function render_panel(): void {
		$connected = GscAdmin::get_instance()->is_connected();
		$current   = $this->property_id();
		?>
		<div class="forwp-seo-panel">
			<h2 class="forwp-seo-admin-section-title"><?php esc_html_e( 'Google Analytics (GA4)', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-admin-muted">
				<?php esc_html_e( 'Uses the same Google connection as Search Console (read-only Analytics access). Users, sessions and pageviews feed 4WP Planner goals (F1) automatically.', '4wp-seo-helper' ); ?>
			</p>
			<?php if ( ! $connected ) : ?>
				<p><?php esc_html_e( 'Connect Google above first.', '4wp-seo-helper' ); ?></p>
			<?php else : ?>
				<?php $list = $this->properties(); ?>
				<?php if ( isset( $list['error'] ) ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( $list['error'] ); ?></p>
					<p><?php esc_html_e( 'If you connected Google before Analytics support was added, disconnect and connect again to grant the Analytics scope.', '4wp-seo-helper' ); ?></p></div>
				<?php else : ?>
					<form method="post">
						<?php wp_nonce_field( self::NONCE, 'forwp_seo_ga_nonce' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="forwp-seo-ga-property"><?php esc_html_e( 'GA4 property', '4wp-seo-helper' ); ?></label></th>
								<td>
									<select id="forwp-seo-ga-property" name="forwp_seo_ga_property">
										<option value=""><?php esc_html_e( '— Not used —', '4wp-seo-helper' ); ?></option>
										<?php foreach ( $list['properties'] ?? [] as $property ) : ?>
											<option value="<?php echo esc_attr( $property['id'] ); ?>" <?php selected( $current, $property['id'] ); ?>><?php echo esc_html( $property['account'] . ' / ' . $property['name'] . ' (' . $property['id'] . ')' ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save GA4 property', '4wp-seo-helper' ), 'secondary' ); ?>
					</form>
					<?php if ( $this->is_ready() ) : ?>
						<?php
						$end    = wp_date( 'Y-m-d', strtotime( '-1 day' ) );
						$start  = wp_date( 'Y-m-d', strtotime( '-7 days' ) );
						$totals = $this->totals( $start, $end );
						?>
						<?php if ( isset( $totals['error'] ) ) : ?>
							<div class="notice notice-error inline"><p><?php echo esc_html( $totals['error'] ); ?></p></div>
						<?php else : ?>
							<p>
								<?php
								printf(
									/* translators: 1: start date, 2: end date, 3: users, 4: sessions, 5: pageviews */
									esc_html__( 'Last 7 days (%1$s – %2$s): %3$s users · %4$s sessions · %5$s pageviews.', '4wp-seo-helper' ),
									esc_html( $start ),
									esc_html( $end ),
									esc_html( isset( $totals['totals']['activeUsers'] ) ? number_format_i18n( $totals['totals']['activeUsers'] ) : '—' ),
									esc_html( number_format_i18n( $totals['totals']['sessions'] ?? 0 ) ),
									esc_html( number_format_i18n( $totals['totals']['screenPageViews'] ?? 0 ) )
								);
								?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
