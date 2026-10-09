<?php
/**
 * Cross-post log stored on the post. Git meta.json is not the analytics source.
 */

namespace Forwp\SeoHelper\CrossPosting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Record {
	public const META = '_forwp_seo_crosspost_log';

	/**
	 * @return list<array{platform: string, url: string, slug: string, posted_at: string}>
	 */
	public static function read( int $post_id ): array {
		$log = get_post_meta( $post_id, self::META, true );
		if ( ! is_array( $log ) || [] === $log ) {
			self::backfill( $post_id );
			$log = get_post_meta( $post_id, self::META, true );
		}

		return self::normalize( $log );
	}

	public static function append( int $post_id, string $platform, string $url, string $slug, string $posted_at ): void {
		$entry = [
			'platform'  => $platform,
			'url'       => $url,
			'slug'      => $slug,
			'posted_at' => $posted_at,
		];
		$log   = self::normalize( get_post_meta( $post_id, self::META, true ) );
		$last  = $log[ count( $log ) - 1 ] ?? null;
		if ( is_array( $last ) && $last['platform'] === $platform && $last['url'] === $url ) {
			return;
		}
		$log[] = $entry;
		update_post_meta( $post_id, self::META, $log );
	}

	/**
	 * Copy already-posted platforms from the package once, when the post has no log yet.
	 */
	private static function backfill( int $post_id ): void {
		$package = apply_filters( 'forwp_drive_package', null, $post_id );
		if ( ! is_array( $package ) || empty( $package['platforms'] ) || ! is_array( $package['platforms'] ) ) {
			return;
		}

		foreach ( $package['platforms'] as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$url    = (string) ( $entry['url'] ?? '' );
			$status = (string) ( $entry['status'] ?? '' );
			if ( '' === $url && 'posted' !== $status ) {
				continue;
			}
			self::append(
				$post_id,
				(string) $key,
				$url,
				(string) ( $entry['slug'] ?? '' ),
				(string) ( $entry['posted_at'] ?? '' )
			);
		}
	}

	/**
	 * @param mixed $log Raw meta.
	 * @return list<array{platform: string, url: string, slug: string, posted_at: string}>
	 */
	private static function normalize( $log ): array {
		if ( ! is_array( $log ) ) {
			return [];
		}

		$out = [];
		foreach ( $log as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$platform = strtolower( sanitize_text_field( (string) ( $row['platform'] ?? '' ) ) );
			if ( '' === $platform ) {
				continue;
			}
			$out[] = [
				'platform'  => $platform,
				'url'       => esc_url_raw( (string) ( $row['url'] ?? '' ) ),
				'slug'      => sanitize_text_field( (string) ( $row['slug'] ?? '' ) ),
				'posted_at' => sanitize_text_field( (string) ( $row['posted_at'] ?? '' ) ),
			];
		}

		return $out;
	}
}
