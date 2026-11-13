<?php
/**
 * GA4 data collection: the only place that calls the Analytics API.
 *
 * Runs by cron (twice a day) or by the "Sync now" button. Everything else (GA page, overview card,
 * 4WP Planner) reads stored rows only.
 *
 * - Daily rows (sessions, pageviews): a day is closed SETTLE_DAYS after it ends; a closed day synced
 *   after closing is final and never requested again. Open days are refreshed on every run.
 * - Period totals (activeUsers cannot be summed by day): readers queue the period they need,
 *   the next run fetches it. A period stays queued (refreshed) until it closes.
 * - On an API error the cron pauses for PAUSE_MINUTES; the button ignores the pause.
 * - The button never waits for Google: it queues a background run (single cron event) and returns.
 */

namespace Forwp\SeoHelper\Ga;

use Forwp\SeoHelper\Gsc\Admin as GscAdmin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sync {
	public const CRON_HOOK     = 'forwp_seo_ga_sync';
	public const RUN_HOOK      = 'forwp_seo_ga_sync_now';
	public const MANUAL_ACTION = 'forwp_seo_ga_sync';
	public const STATUS_ACTION = 'forwp_seo_ga_sync_status';
	public const OPTION_WANTED = 'forwp_seo_ga_wanted_periods';
	public const OPTION_STATE  = 'forwp_seo_ga_sync_state';

	/**
	 * Metrics stored per day.
	 */
	public const DAILY_METRICS = [ 'sessions', 'screenPageViews' ];

	/**
	 * GA4 keeps processing recent data; a day is closed this many days after it ends.
	 */
	public const SETTLE_DAYS = 2;

	private const PAUSE         = 'forwp_seo_ga_sync_pause';
	private const RUNNING       = 'forwp_seo_ga_sync_running';
	private const PAUSE_MINUTES = 30;
	private const BACKFILL_DAYS = 90;
	private const MAX_BACKFILL  = 900;
	private const MAX_WANTED    = 200;
	private const PERIODS_RUN   = 25;
	private const TIME_BUDGET   = 120; // Seconds per run; the rest waits for the next run.

	public static function boot(): void {
		add_action( self::CRON_HOOK, [ self::class, 'cron' ] );
		add_action( self::RUN_HOOK, [ self::class, 'run' ] );
		add_action( 'wp_ajax_' . self::MANUAL_ACTION, [ self::class, 'ajax_start' ] );
		add_action( 'wp_ajax_' . self::STATUS_ACTION, [ self::class, 'ajax_status' ] );
		add_action( 'admin_init', [ self::class, 'maybe_schedule' ] );
	}

	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) && Module::get_instance()->is_ready() ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * First day that is still open (today minus SETTLE_DAYS). Days before it are closed.
	 */
	public static function fresh_date(): string {
		return wp_date( 'Y-m-d', strtotime( '-' . self::SETTLE_DAYS . ' days' ) );
	}

	public static function cron(): void {
		if ( get_transient( self::PAUSE ) ) {
			return;
		}
		self::run();
	}

	/**
	 * AJAX "Sync now": queue a background run and answer at once (the page is not reloaded).
	 */
	public static function ajax_start(): void {
		self::ajax_guard();
		if ( ! self::is_queued() && ! self::is_running() ) {
			wp_schedule_single_event( time(), self::RUN_HOOK );
		}
		spawn_cron();
		wp_send_json_success( self::status_payload() );
	}

	/**
	 * AJAX poll: is the run finished, last result, fresh 30-day summary.
	 */
	public static function ajax_status(): void {
		self::ajax_guard();
		if ( self::is_queued() ) {
			spawn_cron(); // Nudge WP-Cron if the first spawn did not start it.
		}
		wp_send_json_success( self::status_payload() );
	}

	private static function ajax_guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		check_ajax_referer( self::MANUAL_ACTION, 'nonce' );
	}

	/**
	 * @return array{busy: bool, last_ok: string, last_error: string, summary: string}
	 */
	private static function status_payload(): array {
		$state = self::state();
		return [
			'busy'       => self::is_queued() || self::is_running(),
			'last_ok'    => (string) ( $state['last_ok'] ?? '—' ),
			'last_error' => (string) ( $state['last_error'] ?? '' ),
			'summary'    => Module::get_instance()->summary_text(),
		];
	}

	public static function is_queued(): bool {
		return false !== wp_next_scheduled( self::RUN_HOOK );
	}

	public static function is_running(): bool {
		return (bool) get_transient( self::RUNNING );
	}

	/**
	 * Queue a period total for the next run (called by readers when the stored value is missing or open).
	 *
	 * @param list<string> $metrics GA4 metric names.
	 */
	public static function want_period( string $start, string $end, array $metrics ): void {
		$wanted = get_option( self::OPTION_WANTED, [] );
		$wanted = is_array( $wanted ) ? $wanted : [];
		$key    = $start . '|' . $end;
		$have   = (array) ( $wanted[ $key ]['metrics'] ?? [] );
		$merged = array_values( array_unique( array_merge( $have, $metrics ) ) );
		if ( isset( $wanted[ $key ] ) && count( $merged ) === count( $have ) ) {
			return;
		}
		if ( ! isset( $wanted[ $key ] ) && count( $wanted ) >= self::MAX_WANTED ) {
			return;
		}
		$wanted[ $key ] = [
			'start'   => $start,
			'end'     => $end,
			'metrics' => $merged,
		];
		update_option( self::OPTION_WANTED, $wanted, false );
	}

	/**
	 * @return array{last_run?: string, last_ok?: string, last_error?: string}
	 */
	public static function state(): array {
		$state = get_option( self::OPTION_STATE, [] );
		return is_array( $state ) ? $state : [];
	}

	/**
	 * Collect data. Returns an error message or ''.
	 */
	public static function run(): string {
		$module = Module::get_instance();
		if ( ! $module->is_ready() ) {
			return self::fail( 'Google Analytics is not connected.', false );
		}
		if ( get_transient( self::RUNNING ) ) {
			return '';
		}
		set_transient( self::RUNNING, 1, 5 * MINUTE_IN_SECONDS );

		$token = GscAdmin::get_instance()->get_access_token_for_sync();
		$error = '' === $token ? 'Google token is not available.' : self::sync_daily( $token, $module->property_id() );
		if ( '' === $error ) {
			$error = self::sync_periods( $token, $module->property_id() );
		}

		delete_transient( self::RUNNING );
		if ( '' !== $error ) {
			return self::fail( $error, true );
		}

		delete_transient( self::PAUSE );
		$now = current_time( 'mysql', true );
		update_option(
			self::OPTION_STATE,
			[
				'last_run' => $now,
				'last_ok'  => $now,
			],
			false
		);
		return '';
	}

	private static function sync_daily( string $token, string $property ): string {
		$today = wp_date( 'Y-m-d' );
		$from  = self::backfill_start();
		$final = Repository::final_dates( $property, $from, $today, self::DAILY_METRICS, self::SETTLE_DAYS );
		$need  = [];
		for ( $d = $from; $d <= $today; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			if ( ! isset( $final[ $d ] ) ) {
				$need[] = $d;
			}
		}
		if ( ! $need ) {
			return '';
		}

		$result = ( new Client() )->daily( $token, $property, reset( $need ), $today, self::DAILY_METRICS );
		if ( isset( $result['error'] ) ) {
			return (string) $result['error'];
		}
		$days = (array) ( $result['days'] ?? [] );
		$save = [];
		foreach ( $need as $d ) {
			$save[ $d ] = $days[ $d ] ?? array_fill_keys( self::DAILY_METRICS, 0 ); // No traffic that day.
		}
		Repository::save_daily( $property, $save );
		return '';
	}

	private static function sync_periods( string $token, string $property ): string {
		$wanted = get_option( self::OPTION_WANTED, [] );
		$wanted = is_array( $wanted ) ? $wanted : [];
		$fresh  = self::fresh_date();
		$today  = wp_date( 'Y-m-d' );
		$done   = 0;
		$error  = '';
		$began  = time();

		foreach ( $wanted as $key => $item ) {
			if ( $done >= self::PERIODS_RUN || time() - $began > self::TIME_BUDGET ) {
				break;
			}
			$start   = (string) ( $item['start'] ?? '' );
			$end     = (string) ( $item['end'] ?? '' );
			$metrics = array_values( array_filter( (array) ( $item['metrics'] ?? [] ), 'is_string' ) );
			if ( '' === $start || '' === $end || ! $metrics || $start > $today ) {
				if ( $start <= $today ) {
					unset( $wanted[ $key ] );
				}
				continue;
			}
			$result = ( new Client() )->totals( $token, $property, $start, min( $end, $today ), $metrics );
			if ( isset( $result['error'] ) ) {
				// Long periods can take GA4 20 s+ to compute; keep it queued and go on with the rest.
				$error = '' === $error ? (string) $result['error'] : $error;
				++$done;
				continue;
			}
			foreach ( $metrics as $metric ) {
				Repository::save_period( $property, $start, $end, $metric, (int) ( $result['totals'][ $metric ] ?? 0 ) );
			}
			if ( $end < $fresh ) {
				unset( $wanted[ $key ] ); // Closed: the value just stored is final.
			}
			++$done;
		}

		update_option( self::OPTION_WANTED, $wanted, false );
		return $error;
	}

	/**
	 * Earliest day to keep complete: BACKFILL_DAYS back, or the earliest queued period (capped).
	 */
	private static function backfill_start(): string {
		$from   = wp_date( 'Y-m-d', strtotime( '-' . self::BACKFILL_DAYS . ' days' ) );
		$limit  = wp_date( 'Y-m-d', strtotime( '-' . self::MAX_BACKFILL . ' days' ) );
		$wanted = get_option( self::OPTION_WANTED, [] );
		foreach ( is_array( $wanted ) ? $wanted : [] as $item ) {
			$start = (string) ( $item['start'] ?? '' );
			if ( '' !== $start && $start < $from ) {
				$from = $start;
			}
		}
		return max( $from, $limit );
	}

	private static function fail( string $message, bool $pause ): string {
		if ( $pause ) {
			set_transient( self::PAUSE, 1, self::PAUSE_MINUTES * MINUTE_IN_SECONDS );
		}
		$state               = self::state();
		$state['last_run']   = current_time( 'mysql', true );
		$state['last_error'] = $message;
		update_option( self::OPTION_STATE, $state, false );
		return $message;
	}
}
