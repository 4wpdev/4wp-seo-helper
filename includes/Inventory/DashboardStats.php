<?php
/**
 * Live + period-over-period figures for the Dashboard.
 */

namespace Forwp\SeoHelper\Inventory;

use Forwp\SeoHelper\Admin\Menu;
use Forwp\SeoHelper\Gsc\Admin as GscAdmin;
use Forwp\SeoHelper\Gsc\Insights;
use Forwp\SeoHelper\Gsc\Module as GscModule;
use Forwp\SeoHelper\Gsc\ReportPeriod;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DashboardStats {
	public const WEAK_SCORE      = 50;
	public const OPTION_SNAPSHOT = 'forwp_seo_helper_dashboard_snapshot';

	/**
	 * @return array<string, mixed>
	 */
	public static function collect(): array {
		$repository = new Repository();
		$inventory  = $repository->get_stats();
		$growth     = self::published_growth( $repository );
		$snapshot   = self::rotate_snapshot( $inventory );
		$gsc        = self::search_payload();
		$prev       = is_array( $snapshot['prev'] ?? null ) ? $snapshot['prev'] : null;

		return [
			'posts'     => (int) ( $inventory['posts'] ?? 0 ),
			'avg'       => (int) ( $inventory['avg_completeness'] ?? 0 ),
			'weak'      => (int) ( $inventory['weak_count'] ?? 0 ),
			'gaps'      => (int) ( $inventory['gap_count'] ?? 0 ),
			'missing'   => is_array( $inventory['missing_counts'] ?? null ) ? $inventory['missing_counts'] : [],
			'types'     => is_array( $inventory['by_post_type'] ?? null ) ? $inventory['by_post_type'] : [],
			'weakest'   => is_array( $inventory['weakest'] ?? null ) ? $inventory['weakest'] : [],
			'growth'    => $growth,
			'delta'     => [
				'posts' => null === $prev ? [ 'value' => 0, 'pct' => null ] : self::delta( (int) ( $inventory['posts'] ?? 0 ), (int) $prev['posts'] ),
				'weak'  => null === $prev ? [ 'value' => 0, 'pct' => null ] : self::delta( (int) ( $inventory['weak_count'] ?? 0 ), (int) $prev['weak'] ),
				'gaps'  => null === $prev ? [ 'value' => 0, 'pct' => null ] : self::delta( (int) ( $inventory['gap_count'] ?? 0 ), (int) $prev['gaps'] ),
				'avg'   => null === $prev ? [ 'value' => 0, 'pct' => null ] : self::delta( (int) ( $inventory['avg_completeness'] ?? 0 ), (int) $prev['avg'] ),
			],
			'snapshot'  => $snapshot,
			'gsc'       => $gsc,
			'weak_url'  => add_query_arg(
				[
					'page'      => Menu::INVENTORY_PAGE_SLUG,
					'view'      => 'inventory',
					'max_score' => (string) ( self::WEAK_SCORE - 1 ),
				],
				admin_url( 'admin.php' )
			),
			'gaps_url'  => add_query_arg(
				[
					'page'    => Menu::INVENTORY_PAGE_SLUG,
					'view'    => 'inventory',
					'missing' => 'any',
				],
				admin_url( 'admin.php' )
			),
			'inventory_url' => admin_url( 'admin.php?page=' . Menu::INVENTORY_PAGE_SLUG . '&view=inventory' ),
			'gsc_url'       => admin_url( 'admin.php?page=' . Menu::GSC_PAGE_SLUG ),
			'settings_url'  => admin_url( 'admin.php?page=' . Menu::SETTINGS_PAGE_SLUG ),
		];
	}

	/**
	 * @return array{week:int,prev_week:int,month:int,prev_month:int,week_delta:array{value:int,pct:?float},month_delta:array{value:int,pct:?float}}
	 */
	private static function published_growth( Repository $repository ): array {
		$now        = time();
		$week       = gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS );
		$prev_week  = gmdate( 'Y-m-d H:i:s', $now - 14 * DAY_IN_SECONDS );
		$month      = gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS );
		$prev_month = gmdate( 'Y-m-d H:i:s', $now - 60 * DAY_IN_SECONDS );
		$end        = gmdate( 'Y-m-d H:i:s', $now );

		$week_count      = $repository->count_published_between( $week, $end );
		$prev_week_count = $repository->count_published_between( $prev_week, $week );
		$month_count     = $repository->count_published_between( $month, $end );
		$prev_month_count = $repository->count_published_between( $prev_month, $month );

		return [
			'week'        => $week_count,
			'prev_week'   => $prev_week_count,
			'month'       => $month_count,
			'prev_month'  => $prev_month_count,
			'week_delta'  => self::delta( $week_count, $prev_week_count ),
			'month_delta' => self::delta( $month_count, $prev_month_count ),
		];
	}

	/**
	 * @param array<string, mixed> $inventory
	 * @return array{at:string,prev:?array{at:string,posts:int,weak:int,gaps:int,avg:int}}
	 */
	private static function rotate_snapshot( array $inventory ): array {
		$stored = get_option( self::OPTION_SNAPSHOT, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$current = [
			'at'    => gmdate( 'Y-m-d H:i:s' ),
			'posts' => (int) ( $inventory['posts'] ?? 0 ),
			'weak'  => (int) ( $inventory['weak_count'] ?? 0 ),
			'gaps'  => (int) ( $inventory['gap_count'] ?? 0 ),
			'avg'   => (int) ( $inventory['avg_completeness'] ?? 0 ),
		];

		$prev_at = (string) ( $stored['at'] ?? '' );
		$age     = '' !== $prev_at ? time() - (int) strtotime( $prev_at . ' UTC' ) : PHP_INT_MAX;
		$prev    = null;

		if ( isset( $stored['posts'] ) ) {
			$prev = [
				'at'    => $prev_at,
				'posts' => (int) $stored['posts'],
				'weak'  => (int) ( $stored['weak'] ?? 0 ),
				'gaps'  => (int) ( $stored['gaps'] ?? 0 ),
				'avg'   => (int) ( $stored['avg'] ?? 0 ),
			];
		}

		if ( $age >= 20 * HOUR_IN_SECONDS ) {
			update_option( self::OPTION_SNAPSHOT, $current, false );
		}

		return [
			'at'   => (string) ( $stored['at'] ?? $current['at'] ),
			'prev' => $prev,
		];
	}

	/**
	 * GSC overview + list panes for the dashboard (null when module off).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function search_payload(): ?array {
		if ( ! GscModule::get_instance()->is_enabled() ) {
			return [
				'enabled'     => false,
				'connected'   => false,
				'needs_sync'  => false,
				'clicks'      => 0,
				'impressions' => 0,
				'position'    => 0.0,
				'change'      => [],
				'label'       => '',
				'top_queries' => [],
				'top_pages'   => [],
			];
		}

		$admin    = GscAdmin::get_instance();
		$property = GscAdmin::get_site_property();
		$ready    = $admin->is_connected() && '' !== $property;

		if ( ! $ready ) {
			return [
				'enabled'     => true,
				'connected'   => false,
				'needs_sync'  => true,
				'clicks'      => 0,
				'impressions' => 0,
				'position'    => 0.0,
				'change'      => [],
				'label'       => '',
				'top_queries' => [],
				'top_pages'   => [],
			];
		}

		$days     = ReportPeriod::resolve_from_request();
		$insights = new Insights();
		$overview = $insights->build_overview( $property, $days );
		$cards    = $insights->build_cards( $property, $days );

		return [
			'enabled'     => true,
			'connected'   => true,
			'needs_sync'  => ! empty( $cards['needs_sync'] ),
			'clicks'      => (int) ( $overview['current']['clicks'] ?? 0 ),
			'impressions' => (int) ( $overview['current']['impressions'] ?? 0 ),
			'position'    => (float) ( $overview['current']['position'] ?? 0 ),
			'change'      => is_array( $overview['change'] ?? null ) ? $overview['change'] : [],
			'label'       => (string) ( $overview['period']['label'] ?? ( $cards['period_label'] ?? '' ) ),
			'top_queries' => array_slice( is_array( $cards['top_queries'] ?? null ) ? $cards['top_queries'] : [], 0, 8 ),
			'top_pages'   => array_slice( is_array( $cards['top_pages'] ?? null ) ? $cards['top_pages'] : [], 0, 8 ),
		];
	}

	/**
	 * @return array{value:int,pct:?float}
	 */
	public static function delta( int $current, int $previous ): array {
		$pct = null;
		if ( $previous > 0 ) {
			$pct = round( ( ( $current - $previous ) / $previous ) * 100, 1 );
		}

		return [
			'value' => $current - $previous,
			'pct'   => $pct,
		];
	}
}
