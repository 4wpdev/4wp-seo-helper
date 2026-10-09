<?php
/**
 * One-shot cron after the original is published. Same mechanism as scheduled posts.
 */

namespace Forwp\SeoHelper\CrossPosting;

use ForWP\Booking\Channels\Telegram_Channel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schedule {
	public const HOOK = 'forwp_seo_crossposting_due';

	public const PACKAGE_META = '_forwp_drive_package';

	public static function boot(): void {
		add_action( 'transition_post_status', [ self::class, 'on_status' ], 20, 3 );
		add_action( self::HOOK, [ self::class, 'send' ], 10, 1 );
	}

	/**
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function on_status( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || ! Module::get_instance()->is_enabled() ) {
			return;
		}

		$post_id = (int) $post->ID;
		if ( ! self::is_package( $post_id ) ) {
			return;
		}

		$args = [ $post_id ];
		$next = wp_next_scheduled( self::HOOK, $args );

		if ( 'publish' !== $new_status ) {
			if ( $next ) {
				wp_unschedule_event( $next, self::HOOK, $args );
			}
			return;
		}

		if ( 'publish' === $old_status || $next ) {
			return;
		}

		wp_schedule_single_event( time() + max( 1, self::delay_seconds( $post_id ) ), self::HOOK, $args );
	}

	/**
	 * @param int  $post_id          Post ID.
	 * @param bool $require_publish  Cron only notifies a live post. The editor button does not wait.
	 * @return true|\WP_Error
	 */
	public static function send( $post_id, $require_publish = true ) {
		$post_id = (int) $post_id;
		if ( ! Module::get_instance()->is_enabled() ) {
			return new \WP_Error( 'forwp_seo_crosspost_off', __( 'Cross posting is disabled.', '4wp-seo-helper' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ( $require_publish && 'publish' !== $post->post_status ) ) {
			return new \WP_Error( 'forwp_seo_crosspost_post', __( 'This post is not published.', '4wp-seo-helper' ) );
		}

		if ( ! class_exists( Telegram_Channel::class ) ) {
			update_post_meta( $post_id, '_forwp_seo_crosspost_notify_error', 'Telegram channel is not available.' );
			return new \WP_Error( 'forwp_seo_crosspost_telegram', __( 'Telegram channel is not available.', '4wp-seo-helper' ) );
		}

		$result = ( new Telegram_Channel() )->send_html( self::message( $post ) );
		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, '_forwp_seo_crosspost_notify_error', $result->get_error_message() );
			return $result;
		}

		delete_post_meta( $post_id, '_forwp_seo_crosspost_notify_error' );
		update_post_meta( $post_id, '_forwp_seo_crosspost_notified_at', gmdate( 'c' ) );

		return true;
	}

	/**
	 * @param int $post_id Post ID.
	 */
	private static function is_package( int $post_id ): bool {
		$link = get_post_meta( $post_id, self::PACKAGE_META, true );
		return is_array( $link ) && ! empty( $link['path'] );
	}

	/**
	 * Settings delay. Package republish.delay_hours / delay_minutes replace the matching part.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function delay_seconds( int $post_id ): int {
		$module  = Module::get_instance();
		$hours   = $module->delay_hours();
		$minutes = $module->delay_minutes();
		$package = apply_filters( 'forwp_drive_package', null, $post_id );

		if ( is_array( $package ) && isset( $package['republish'] ) && is_array( $package['republish'] ) ) {
			if ( isset( $package['republish']['delay_hours'] ) ) {
				$hours = max( 0, (int) $package['republish']['delay_hours'] );
			}
			if ( isset( $package['republish']['delay_minutes'] ) ) {
				$minutes = max( 0, (int) $package['republish']['delay_minutes'] );
			}
		}

		return ( $hours * HOUR_IN_SECONDS ) + ( $minutes * MINUTE_IN_SECONDS );
	}

	private static function message( \WP_Post $post ): string {
		$declared = [];
		$package  = apply_filters( 'forwp_drive_package', null, (int) $post->ID );
		if ( is_array( $package ) && isset( $package['platforms'] ) && is_array( $package['platforms'] ) ) {
			$declared = $package['platforms'];
		}

		$rows = [];
		foreach ( Platforms::all() as $platform ) {
			$key = (string) ( $platform['key'] ?? '' );
			$url = (string) ( $platform['url'] ?? '' );
			if ( '' === $key || '' === $url || ! in_array( $key, Platforms::ACTIVE_KEYS, true ) || ! isset( $declared[ $key ] ) || ! is_array( $declared[ $key ] ) ) {
				continue;
			}
			$entry  = $declared[ $key ];
			$status = (string) ( $entry['status'] ?? 'open' );
			$live   = (string) ( $entry['url'] ?? '' );
			$link   = '' !== $live ? $live : $url;
			$rows[] = [
				'label'  => (string) ( $platform['label'] ?? $key ),
				'status' => '' !== $status ? $status : 'open',
				'link'   => $link,
			];
		}

		$lines   = [];
		$lines[] = '<b>' . esc_html__( 'Share this material', '4wp-seo-helper' ) . '</b>';
		$lines[] = '<a href="' . esc_attr( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
		$lines[] = '';

		foreach ( $rows as $row ) {
			$lines[] = '<a href="' . esc_attr( $row['link'] ) . '">' . esc_html( $row['label'] ) . '</a> — ' . esc_html( $row['status'] );
		}

		return implode( "\n", $lines );
	}

}
